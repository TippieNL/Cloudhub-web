<?php
declare(strict_types=1);
namespace CloudHub\Services\Jobs;

use CloudHub\Services\ThumbnailService;
use RuntimeException;

/**
 * Make the thumbnails of every photo in a folder ahead of time, so opening a
 * large gallery does not decode each one while somebody waits.
 *
 * The thumbnails are the ones /api/thumbnail serves -- ThumbnailService's,
 * kept in the mirror of the store with the same size, encoder and limits --
 * so the route finds them already made. Nothing in the store changes.
 * Starting one decodes every photo under the folder, which is the on-demand
 * expense the duplicate scan is kept to editors for, so this is kept to them
 * too. Videos are left alone: their frames can only come from a client.
 *
 * The whole store is also covered by the thumbnail scan (ThumbnailScan,
 * POST /api/thumbnails/scan, tools/thumbnails.php); this is the same work for
 * one folder, as a task. The two can run at once: a thumbnail is made under
 * its own lock, so a photo is decoded once whoever asks first.
 */
final class ThumbnailsJob extends BaseJobType
{
    public function name(): string { return 'thumbnails'; }
    public function writesStore(array $payload): bool { return false; }

    public function prepare(array $params, JobEnvironment $env, array $user): array
    {
        $folder = self::existingFolder($params['path'] ?? '/', $env->files);
        return [
            'label' => $folder === '/' ? 'Make thumbnails for the whole store' : 'Make thumbnails in "'.basename($folder).'"',
            'target' => $folder,
            'payload' => ['path' => $folder],
        ];
    }

    public function run(JobContext $ctx): array
    {
        if (!extension_loaded('gd')) throw new RuntimeException('Thumbnails need the PHP GD extension', 503);
        $env = $ctx->env();
        $fs = $env->files;
        $thumbs = $env->thumbnails();
        $start = $fs->existing((string)($ctx->payload()['path'] ?? '/'));
        if (!is_dir($start)) throw new RuntimeException('That is not a folder', 400);

        $ctx->progress(0, 'Counting photos');
        $total = 0;
        foreach (Tree::walk($fs, $start) as [$full, $isDir]) {
            if (!$isDir && ThumbnailService::isImage($full)) $total++;
            $ctx->checkpoint();
        }
        $ctx->total($total, 'items');
        $ctx->checkpoint(true);

        $made = 0; $had = 0; $skipped = 0;
        foreach (Tree::walk($fs, $start) as [$full, $isDir]) {
            if ($isDir || !ThumbnailService::isImage($full)) continue;
            $ctx->advance(1, $fs->relative($full));
            // A format this server's GD cannot read, or the one name the
            // mirror cannot hold: the gallery shows these without a kept one.
            if (!ThumbnailService::canDecode(ThumbnailService::extensionOf($full)) || !$thumbs->canKeep($full)) {
                $skipped++;
                $ctx->checkpoint();
                continue;
            }
            try {
                if ($thumbs->ensure($full, true)['status'] === ThumbnailService::GENERATED) $made++;
                else $had++;
            } catch (RuntimeException $e) {
                // Unreadable or too large: the same photos the gallery shows an
                // icon for, remembered so they are not decoded again. One is
                // not the job.
                if ((int)$e->getCode() === 503) throw $e;
                $skipped++;
            }
            $ctx->checkpoint();
        }
        return ['path' => $fs->relative($start), 'made' => $made, 'existing' => $had, 'skipped' => $skipped];
    }
}
