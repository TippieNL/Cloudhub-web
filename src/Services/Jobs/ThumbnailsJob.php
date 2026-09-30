<?php
declare(strict_types=1);
namespace CloudHub\Services\Jobs;

use CloudHub\Services\ImageThumbnailer;
use RuntimeException;

/**
 * Make the thumbnails of every photo in a folder ahead of time, so opening a
 * large gallery does not decode each one while somebody waits.
 *
 * The entries are the ones /api/thumbnail serves -- same key, same encoder,
 * same limits -- so the route finds them already made. Nothing in the store
 * changes. Starting one decodes every photo under the folder, which is the
 * on-demand expense the duplicate scan is kept to editors for, so this is
 * kept to them too. Videos are left alone: their frames can only come from a
 * browser.
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
        $start = $fs->existing((string)($ctx->payload()['path'] ?? '/'));
        if (!is_dir($start)) throw new RuntimeException('That is not a folder', 400);

        $ctx->progress(0, 'Counting photos');
        $total = 0;
        foreach (Tree::walk($fs, $start) as [$full, $isDir]) {
            if (!$isDir && self::isImage($full)) $total++;
            $ctx->checkpoint();
        }
        $ctx->total($total, 'items');
        $ctx->checkpoint(true);

        $made = 0; $had = 0; $skipped = 0;
        foreach (Tree::walk($fs, $start) as [$full, $isDir]) {
            if ($isDir || !self::isImage($full)) continue;
            $ctx->advance(1, $fs->relative($full));
            $cache = ImageThumbnailer::cachePath($env->projectDir, $full);
            if ($cache === null) { $skipped++; continue; }
            if (is_file($cache)) { $had++; $ctx->checkpoint(); continue; }
            try {
                ImageThumbnailer::generate($full, $cache);
                $made++;
            } catch (RuntimeException $e) {
                // Unreadable, too large or an unsupported flavour: the same
                // photos the gallery shows an icon for. One is not the job.
                if ((int)$e->getCode() === 503) throw $e;
                $skipped++;
            }
            $ctx->checkpoint();
        }
        return ['path' => $fs->relative($start), 'made' => $made, 'existing' => $had, 'skipped' => $skipped];
    }

    private static function isImage(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ImageThumbnailer::IMAGE_EXTENSIONS, true);
    }
}
