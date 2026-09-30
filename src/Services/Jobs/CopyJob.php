<?php
declare(strict_types=1);
namespace CloudHub\Services\Jobs;

use RuntimeException;

/**
 * Copy files and folders into a folder, in the background.
 *
 * The rules are the copy route's: every item is attempted and the failures
 * come back named; a name that is taken gets " (2)" unless overwriting is
 * off; a folder cannot go into itself; the copy has to fit the quota and is
 * charged to whoever made it.
 *
 * What is different is how an item lands. It is copied into the attempt's
 * staging folder first and moved into place with one rename, so an item is
 * either all there or not there at all -- a cancel, a full disk or a killed
 * worker never leaves half a folder in the store. Items already placed stay
 * placed, and the job's state remembers them: a retry, or the next attempt
 * after a crash, copies only what is left.
 */
final class CopyJob extends BaseJobType
{
    public function name(): string { return 'copy'; }

    public function prepare(array $params, JobEnvironment $env, array $user): array
    {
        $fs = $env->files;
        $fs->writable();
        $paths = self::existingPaths($params['paths'] ?? null, $fs);
        $destination = self::existingFolder($params['destination'] ?? null, $fs);
        return [
            'label' => count($paths) === 1 ? 'Copy "'.basename($paths[0]).'"' : 'Copy '.self::count(count($paths), 'item'),
            'target' => $destination,
            'payload' => ['paths' => $paths, 'destination' => $destination],
        ];
    }

    public function run(JobContext $ctx): array
    {
        $env = $ctx->env();
        $fs = $env->files;
        $fs->writable();
        $payload = $ctx->payload();
        $paths = array_values(array_filter((array)($payload['paths'] ?? []), 'is_string'));
        $state = $ctx->state();
        /** @var array<int,string> $placed item index => where it landed */
        $placed = [];
        foreach ((array)($state['placed'] ?? []) as $i => $to) $placed[(int)$i] = (string)$to;

        // The last attempt stopped after choosing a name and before recording
        // the item as placed. If the item is at that name, the rename happened.
        $placing = $state['placing'] ?? null;
        if (is_array($placing) && isset($placing['index'], $placing['to'])) {
            try { $landed = $fs->sanitize((string)$placing['to']); } catch (RuntimeException) { $landed = null; }
            if ($landed !== null && file_exists($landed)) {
                $placed[(int)$placing['index']] = (string)$placing['to'];
                self::recordOwnership($ctx, $landed);
            }
            $this->save($ctx, $placed, null);
        }

        $destination = $fs->existing((string)($payload['destination'] ?? ''));
        if (!is_dir($destination)) throw new RuntimeException('The destination is not a folder', 400);

        // Measured first, so progress is in bytes: one large folder is most of
        // a copy, and a count of top-level items would sit at 0% throughout.
        $todo = [];
        $total = 0;
        $ctx->progress(0, 'Measuring');
        foreach ($paths as $i => $rel) {
            if (isset($placed[$i])) continue;
            try {
                $bytes = Tree::measure($fs, $fs->existing($rel), null, null, static fn() => $ctx->checkpoint())['bytes'];
            } catch (RuntimeException $e) {
                if (self::stops($e)) throw $e;
                $bytes = 0;
            }
            $todo[$i] = $bytes;
            $total += $bytes;
        }
        $ctx->total($total, 'bytes');
        $ctx->progress(0);
        $ctx->checkpoint(true);

        $failed = [];
        foreach ($todo as $i => $bytes) {
            $rel = $paths[$i];
            $staged = null;
            try {
                $source = $fs->existing($rel);
                if (is_dir($source) && str_starts_with($destination.'/', $source.'/')) throw new RuntimeException('A folder cannot be copied into itself', 409);
                if (!$env->config['allow_overwrite'] && file_exists($fs->childPath($destination, basename($source)))) {
                    throw new RuntimeException('An item with that name is already there', 409);
                }
                $env->quota()->assertFits($bytes, $ctx->owner());
                self::assertDiskSpace($env, $bytes);

                $staged = $ctx->staging().'/'.$i;
                if (file_exists($staged) || is_link($staged)) $fs->deleteTree($staged);
                Tree::copy($fs, $source, $staged, $ctx, $rel);
                $target = self::moveIntoPlace($ctx, $staged, $destination, basename($source),
                    fn(string $to) => $this->save($ctx, $placed, ['index' => $i, 'to' => $to]));
                $staged = null;
                $placed[$i] = $fs->relative($target);
                self::recordOwnership($ctx, $target);
                $this->save($ctx, $placed, null);
            } catch (JobCancelled $e) {
                throw new JobCancelled('Cancelled', $this->result($fs->relative($destination), $placed, $failed, count($paths)));
            } catch (RuntimeException $e) {
                if (self::stops($e)) throw $e;
                $failed[] = ['path' => $rel, 'message' => $e->getMessage()];
                // What a failed item staged goes now, not at the end: the disk
                // filling up is the likeliest failure, and the next item needs
                // that space.
                if ($staged !== null && (file_exists($staged) || is_link($staged))) {
                    try { $fs->deleteTree($staged); } catch (\Throwable) {}
                }
            }
        }

        $result = $this->result($fs->relative($destination), $placed, $failed, count($paths));
        if ($failed) {
            throw new JobFailed(count($failed) === count($todo) && !$placed
                ? 'Nothing could be copied: '.$failed[0]['message']
                : count($failed).' of '.count($paths).' items could not be copied', $result);
        }
        return $result;
    }

    /** Stops the job rather than failing one item: the queue's signals, and a database gone away. */
    private static function stops(RuntimeException $e): bool
    {
        return $e instanceof JobCancelled || $e instanceof JobInterrupted || $e instanceof JobLost || $e instanceof \PDOException;
    }

    private function save(JobContext $ctx, array $placed, ?array $placing): void
    {
        $ctx->saveState(['placed' => $placed, 'placing' => $placing]);
    }

    private function result(string $destination, array $placed, array $failed, int $count): array
    {
        ksort($placed);
        return ['destination' => $destination, 'copied' => count($placed), 'of' => $count,
            'items' => array_values($placed), 'failed' => $failed];
    }
}
