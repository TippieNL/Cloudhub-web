<?php
declare(strict_types=1);
namespace CloudHub\Services\Jobs;

/**
 * One kind of background job.
 *
 * Adding an operation means writing one of these and registering it in
 * JobTypes::standard(). The queue, the worker, the API and the Tasks screen
 * need nothing else.
 *
 * Nothing a client sends is trusted twice. prepare() validates parameters
 * when the job is queued and stores canonical relative paths; run() resolves
 * every one of them through FileService again, because by the time a worker
 * gets to the job the tree may have changed underneath it.
 *
 * A job may be run more than once: after a crash, a worker restart, or a
 * retry. run() reads what an earlier attempt saved with JobContext::saveState()
 * and carries on from there, or starts over when it saved nothing. Whatever an
 * attempt staged but did not move into place is gone by the time the next one
 * starts.
 */
interface JobType
{
    /** The name stored in jobs.type and used by POST /api/jobs. */
    public function name(): string;

    /**
     * 'read' or 'write': the role the owner needs to queue the job, and still
     * needs when it runs -- an account demoted in between gets a failed job,
     * not a write it no longer has the right to make.
     */
    public function capability(array $payload): string;

    /**
     * Whether it writes to the file store. Such a job is refused while the
     * server is read-only, and cached listings are retired after it.
     */
    public function writesStore(array $payload): bool;

    /** Whether a client may queue it directly, rather than a route preparing it. */
    public function clientCreatable(): bool;

    /** Whether a queued job may be called off before it starts. */
    public function cancelableWhilePending(): bool;

    /** Whether a running job may be stopped part way: false for what cannot be undone. */
    public function cancelableWhileRunning(): bool;

    /** Whether at most one job of this type may run at a time, across all accounts. */
    public function exclusive(): bool;

    /**
     * Validate a client's parameters and turn them into what is stored.
     *
     * @param array{id:int,username:string,role:string} $user
     * @return array{label:string,target:string,payload:array}
     */
    public function prepare(array $params, JobEnvironment $env, array $user): array;

    /**
     * Do the work, reporting through $ctx. Returns the result shown to the
     * owner. Throws JobFailed to fail with a result of its own, such as the
     * items that could not be copied.
     */
    public function run(JobContext $ctx): array;

    /**
     * Remove what a failed or cancelled job leaves behind: its staging and
     * any partial output. Never anything already moved into the store.
     */
    public function discard(string $jobId, JobEnvironment $env): void;

    /**
     * A finished job's file for its owner to download, or null.
     *
     * @return array{path:string,name:string,mime:string}|null
     */
    public function download(array $job, JobEnvironment $env): ?array;
}
