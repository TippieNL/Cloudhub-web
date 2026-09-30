<?php
declare(strict_types=1);
namespace CloudHub\Services\Jobs;

/**
 * The job's owner asked it to stop; it cleans up and ends as cancelled.
 *
 * A type may rethrow it with a result, so the owner sees what was done before
 * it stopped -- the items a copy had already placed stay placed.
 */
final class JobCancelled extends \RuntimeException
{
    public function __construct(string $message = 'Cancelled', public readonly array $result = [])
    {
        parent::__construct($message);
    }
}
