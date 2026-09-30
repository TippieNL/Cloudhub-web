<?php
declare(strict_types=1);
namespace CloudHub\Services\Jobs;

use CloudHub\Helpers\Cache;
use CloudHub\Repositories\JobRepository;
use CloudHub\Services\AuditLog;
use RuntimeException;

/**
 * Takes jobs from the queue and runs them.
 *
 * tools/worker.php runs one of these in a loop; a web request may run one
 * after it has answered, where no command-line worker is running. Any number
 * may run at once, on one machine or several sharing the database: a job is
 * only ever claimed with a compare-and-set, and everything written afterwards
 * names the claim, so no two workers run the same job.
 *
 * Before running a job the worker asks the owner's account again -- it must
 * still exist, be enabled, and hold the role the job needs -- and whether the
 * server has gone read-only. While it runs, $_SESSION names the owner, so the
 * audit trail and the ledger attribute what it does to them.
 *
 * Every few seconds a worker takes back jobs whose worker has died or gone
 * quiet; once a minute it deletes finished jobs past their retention, with
 * their files, and clears work folders nothing refers to.
 */
final class Worker
{
    /** How often jobs of dead or silent workers are looked for. Two queries, so cheap. */
    public const RECOVERY_SECONDS = 10;

    /** How often expired jobs and unowned folders are cleared. */
    public const HOUSEKEEPING_SECONDS = 60;

    private bool $stop = false;
    private bool $started = false;
    private readonly string $id;

    /**
     * @param \Closure(string): void|null $log
     */
    public function __construct(
        private readonly JobEnvironment $env,
        private readonly JobTypes $types,
        private readonly WorkerRegistry $registry,
        string $kind = 'cli',
        private readonly ?\Closure $log = null,
        ?string $id = null,
    ) {
        $this->id = $id ?? WorkerRegistry::newId($kind);
    }

    public function id(): string { return $this->id; }

    /** Finish up and stop: a running job goes back to the queue at its next checkpoint. */
    public function requestStop(): void { $this->stop = true; }
    public function stopping(): bool { return $this->stop; }

    /** Say this worker is alive; cheap enough for every loop. */
    public function beat(): void { $this->registry->beat($this->id); }

    /** Leave, so this worker stops counting as running at once. */
    public function shutdown(): void { $this->registry->forget($this->id); }

    /**
     * Run jobs until the queue has none for this worker, $until passes, or
     * $maxJobs have been run.
     *
     * @return int how many jobs were run
     */
    public function runUntilIdle(?float $until = null, ?int $maxJobs = null): int
    {
        $ran = 0;
        while (!$this->stop) {
            if ($until !== null && microtime(true) >= $until) break;
            if ($maxJobs !== null && $ran >= $maxJobs) break;
            if ($this->runOnce() === null) break;
            $ran++;
        }
        return $ran;
    }

    /**
     * Claim one job and run it.
     *
     * @return array|null the job as it ended, or null when there was nothing to run
     */
    public function runOnce(): ?array
    {
        $this->beat();
        // A worker starting up is often a worker restarted after a crash: the
        // job the crash interrupted is looked for at once, not a minute later.
        $this->housekeeping(!$this->started);
        $this->started = true;
        if ($this->stop) return null;

        $jobs = $this->env->jobs();
        $skip = [];
        // A type that runs one at a time is not even claimed while one runs.
        foreach ($this->types->exclusive() as $name) {
            if ($jobs->exclusiveWinner($name) !== null) $skip[] = $name;
        }
        $job = $jobs->claimNext($this->id, $skip);
        if ($job === null) return null;
        return $this->execute($job) ?? ['id' => $job['id'], 'status' => 'pending'];
    }

