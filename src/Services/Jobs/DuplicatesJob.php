<?php
declare(strict_types=1);
namespace CloudHub\Services\Jobs;

use CloudHub\Services\DuplicateFinder;

/**
 * The duplicate scan, run to the end by a worker instead of one slice per
 * browser poll -- so it finishes with the tab closed.
 *
 * It drives the same DuplicateFinder the scan route drives, and the finder
 * keeps one result for the whole installation. Two scans advancing it at once
 * would overwrite each other's progress, so this type is exclusive and the
 * route refuses to advance a scan while one of these is queued or running.
 * GET /api/duplicates/scan reads the result as it grows, exactly as before.
 */
final class DuplicatesJob extends BaseJobType
{
    /** How long the walk that begins a scan may go without reporting. */
    private const WALK_LEASE_SECONDS = 900;

    public function name(): string { return 'duplicates'; }
    public function writesStore(array $payload): bool { return false; }
    public function exclusive(): bool { return true; }

    public function prepare(array $params, JobEnvironment $env, array $user): array
    {
        $folder = self::existingFolder($params['path'] ?? '/', $env->files);
        return [
            'label' => $folder === '/' ? 'Find duplicates in the whole store' : 'Find duplicates in "'.basename($folder).'"',
            'target' => $folder,
            'payload' => ['path' => $folder],
        ];
    }

    public function run(JobContext $ctx): array
    {
        $env = $ctx->env();
        $finder = new DuplicateFinder($env->config, $env->files, $env->projectDir.'/storage/.cache');
        $slice = max(1, (int)($env->config['duplicate_scan_seconds'] ?? 8));

        // Neither the walk nor a slice can report while it runs: a slice checks
        // its clock between files, and hashing one large file cannot be
        // paused. The lease keeps the job from being taken for abandoned
        // meanwhile; a worker that really dies is noticed by its process
        // having gone, sooner than that.
        $ctx->progress(0, 'Looking for candidates');
        $ctx->extendLease(self::WALK_LEASE_SECONDS);
        $progress = $finder->begin((string)($ctx->payload()['path'] ?? '/'));
        while (!$progress['done']) {
            $ctx->total((int)$progress['toHash'], 'items');
            $ctx->progress((int)$progress['hashed'], 'Comparing files');
            $ctx->checkpoint(true);
            $ctx->extendLease($slice * 3 + 600);
            $progress = $finder->advance();
        }
        $ctx->total((int)$progress['toHash'], 'items');
        $ctx->progress((int)$progress['hashed']);
        return [
            'path' => $progress['path'],
            'groups' => count($progress['groups']),
            'duplicateFiles' => (int)$progress['duplicateFiles'],
            'reclaimable' => (int)$progress['reclaimable'],
            'scanned' => (int)$progress['scanned'],
            'truncated' => (bool)$progress['truncated'],
        ];
    }
}
