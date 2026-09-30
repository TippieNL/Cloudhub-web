<?php
declare(strict_types=1);
namespace CloudHub\Services\Jobs;

use CloudHub\Repositories\FavoriteRepository;
use CloudHub\Repositories\JobRepository;
use CloudHub\Repositories\ShareLinkRepository;
use CloudHub\Repositories\StorageLedger;
use CloudHub\Repositories\UserRepository;
use CloudHub\Services\FileService;
use CloudHub\Services\StorageQuota;
use PDO;
use RuntimeException;

/**
 * What a job type works with: the configuration, the file store and the
 * bookkeeping around it, shared by the request that queues a job and the
 * worker that runs it.
 *
 * Every job gets one work directory, ROOT_DIR/.jobs/<id>. It is inside the
 * storage root on purpose, as .trash and .uploads are: a copy or an extraction
 * staged there moves into place with rename(), which is atomic and cannot
 * half-finish, so nothing a failed or cancelled job wrote is ever visible.
 * .jobs is reserved, so no route lists, searches, counts or addresses it.
 */
final class JobEnvironment
{
    private ?StorageLedger $ledger = null;
    private ?StorageQuota $quota = null;

    public function __construct(
        public readonly array $config,
        public readonly FileService $files,
        public readonly PDO $db,
        public readonly string $projectDir,
    ) {}

    public function jobs(): JobRepository { return new JobRepository($this->db); }
    public function users(): UserRepository { return new UserRepository($this->db); }
    public function shares(): ShareLinkRepository { return new ShareLinkRepository($this->db); }
    public function favorites(): FavoriteRepository { return new FavoriteRepository($this->db); }
    public function ledger(): StorageLedger { return $this->ledger ??= new StorageLedger($this->db); }
    public function quota(): StorageQuota { return $this->quota ??= new StorageQuota($this->files, $this->config, $this->ledger(), $this->projectDir); }

    /**
     * ROOT_DIR/.jobs, where every job keeps its staging and its output.
     *
     * Refused if it is a symbolic link: everything below it is deleted by
     * path, and a link would carry those deletions outside the storage root.
     */
    public function workRoot(): string
    {
        $dir = $this->files->root().'/.jobs';
        if (is_link($dir)) throw new RuntimeException('The job folder is a symbolic link; refusing to use it', 500);
        return $dir;
    }

    /** This job's work directory, which may not exist yet. */
    public function workDir(string $jobId): string
    {
        if (!JobRepository::validId($jobId)) throw new RuntimeException('Invalid job id', 400);
        return $this->workRoot().'/'.$jobId;
    }

    /** This job's work directory, created if need be. */
    public function ensureWorkDir(string $jobId): string
    {
        return $this->ensureDir($this->workDir($jobId));
    }

    /**
     * Where one attempt stages what it has not yet moved into place.
     *
     * One per attempt, so a worker that stalled long enough to lose its job
     * never writes into the directory of the attempt that took over: when it
     * wakes, its files land somewhere nobody reads, and its next checkpoint
     * stops it.
     */
    public function stagingDir(string $jobId, int $attempt): string
    {
        return $this->ensureDir($this->workDir($jobId).'/attempt-'.max(1, $attempt));
    }

    /** Where a job keeps a result the owner downloads, such as an archive. */
    public function outputDir(string $jobId): string
    {
        return $this->ensureDir($this->workDir($jobId).'/output');
    }

    /** Remove the staging of every attempt, keeping any output. */
    public function clearStaging(string $jobId): void
    {
        $dir = $this->workDir($jobId);
        if (!is_dir($dir) || is_link($dir)) return;
        foreach (scandir($dir) ?: [] as $name) {
            if (preg_match('/^attempt-\d+$/', $name)) $this->files->deleteTree($dir.'/'.$name);
        }
        // Nothing left means nothing to keep; an empty folder per job would
        // otherwise pile up in .jobs for as long as the jobs are listed.
        if ((scandir($dir) ?: []) === ['.', '..']) @rmdir($dir);
    }

    /** Remove this job's work directory and everything in it. */
    public function removeWorkDir(string $jobId): void
    {
        $dir = $this->workDir($jobId);
        if (file_exists($dir) || is_link($dir)) $this->files->deleteTree($dir);
    }

    private function ensureDir(string $dir): string
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create the job\'s working folder', 500);
        }
        return $dir;
    }
}
