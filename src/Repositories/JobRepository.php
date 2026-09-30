<?php
declare(strict_types=1);

namespace CloudHub\Repositories;

use PDO;
use RuntimeException;

/**
 * The background job queue.
 *
 * A job moves pending -> processing -> completed | failed | cancelled, and
 * every move is a compare-and-set on the row: an UPDATE whose WHERE names the
 * state it expects, and which counts as done only if it changed a row. Two
 * workers that pick the same pending job therefore cannot both claim it --
 * the second finds the status already moved -- with no lock the filesystem
 * or the database version has to support. The SQL is plain enough for the
 * SQLite the tests run against.
 *
 * A claim carries a random token. Everything a worker writes afterwards names
 * that token, so a worker that stalled long enough for its job to be handed to
 * another cannot overwrite the new owner's progress or verdict: its next write
 * changes nothing, and it learns it has lost the job.
 *
 * `revision` is bumped by every update. MySQL's rowCount() counts rows an
 * UPDATE changed, not rows it matched, so a heartbeat landing in the same
 * second as the last, with nothing else new, would otherwise look like a lost
 * claim.
 *
 * Times are UTC 'Y-m-d H:i:s' strings produced here, never by the database,
 * so both engines agree and a test can move the clock.
 */
final class JobRepository
{
    public const STATUSES = ['pending', 'processing', 'completed', 'failed', 'cancelled'];
    public const FINISHED = ['completed', 'failed', 'cancelled'];

    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(private readonly PDO $db, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /** Job ids are 32 lower-case hex characters; anything else names no job. */
    public static function validId(string $id): bool
    {
        return preg_match('/^[a-f0-9]{32}$/', $id) === 1;
    }

    public function now(): int
    {
        return ($this->clock)();
    }

    private function stamp(?int $at = null): string
    {
        return gmdate('Y-m-d H:i:s', $at ?? $this->now());
    }

    /**
     * A fresh job id, for a caller that has to prepare the job's folder
     * before queueing it.
     *
     * The first twelve hex digits are the time in milliseconds and the other
     * twenty are random, as in a UUIDv7: the queue orders by created_at and
     * then id, and created_at has whole seconds, so jobs queued within one
     * second would otherwise run in a random order.
     */
    public static function newId(): string
    {
        return sprintf('%012x', (int)(microtime(true) * 1000) & 0xFFFFFFFFFFFF).bin2hex(random_bytes(10));
    }

    /** Queue a job. $payload has already been validated by its type. */
    public function create(int $userId, string $type, string $label, string $target, array $payload, ?string $id = null): array
    {
        $id ??= self::newId();
        if (!self::validId($id)) throw new RuntimeException('Invalid job id', 500);
        $stmt = $this->db->prepare(
            "INSERT INTO jobs (id, user_id, type, status, label, target, payload, created_at, revision)
             VALUES (?, ?, ?, 'pending', ?, ?, ?, ?, 0)");
        $stmt->execute([$id, $userId, $type, mb_substr($label, 0, 255), mb_substr($target, 0, 1024),
            self::encode($payload), $this->stamp()]);
        return $this->find($id) ?? throw new RuntimeException('Unable to queue the job', 500);
    }

    public function find(string $id): ?array
    {
        if (!self::validId($id)) return null;
        $stmt = $this->db->prepare('SELECT * FROM jobs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::decode($row) : null;
    }

    /** One of this account's jobs; null for anyone else's, so a guessed id learns nothing. */
    public function findFor(string $id, int $userId): ?array
    {
        $job = $this->find($id);
        return $job !== null && $job['user_id'] === $userId ? $job : null;
    }

    /** @return list<array> newest first */
    public function listFor(int $userId, bool $activeOnly = false, int $limit = 100): array
    {
        $sql = 'SELECT * FROM jobs WHERE user_id = ?'
            .($activeOnly ? " AND status IN ('pending','processing')" : '')
            .' ORDER BY created_at DESC, id DESC LIMIT '.max(1, min(500, $limit));
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        return array_map([self::class, 'decode'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** Jobs of this account not yet finished. */
    public function activeCount(int $userId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM jobs WHERE user_id = ? AND status IN ('pending','processing')");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /** Whether any job of this type, by anyone, is queued or running. */
    public function anyActive(string $type): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM jobs WHERE type = ? AND status IN ('pending','processing') LIMIT 1");
        $stmt->execute([$type]);
        return $stmt->fetchColumn() !== false;
    }

    public function pendingCount(): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM jobs WHERE status = 'pending'")->fetchColumn();
    }

    /** @return array<string,int> jobs per status */
    public function counts(): array
    {
        $out = array_fill_keys(self::STATUSES, 0);
        foreach ($this->db->query('SELECT status, COUNT(*) AS n FROM jobs GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(string)$r['status']] = (int)$r['n'];
        }
        return $out;
    }

    /* ---- the worker's side --------------------------------------------- */

    /**
     * Claim the oldest pending job, skipping the given types.
     *
     * Candidates are read first and then claimed one at a time with a
     * compare-and-set, so a candidate another worker took in between is just
     * the next one tried.
     *
     * @param list<string> $skipTypes
     * @return array|null the claimed job, `claim_token` included
     */
    public function claimNext(string $worker, array $skipTypes = []): ?array
    {
        $candidates = $this->db->query(
            "SELECT id, type FROM jobs WHERE status = 'pending' ORDER BY created_at, id LIMIT 20")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $claim = $this->db->prepare(
            "UPDATE jobs SET status = 'processing', claim_token = ?, worker = ?, attempts = attempts + 1,
                started_at = COALESCE(started_at, ?), heartbeat_at = ?, revision = revision + 1
             WHERE id = ? AND status = 'pending'");
        foreach ($candidates as $c) {
            if (in_array((string)$c['type'], $skipTypes, true)) continue;
            $token = bin2hex(random_bytes(16));
            $now = $this->stamp();
            $claim->execute([$token, mb_substr($worker, 0, 100), $now, $now, (string)$c['id']]);
            if ($claim->rowCount() === 1) return $this->find((string)$c['id']);
        }
        return null;
    }

    /**
     * For a type that may only run one at a time: which processing job wins.
     *
     * Two workers can each claim a different job of such a type in the same
     * instant. Both then ask this, both see both claims, and both get the same
     * answer -- the earliest started, then the lowest id -- so exactly one goes
     * on and the other hands its job back.
     */
    public function exclusiveWinner(string $type): ?string
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM jobs WHERE type = ? AND status = 'processing' ORDER BY started_at, id LIMIT 1");
        $stmt->execute([$type]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (string)$id;
    }

    /**
     * Report progress and prove the claim is still held.
     *
     * $leaseUntil moves the heartbeat into the future, for a step that cannot
     * report while it runs (libzip writing an archive, say): the job is not
     * taken for dead until that time has also passed the stale threshold.
     *
     * @return bool|null whether a cancellation was asked for; null when the claim is lost
     */
    public function heartbeat(string $id, string $claim, int $done, int $total, string $unit, ?string $current, ?int $leaseUntil = null): ?bool
    {
        $stmt = $this->db->prepare(
            "UPDATE jobs SET heartbeat_at = ?, progress_done = ?, progress_total = ?, progress_unit = ?, current_item = ?,
                revision = revision + 1
             WHERE id = ? AND claim_token = ? AND status = 'processing'");
        $stmt->execute([$this->stamp($leaseUntil), max(0, $done), max(0, $total), $unit,
            $current === null ? null : mb_substr($current, 0, 1000), $id, $claim]);
        if ($stmt->rowCount() !== 1) return null;
        $stmt = $this->db->prepare('SELECT cancel_requested FROM jobs WHERE id = ? AND claim_token = ?');
        $stmt->execute([$id, $claim]);
        $flag = $stmt->fetchColumn();
        return $flag === false ? null : (bool)(int)$flag;
    }

    /** Keep what a later attempt needs to carry on from where this one got to. */
    public function saveState(string $id, string $claim, array $state): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE jobs SET state = ?, heartbeat_at = ?, revision = revision + 1
             WHERE id = ? AND claim_token = ? AND status = 'processing'");
        $stmt->execute([self::encode($state), $this->stamp(), $id, $claim]);
        return $stmt->rowCount() === 1;
    }

    /**
     * The three ways a claimed job ends. $progress is how far it got --
     * [done, total, unit] -- written with the verdict, because a job that
     * finishes inside its first heartbeat interval has reported none yet.
     * A completed job shows all of its work done.
     */
    public function complete(string $id, string $claim, array $result, ?array $progress = null): bool
    {
        if ($progress !== null) $progress = [max($progress[0], $progress[1]), max($progress[0], $progress[1]), $progress[2]];
        return $this->finish($id, $claim, 'completed', null, $result, $progress, true);
    }

    public function fail(string $id, string $claim, string $error, ?array $result = null, ?array $progress = null): bool
    {
        return $this->finish($id, $claim, 'failed', $error, $result, $progress, false);
    }

    public function cancelled(string $id, string $claim, ?array $result = null, ?array $progress = null): bool
    {
        return $this->finish($id, $claim, 'cancelled', null, $result, $progress, false);
    }

    private function finish(string $id, string $claim, string $status, ?string $error, ?array $result, ?array $progress, bool $full): bool
    {
        $now = $this->stamp();
        $set = $progress !== null ? ', progress_done = ?, progress_total = ?, progress_unit = ?'
            : ($full ? ', progress_done = progress_total' : '');
        $stmt = $this->db->prepare(
            "UPDATE jobs SET status = ?, error = ?, result = ?, finished_at = ?, heartbeat_at = ?, claim_token = NULL,
                current_item = NULL, cancel_requested = 0".$set.", revision = revision + 1
             WHERE id = ? AND claim_token = ? AND status = 'processing'");
        $values = [$status, $error === null ? null : mb_substr($error, 0, 1000),
            $result === null ? null : self::encode($result), $now, $now];
        if ($progress !== null) array_push($values, max(0, (int)$progress[0]), max(0, (int)$progress[1]), (string)$progress[2]);
        array_push($values, $id, $claim);
        $stmt->execute($values);
        return $stmt->rowCount() === 1;
    }

    /**
     * Hand a job back to the queue, as it was: the worker is stopping, or it
     * claimed a job of a type another worker is already running.
     *
     * The attempt the claim counted is given back. Neither case is the job
     * failing, and a worker restarted often enough -- by deployments, say --
     * would otherwise use up a job's attempts without it ever having crashed.
     */
    public function release(string $id, string $claim): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE jobs SET status = 'pending', claim_token = NULL, worker = NULL, heartbeat_at = NULL,
                current_item = NULL, attempts = CASE WHEN attempts > 0 THEN attempts - 1 ELSE 0 END, revision = revision + 1
             WHERE id = ? AND claim_token = ? AND status = 'processing'");
        $stmt->execute([$id, $claim]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Take back jobs whose worker stopped answering.
     *
     * A processing job whose heartbeat is older than $staleSeconds goes back to
     * pending -- the next attempt carries on or starts over, as its type
     * decides -- unless it has already been tried $maxAttempts times, when it
     * fails for good rather than crash a worker in a loop. Each change is a
     * compare-and-set on the very heartbeat that was judged stale, so a worker
     * that reports in at the last moment keeps its job.
     *
     * A job its owner had asked to cancel is finished as cancelled instead:
     * running it again would do what they asked not to have done.
     *
     * @return array{requeued:list<string>,failed:list<string>,cancelled:list<string>}
     */
    public function recoverStale(int $staleSeconds, int $maxAttempts): array
    {
        $cutoff = $this->stamp($this->now() - max(1, $staleSeconds));
        $stmt = $this->db->prepare(
            "SELECT id, attempts, claim_token, cancel_requested FROM jobs WHERE status = 'processing' AND (heartbeat_at IS NULL OR heartbeat_at < ?)");
        $stmt->execute([$cutoff]);
        return $this->reclaim($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], $maxAttempts,
            'It stopped '.max(1, $maxAttempts).' times part way through, most likely because the worker running it was killed or ran out of memory.');
    }

    /**
     * Take back the jobs of workers known to be gone, without waiting for
     * their heartbeats to go stale: a worker restarted after a crash finds the
     * job the crash interrupted at once.
     *
     * @param list<string> $deadWorkers worker ids whose process no longer exists
     * @return array{requeued:list<string>,failed:list<string>,cancelled:list<string>}
     */
    public function recoverFrom(array $deadWorkers, int $maxAttempts): array
    {
        if (!$deadWorkers) return ['requeued' => [], 'failed' => [], 'cancelled' => []];
        $in = implode(',', array_fill(0, count($deadWorkers), '?'));
        $stmt = $this->db->prepare("SELECT id, attempts, claim_token, cancel_requested FROM jobs WHERE status = 'processing' AND worker IN ($in)");
        $stmt->execute(array_values($deadWorkers));
        return $this->reclaim($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], $maxAttempts,
            'Its worker stopped '.max(1, $maxAttempts).' times part way through.');
    }

    /** @return list<array{id:string,worker:string}> */
    public function processingWorkers(): array
    {
        return array_map(static fn(array $r): array => ['id' => (string)$r['id'], 'worker' => (string)$r['worker']],
            $this->db->query("SELECT id, worker FROM jobs WHERE status = 'processing' AND worker IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function reclaim(array $rows, int $maxAttempts, string $giveUp): array
    {
        $out = ['requeued' => [], 'failed' => [], 'cancelled' => []];
        $cancel = $this->db->prepare(
            "UPDATE jobs SET status = 'cancelled', claim_token = NULL, finished_at = ?, current_item = NULL,
                cancel_requested = 0, revision = revision + 1
             WHERE id = ? AND status = 'processing' AND claim_token = ?");
        $requeue = $this->db->prepare(
            "UPDATE jobs SET status = 'pending', claim_token = NULL, worker = NULL, heartbeat_at = NULL, current_item = NULL,
                revision = revision + 1
             WHERE id = ? AND status = 'processing' AND claim_token = ?");
        $fail = $this->db->prepare(
            "UPDATE jobs SET status = 'failed', error = ?, claim_token = NULL, finished_at = ?, current_item = NULL,
                revision = revision + 1
             WHERE id = ? AND status = 'processing' AND claim_token = ?");
        foreach ($rows as $r) {
            $id = (string)$r['id'];
            $claim = (string)$r['claim_token'];
            if ((int)$r['cancel_requested'] === 1) {
                $cancel->execute([$this->stamp(), $id, $claim]);
                if ($cancel->rowCount() === 1) $out['cancelled'][] = $id;
            } elseif ((int)$r['attempts'] >= max(1, $maxAttempts)) {
                $fail->execute([$giveUp, $this->stamp(), $id, $claim]);
                if ($fail->rowCount() === 1) $out['failed'][] = $id;
            } else {
                $requeue->execute([$id, $claim]);
                if ($requeue->rowCount() === 1) $out['requeued'][] = $id;
            }
        }
        return $out;
    }

    /* ---- the owner's side ---------------------------------------------- */

    /**
     * Ask a job to stop.
     *
     * A pending job is cancelled on the spot. A running one is flagged and
     * stops at its next checkpoint, cleaning up after itself; the answer is
     * then 'cancelling'. The two flags are the type's say: an irreversible
     * job, such as emptying the trash, whose items are already out of sight
     * once it is queued, cannot be stopped at all.
     *
     * @return string 'cancelled' or 'cancelling'
     */
    public function requestCancel(string $id, int $userId, bool $cancelableWhileRunning, bool $cancelableWhilePending = true): string
    {
        $job = $this->findFor($id, $userId) ?? throw new RuntimeException('Job not found', 404);
        if ($job['status'] === 'pending') {
            if (!$cancelableWhilePending) throw new RuntimeException('This operation cannot be stopped once it has been queued', 409);
            $now = $this->stamp();
            $stmt = $this->db->prepare(
                "UPDATE jobs SET status = 'cancelled', finished_at = ?, revision = revision + 1
                 WHERE id = ? AND user_id = ? AND status = 'pending'");
            $stmt->execute([$now, $id, $userId]);
            if ($stmt->rowCount() === 1) return 'cancelled';
            $job = $this->findFor($id, $userId) ?? throw new RuntimeException('Job not found', 404);
        }
        if ($job['status'] !== 'processing') throw new RuntimeException('This job has already finished', 409);
        if (!$cancelableWhileRunning) throw new RuntimeException('This operation cannot be stopped once it has started', 409);
        $stmt = $this->db->prepare(
            "UPDATE jobs SET cancel_requested = 1, revision = revision + 1 WHERE id = ? AND user_id = ? AND status = 'processing'");
        $stmt->execute([$id, $userId]);
        if ($stmt->rowCount() === 1) return 'cancelling';
        throw new RuntimeException('This job has already finished', 409);
    }

    /**
     * Queue a failed or cancelled job again.
     *
     * Its saved state is kept, so a type that can carry on does: a copy
     * stopped after five of twenty items copies the other fifteen, rather
     * than a second set of the first five. A type that cannot carry on
     * ignores the state and starts over.
     */
    public function retry(string $id, int $userId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE jobs SET status = 'pending', error = NULL, result = NULL, progress_done = 0, progress_total = 0,
                current_item = NULL, attempts = 0, cancel_requested = 0, claim_token = NULL, worker = NULL,
                created_at = ?, started_at = NULL, heartbeat_at = NULL, finished_at = NULL, revision = revision + 1
             WHERE id = ? AND user_id = ? AND status IN ('failed','cancelled')");
        $stmt->execute([$this->stamp(), $id, $userId]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Forget a finished job. Its files are the caller's to remove.
     *
     * Finished only: a queued job is called off with a cancel, which its type
     * may refuse -- emptying the trash cannot be stopped once queued, and a
     * remove must not be the way round that.
     */
    public function remove(string $id, int $userId): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM jobs WHERE id = ? AND user_id = ? AND status IN ('completed','failed','cancelled')");
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() === 1;
    }

    /** @return list<array> this account's finished jobs, for "Clear finished" */
    public function finishedFor(int $userId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM jobs WHERE user_id = ? AND status IN ('completed','failed','cancelled')");
        $stmt->execute([$userId]);
        return array_map([self::class, 'decode'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /* ---- housekeeping --------------------------------------------------- */

    /** @return list<array> finished jobs older than the retention, oldest first */
    public function expired(int $retentionSeconds, int $limit = 200): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM jobs WHERE status IN ('completed','failed','cancelled') AND finished_at < ?
             ORDER BY finished_at LIMIT ".max(1, $limit));
        $stmt->execute([$this->stamp($this->now() - max(0, $retentionSeconds))]);
        return array_map([self::class, 'decode'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** Delete a finished job outright. */
    public function delete(string $id): void
    {
        $this->db->prepare("DELETE FROM jobs WHERE id = ? AND status IN ('completed','failed','cancelled')")->execute([$id]);
    }

    /**
     * The status of each of these jobs that is still recorded, for matching
     * work folders to jobs. An id missing from the answer names no job.
     *
     * @return array<string,string> id => status
     */
    public function statuses(array $ids): array
    {
        $ids = array_values(array_filter($ids, static fn(mixed $id): bool => is_string($id) && self::validId($id)));
        if (!$ids) return [];
        $stmt = $this->db->prepare('SELECT id, status FROM jobs WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).')');
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $out[(string)$row['id']] = (string)$row['status'];
        return $out;
    }

    /* ---- encoding -------------------------------------------------------- */

    private static function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }

    private static function decodeJson(mixed $value): ?array
    {
        if ($value === null || $value === '') return null;
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function decode(array $row): array
    {
        return [
            'id' => (string)$row['id'],
            'user_id' => (int)$row['user_id'],
            'type' => (string)$row['type'],
            'status' => (string)$row['status'],
            'label' => (string)$row['label'],
            'target' => (string)$row['target'],
            'payload' => self::decodeJson($row['payload']) ?? [],
            'state' => self::decodeJson($row['state'] ?? null),
            'result' => self::decodeJson($row['result'] ?? null),
            'progress_done' => (int)$row['progress_done'],
            'progress_total' => (int)$row['progress_total'],
            'progress_unit' => (string)$row['progress_unit'],
            'current_item' => $row['current_item'] === null ? null : (string)$row['current_item'],
            'error' => $row['error'] === null ? null : (string)$row['error'],
            'attempts' => (int)$row['attempts'],
            'cancel_requested' => (bool)(int)$row['cancel_requested'],
            'claim_token' => $row['claim_token'] === null ? null : (string)$row['claim_token'],
            'worker' => $row['worker'] === null ? null : (string)$row['worker'],
            'created_at' => (string)$row['created_at'],
            'started_at' => $row['started_at'] === null ? null : (string)$row['started_at'],
            'heartbeat_at' => $row['heartbeat_at'] === null ? null : (string)$row['heartbeat_at'],
            'finished_at' => $row['finished_at'] === null ? null : (string)$row['finished_at'],
        ];
    }
}
