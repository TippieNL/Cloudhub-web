<?php
declare(strict_types=1);
namespace CloudHub\Services\Jobs;

use RuntimeException;

/**
 * Checksums of files, for checking a copy or a download against its source.
 *
 * Reading only, and no more expensive than downloading the files, which any
 * signed-in account may already do; so any signed-in account may ask. The
 * algorithm is chosen from a fixed list and never passed anywhere but hash_init().
 */
final class ChecksumJob extends BaseJobType
{
    public const ALGORITHMS = ['sha256' => 'SHA-256', 'sha1' => 'SHA-1', 'md5' => 'MD5'];
    private const MAX_FILES = 100;

    public function name(): string { return 'checksum'; }
    public function capability(array $payload): string { return 'read'; }
    public function writesStore(array $payload): bool { return false; }

    public function prepare(array $params, JobEnvironment $env, array $user): array
    {
        $fs = $env->files;
        $paths = self::existingPaths($params['paths'] ?? null, $fs, self::MAX_FILES);
        foreach ($paths as $rel) {
            if (!is_file($fs->existing($rel))) throw new RuntimeException('Checksums can only be made of files; "'.basename($rel).'" is a folder', 400);
        }
        $algorithm = $params['algorithm'] ?? 'sha256';
        if (!is_string($algorithm) || !isset(self::ALGORITHMS[$algorithm])) {
            throw new RuntimeException('algorithm must be one of '.implode(', ', array_keys(self::ALGORITHMS)), 422);
        }
        return [
            'label' => self::ALGORITHMS[$algorithm].' of '.(count($paths) === 1 ? '"'.basename($paths[0]).'"' : self::count(count($paths), 'file')),
            'target' => dirname($paths[0]),
            'payload' => ['paths' => $paths, 'algorithm' => $algorithm],
        ];
    }

    public function run(JobContext $ctx): array
    {
        $fs = $ctx->env()->files;
        $payload = $ctx->payload();
        $algorithm = (string)($payload['algorithm'] ?? 'sha256');
        if (!isset(self::ALGORITHMS[$algorithm])) throw new RuntimeException('Unsupported checksum algorithm', 422);

        $files = [];
        $total = 0;
        foreach ((array)($payload['paths'] ?? []) as $rel) {
            $full = $fs->existing((string)$rel);
            if (!is_file($full)) throw new RuntimeException('"'.basename((string)$rel).'" is no longer a file', 400);
            $files[] = [(string)$rel, $full];
            $total += (int)(@filesize($full) ?: 0);
        }
        $ctx->total($total, 'bytes');
        $ctx->checkpoint(true);

        $out = [];
        foreach ($files as [$rel, $full]) {
            $in = @fopen($full, 'rb');
            if ($in === false) throw new RuntimeException('Unable to read '.basename($rel), 500);
            $hash = hash_init($algorithm);
            $bytes = 0;
            try {
                $ctx->progress($ctx->done(), $rel);
                while (!feof($in)) {
                    $data = fread($in, Tree::CHUNK_BYTES);
                    if ($data === false) throw new RuntimeException('Unable to read '.basename($rel), 500);
                    if ($data === '') break;
                    hash_update($hash, $data);
                    $bytes += strlen($data);
                    $ctx->advance(strlen($data));
                    $ctx->checkpoint();
                }
            } finally {
                fclose($in);
            }
            $out[] = ['path' => $rel, 'bytes' => $bytes, 'hash' => hash_final($hash)];
        }
        return ['algorithm' => $algorithm, 'files' => $out];
    }
}