    /**
     * Run a claimed job to its end.
     *
     * @return array|null the job as it ended; null when it was handed back
     */
    private function execute(array $job): ?array
    {
        $jobs = $this->env->jobs();
        $id = (string)$job['id'];
        $claim = (string)$job['claim_token'];
        $type = $this->types->get((string)$job['type']);

        if ($type === null) {
            $jobs->fail($id, $claim, 'This kind of task is not supported by this version of CloudHub');
            return $jobs->find($id);
        }
        // Two workers can claim two jobs of an exclusive type in the same
        // instant; exactly one is the winner, and the other job goes back.
        if ($type->exclusive() && $jobs->exclusiveWinner($type->name()) !== $id) {
            $jobs->release($id, $claim);
            return null;
        }

        $owner = $this->env->users()->get((int)$job['user_id']);
        $refusal = match (true) {
            $owner === null || !$owner['isActive'] => 'The account that queued this task no longer exists or has been disabled',
            $type->capability((array)$job['payload']) === 'write' && !in_array($owner['role'], ['editor', 'admin'], true)
                => 'The account that queued this task no longer has permission to make changes',
            $type->writesStore((array)$job['payload']) && (bool)$this->env->config['read_only'] => 'Server is in read-only mode',
            default => null,
        };
        if ($refusal !== null) {
            $jobs->fail($id, $claim, $refusal);
            $this->log('job '.$id.' ('.$type->name().') refused: '.$refusal);
            return $jobs->find($id);
        }

        // Whatever an earlier attempt staged is of no use now: a job that can
        // carry on keeps what it needs in its saved state.
        try { $this->env->clearStaging($id); } catch (\Throwable $e) { $this->log('job '.$id.': could not clear old staging: '.$e->getMessage()); }

        $session = $_SESSION ?? null;
        $_SESSION = ['user_id' => $owner['id'], 'username' => $owner['username'], 'role' => $owner['role']];
        $ctx = new JobContext($jobs, $job, $this->env, fn(): bool => $this->stop, fn() => $this->beat());
        $outcome = 'lost';
        $this->log('job '.$id.' ('.$type->name().') started, attempt '.$job['attempts']);
        try {
            try {
                $ctx->checkpoint(true);
                $result = $type->run($ctx);
                $outcome = $jobs->complete($id, $claim, $result, $ctx->snapshot()) ? 'completed' : 'lost';
                if ($outcome === 'completed') $this->tidy(fn() => $this->env->clearStaging($id), $id);
            } catch (JobCancelled $e) {
                $outcome = 'lost';
                if ($this->stillHeld($id, $claim, $ctx)) {
                    $this->tidy(fn() => $type->discard($id, $this->env), $id);
                    $outcome = $jobs->cancelled($id, $claim, $e->result ?: null, $ctx->snapshot()) ? 'cancelled' : 'lost';
                }
            } catch (JobInterrupted) {
                // Back to the queue as it stands; the next attempt carries on.
                $outcome = $jobs->release($id, $claim) ? 'released' : 'lost';
            } catch (JobLost) {
                // Another worker has the job now. Its record and its work
                // folder are that worker's; touching either could undo its work.
                $outcome = 'lost';
            } catch (\Throwable $e) {
                $message = self::publicMessage($e);
                if ($message !== $e->getMessage() || (int)$e->getCode() >= 500) {
                    $this->log('job '.$id.' ('.$type->name().') failed: '.get_class($e).': '.$e->getMessage());
                }
                $outcome = 'lost';
                if ($this->stillHeld($id, $claim, $ctx)) {
                    $this->tidy(fn() => $type->discard($id, $this->env), $id);
                    $outcome = $jobs->fail($id, $claim, $message, $e instanceof JobFailed ? $e->result : null, $ctx->snapshot()) ? 'failed' : 'lost';
                }
            }
            if ($outcome !== 'lost' && $outcome !== 'released') {
                AuditLog::write($this->env->db, 'job.'.$type->name(),
                    $outcome === 'completed' ? 'success' : ($outcome === 'cancelled' ? 'cancelled' : 'failure'),
                    ['id' => $id, 'label' => $job['label']]);
            }
        } finally {
            // Even a failed job may have placed some of its items.
            if ($type->writesStore((array)$job['payload'])) Cache::bumpGeneration();
            $_SESSION = $session;
            if ($session === null) unset($_SESSION);
        }
        $this->log('job '.$id.' ('.$type->name().') '.$outcome);
        return $outcome === 'released' ? null : $jobs->find($id);
    }

    /**
     * Whether this worker still holds the job, proven by a fenced write that
     * also holds the job for the cleanup to come.
     *
     * A job fails or is cancelled by deleting what it staged, and that must
     * never happen to a job another worker has taken over: its staging is the
     * new attempt's by then. So the claim is proven first -- an error can
     * surface long after the last checkpoint -- and the lease is pushed out,
     * so the job cannot be judged abandoned while its folder is being
     * cleared.
     */
    private function stillHeld(string $id, string $claim, JobContext $ctx): bool
    {
        [$done, $total, $unit] = $ctx->snapshot();
        $jobs = $this->env->jobs();
        return $jobs->heartbeat($id, $claim, $done, $total, $unit, null, $jobs->now() + 600) !== null;
    }

    /**
     * Take back jobs that nobody is running any more (every RECOVERY_SECONDS),
     * and forget expired jobs and clear work folders that belong to no job
     * (every HOUSEKEEPING_SECONDS) -- across every worker on this machine,
     * unless forced.
     *
     * @return array{recovered:list<string>,failed:list<string>,cancelled:list<string>,expired:int,orphans:int}|null null when neither was due
     */
    public function housekeeping(bool $force = false): ?array
    {
        $recover = $force || $this->due('recovery', self::RECOVERY_SECONDS);
        $tidy = $force || $this->due('housekeeping', self::HOUSEKEEPING_SECONDS);
        if (!$recover && !$tidy) return null;
        $out = ['recovered' => [], 'failed' => [], 'cancelled' => [], 'expired' => 0, 'orphans' => 0];
        if ($recover) $out = $this->recover() + $out;
        if (!$tidy) return $out;

        $config = $this->env->config;
        $jobs = $this->env->jobs();
        $retention = max(1, (int)($config['queue_retention_hours'] ?? 24)) * 3600;
        foreach ($jobs->expired($retention) as $old) {
            $this->tidy(fn() => $this->env->removeWorkDir($old['id']), $old['id']);
            $jobs->delete($old['id']);
            $out['expired']++;
        }
        $out['orphans'] = $this->sweepWorkFolders($jobs);
        $this->registry->prune();
        return $out;
    }

