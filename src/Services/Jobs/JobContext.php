<?php
declare(strict_types=1);
namespace CloudHub\Services\Jobs;

use CloudHub\Repositories\JobRepository;

/**
 * A running job's line to the queue.
 *
 * A job type reports progress through this and calls checkpoint() often --
 * between files, between chunks of a large file. A checkpoint is cheap: it
 * reaches the database at most once per interval, and that one write is also
 * the heartbeat that tells everyone else the job is alive. It is where a job
 * learns that it should stop:
 *
 *   JobCancelled    its owner asked; it cleans up and ends as cancelled
 *   JobInterrupted  the worker is shutting down; the job goes back to the queue
 *   JobLost         another worker has taken the job over; stop and touch nothing
 *
 * so a job type needs no stopping logic of its own beyond calling it.
 */
final class JobContext
{
    private int $done;
    private int $total;
    private string $unit;
    private ?string $current;
    private float $lastBeat = 0.0;
    private ?array $state;

    /**
     * @param array $job the claimed row, claim_token included
     * @param \Closure(): bool $stopping whether the worker has been asked to stop
     * @param \Closure(): void|null $alive called with each heartbeat, to keep the worker's own liveness fresh
     */
    public function __construct(
        private readonly JobRepository $repo,
        private readonly array $job,
        private readonly JobEnvironment $env,
        private readonly \Closure $stopping,
        private readonly ?\Closure $alive = null,
        private readonly float $interval = 1.0,
    ) {
        $this->done = (int)$job['progress_done'];
        $this->total = (int)$job['progress_total'];
        $this->unit = (string)$job['progress_unit'];
        $this->current = $job['current_item'];
        $this->state = $job['state'];
    }

    public function id(): string { return (string)$this->job['id']; }
    public function owner(): int { return (int)$this->job['user_id']; }
    /** 1 on the first run; more when carrying on after an interruption. */
    public function attempt(): int { return (int)$this->job['attempts']; }
    public function payload(): array { return (array)$this->job['payload']; }
    public function env(): JobEnvironment { return $this->env; }

    /** This attempt's own staging folder; see JobEnvironment::stagingDir(). */
    public function staging(): string { return $this->env->stagingDir($this->id(), $this->attempt()); }

    /** Where to keep a file the owner downloads when the job is done. */
    public function output(): string { return $this->env->outputDir($this->id()); }

    /** What an earlier attempt saved with saveState(), or [] on a fresh start. */
    public function state(): array { return $this->state ?? []; }

    /**
     * Record how far the job has got, so an attempt after a crash can carry on.
     *
     * Written at once, not throttled: callers save state at the moments that
     * matter, such as just before and just after moving a finished item into
     * place.
     */
    public function saveState(array $state): void
    {
        $this->state = $state;
        if (!$this->repo->saveState($this->id(), (string)$this->job['claim_token'], $state)) throw new JobLost();
    }

    /** Set the amount of work in all, in items or in bytes. */
    public function total(int $total, string $unit = 'items'): void
    {
        $this->total = max(0, $total);
        $this->unit = $unit === 'bytes' ? 'bytes' : 'items';
    }

    /** Set how much is done, and optionally what is being worked on now. */
    public function progress(int $done, ?string $current = null): void
    {
        $this->done = max(0, $done);
        if ($current !== null) $this->current = $current;
    }

    public function advance(int $by = 1, ?string $current = null): void
    {
        $this->progress($this->done + $by, $current);
    }

    public function done(): int { return $this->done; }
    public function totalSoFar(): int { return $this->total; }

    /** @return array{0:int,1:int,2:string} done, total and unit, as the queue records them */
    public function snapshot(): array { return [$this->done, $this->total, $this->unit]; }

    /**
     * Report in, and stop if asked to. Cheap enough to call per file and per
     * chunk: it only reaches the database once per interval unless $force.
     */
    public function checkpoint(bool $force = false): void
    {
        if (($this->stopping)()) throw new JobInterrupted('The worker is stopping');
        if (!$force && microtime(true) - $this->lastBeat < $this->interval) return;
        $this->beat(null);
    }

    /**
     * About to do something that cannot report while it runs: hold the job
     * for that long, so nobody takes it for abandoned half way through.
     */
    public function extendLease(int $seconds): void
    {
        $this->beat($this->repo->now() + max(0, $seconds));
    }

    private function beat(?int $leaseUntil): void
    {
        $this->lastBeat = microtime(true);
        if ($this->alive) ($this->alive)();
        $cancel = $this->repo->heartbeat($this->id(), (string)$this->job['claim_token'],
            $this->done, $this->total, $this->unit, $this->current, $leaseUntil);
        if ($cancel === null) throw new JobLost('The job was taken over by another worker');
        if ($cancel) throw new JobCancelled('Cancelled');
    }
}
