<?php
declare(strict_types=1);
namespace CloudHub\Services\Jobs;

/**
 * The worker is stopping (SIGTERM, Ctrl+C). The job goes back to the queue as
 * it is, so another worker -- or this one, restarted -- carries it on.
 */
final class JobInterrupted extends \RuntimeException {}
