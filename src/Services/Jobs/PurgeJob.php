<?php
declare(strict_types=1);
namespace CloudHub\Services\Jobs;

use RuntimeException;

/**
 * Permanently delete what a route has already taken out of sight.
 *
 * Emptying a large trash, or deleting a large folder when the trash is off,
 * used to walk and unlink every file inside the request. Now the route moves
 * each item into this job's holding folder -- one rename per item, instant and
 * atomic -- does the bookkeeping, and queues the job; the item has left the
 * listing and the trash before the request returns, and the worker does the
 * slow part.
 *
 * Moving first is what makes the background version safe: nothing can
 * restore, open, share or re-upload over an item while it is being deleted,
 * because it is no longer anywhere a route can reach. It is also why the job
 * cannot be cancelled -- the items have already gone -- and why a failed one
 * keeps its holding folder: a retry finishes the deletion, and removing the
 * job lets housekeeping finish it.
 */
final class PurgeJob extends BaseJobType
{
    public function name(): string { return 'purge'; }
    public function clientCreatable(): bool { return false; }
    public function cancelableWhilePending(): bool { return false; }
    public function cancelableWhileRunning(): bool { return false; }

    /** Only routes queue this job, having moved the items in themselves; see holdingDir(). */
    public function prepare(array $params, JobEnvironment $env, array $user): array
    {
        throw new RuntimeException('Unknown operation', 400);
    }

    /** The folder a route moves items into before it queues the job. */
    public static function holdingDir(JobEnvironment $env, string $jobId): string
    {
        $dir = $env->ensureWorkDir($jobId).'/purge';
        if (!is_dir($dir) && !@mkdir($dir, 0775) && !is_dir($dir)) throw new RuntimeException('Unable to prepare the deletion', 500);
        return $dir;
    }

    public function run(JobContext $ctx): array
    {
        $env = $ctx->env();
        $dir = $env->workDir($ctx->id()).'/purge';
        $payload = $ctx->payload();
        $ctx->total(max(0, (int)($payload['files'] ?? 0)), 'items');
        $ctx->progress(0);
        $deleted = 0;
        if (is_dir($dir) && !is_link($dir)) {
            foreach (scandir($dir) ?: [] as $name) {
                if ($name === '.' || $name === '..') continue;
                Tree::remove($env->files, $dir.'/'.$name, static function () use ($ctx, &$deleted): void {
                    $deleted++;
                    $ctx->advance(1);
                    $ctx->checkpoint();
                });
            }
            if (!@rmdir($dir) && is_dir($dir)) throw new RuntimeException('Unable to finish the deletion', 500);
        }
        return ['files' => $deleted, 'entries' => (int)($payload['entries'] ?? 0)];
    }

    /** Nothing to undo, and what is left is what a retry needs. */
    public function discard(string $jobId, JobEnvironment $env): void {}
}