    /** Whether the periodic step $name is due, claiming it for this run if it is. */
    private function due(string $name, int $seconds): bool
    {
        $stamp = $this->env->projectDir.'/storage/.cache/queue/'.$name;
        clearstatcache(true, $stamp);
        $last = @filemtime($stamp);
        if ($last !== false && time() - $last < $seconds) return false;
        if (is_dir(dirname($stamp)) || @mkdir(dirname($stamp), 0775, true) || is_dir(dirname($stamp))) @touch($stamp);
        return true;
    }

    /**
     * Put back in the queue the jobs of workers that died on this machine,
     * and of any worker whose heartbeat has gone quiet.
     *
     * @return array{recovered:list<string>,failed:list<string>,cancelled:list<string>}
     */
    private function recover(): array
    {
        $config = $this->env->config;
        $jobs = $this->env->jobs();
        $attempts = max(1, (int)($config['queue_max_attempts'] ?? 3));

        $dead = [];
        $here = WorkerRegistry::host();
        foreach ($jobs->processingWorkers() as $row) {
            $worker = WorkerRegistry::parse($row['worker']);
            if ($worker === null || $worker['host'] !== $here || $row['worker'] === $this->id) continue;
            if ($this->registry->isFresh($row['worker'])) continue;
            if (WorkerRegistry::pidAlive($worker['pid']) === false) $dead[$row['worker']] = true;
        }
        $fromDead = $jobs->recoverFrom(array_keys($dead), $attempts);
        $fromStale = $jobs->recoverStale(max(30, (int)($config['queue_stale_seconds'] ?? 120)), $attempts);
        $out = [
            'recovered' => array_merge($fromDead['requeued'], $fromStale['requeued']),
            'failed' => array_merge($fromDead['failed'], $fromStale['failed']),
            'cancelled' => array_merge($fromDead['cancelled'], $fromStale['cancelled']),
        ];
        foreach (array_merge($out['failed'], $out['cancelled']) as $gone) {
            $job = $jobs->find($gone);
            $type = $job === null ? null : $this->types->get($job['type']);
            if ($type !== null) $this->tidy(fn() => $type->discard($gone, $this->env), $gone);
        }
        foreach ($out['recovered'] as $back) $this->log('job '.$back.' went back to the queue: its worker stopped');
        return $out;
    }

    /**
     * Remove work folders whose job is gone -- removed by its owner, or never
     * created because the request that meant to queue it failed -- and staging
     * left beside a finished job's output. A folder younger than five minutes
     * is left alone: a route moves items in before it inserts the job.
     */
    private function sweepWorkFolders(JobRepository $jobs): int
    {
        $root = $this->env->workRoot();
        if (!is_dir($root)) return 0;
        $ids = array_values(array_filter(scandir($root) ?: [], [JobRepository::class, 'validId']));
        $removed = 0;
        foreach (array_chunk($ids, 200) as $chunk) {
            $known = $jobs->statuses($chunk);
            foreach ($chunk as $id) {
                if (!isset($known[$id])) {
                    $at = @filemtime($root.'/'.$id);
                    if ($at !== false && time() - $at < 300) continue;
                    $this->tidy(fn() => $this->env->removeWorkDir($id), $id);
                    $removed++;
                } elseif (in_array($known[$id], JobRepository::FINISHED, true)) {
                    $this->tidy(fn() => $this->env->clearStaging($id), $id);
                }
            }
        }
        return $removed;
    }

    /** What a failed job tells its owner. Messages from CloudHub's own checks are theirs; anything else is logged, not shown. */
    public static function publicMessage(\Throwable $e): string
    {
        if ($e instanceof \PDOException) return 'A database error stopped this task; the details are in the server log';
        if ($e instanceof RuntimeException && $e->getMessage() !== '') return mb_substr($e->getMessage(), 0, 1000);
        return 'An internal error stopped this task; the details are in the server log';
    }

    /** Cleanup that must not turn one failure into another. */
    private function tidy(callable $work, string $id): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            $this->log('job '.$id.': cleanup failed: '.$e->getMessage());
        }
    }

    private function log(string $line): void
    {
        if ($this->log !== null) ($this->log)($line);
    }
}
