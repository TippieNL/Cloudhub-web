<?php
declare(strict_types=1);
namespace CloudHub\Services\Jobs;

use CloudHub\Repositories\JobRepository;

/**
 * The job types this installation knows, by name, and how a job is shown to
 * its owner.
 *
 * A name that is not registered here is not a job anyone can queue, whatever
 * a client sends; and a queued job whose type has since gone fails with a
 * message rather than running something else.
 */
final class JobTypes
{
    /** @var array<string,JobType> */
    private array $types = [];

    public function __construct(JobType ...$types)
    {
        foreach ($types as $type) $this->types[$type->name()] = $type;
    }

    /** Every operation CloudHub runs in the background. A new one is added here. */
    public static function standard(): self
    {
        return new self(
            new CopyJob(),
            new ArchiveJob(),
            new ExtractJob(),
            new PurgeJob(),
            new ThumbnailsJob(),
            new DuplicatesJob(),
            new ChecksumJob(),
        );
    }

    public function get(string $name): ?JobType
    {
        return $this->types[$name] ?? null;
    }

    /** @return list<string> the types at most one of which may run at a time */
    public function exclusive(): array
    {
        return array_values(array_map(static fn(JobType $t): string => $t->name(),
            array_filter($this->types, static fn(JobType $t): bool => $t->exclusive())));
    }

    /**
     * A job as its owner sees it: never the claim token, the worker, the
     * payload or the saved state, which are the queue's business.
     */
    public function present(array $job): array
    {
        $type = $this->get($job['type']);
        $status = $job['status'];
        $done = (int)$job['progress_done'];
        $total = (int)$job['progress_total'];
        $percent = match (true) {
            $status === 'completed' => 100,
            $total > 0 => (int)min(99, floor($done * 100 / $total)),
            default => null,
        };
        return [
            'id' => $job['id'],
            'type' => $job['type'],
            'label' => $job['label'],
            'target' => $job['target'],
            'status' => $status,
            'progress' => ['done' => $done, 'total' => $total, 'unit' => $job['progress_unit'], 'percent' => $percent],
            'currentItem' => $status === 'processing' ? $job['current_item'] : null,
            'error' => $job['error'],
            'result' => $job['result'],
            'attempts' => (int)$job['attempts'],
            'cancelRequested' => (bool)$job['cancel_requested'],
            'canCancel' => $type !== null && !$job['cancel_requested'] && (
                ($status === 'pending' && $type->cancelableWhilePending())
                || ($status === 'processing' && $type->cancelableWhileRunning())),
            'canRetry' => $type !== null && in_array($status, ['failed', 'cancelled'], true),
            'canRemove' => in_array($status, JobRepository::FINISHED, true),
            'hasDownload' => $status === 'completed' && !empty($job['result']['download']),
            'createdAt' => self::iso($job['created_at']),
            'startedAt' => self::iso($job['started_at']),
            'finishedAt' => self::iso($job['finished_at']),
        ];
    }

    private static function iso(?string $stamp): ?string
    {
        if ($stamp === null || $stamp === '') return null;
        $at = strtotime($stamp.' UTC');
        return $at === false ? null : gmdate('c', $at);
    }
}
