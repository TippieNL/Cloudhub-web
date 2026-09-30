<?php
declare(strict_types=1);
namespace CloudHub\Services\Jobs;

/**
 * The claim on the job is gone: this worker stalled long enough for the job to
 * be judged abandoned and handed to another. It must stop at once and touch
 * nothing more -- not the job's record, and not its work directory, which the
 * new owner may already be using.
 */
final class JobLost extends \RuntimeException {}
