<?php
declare(strict_types=1);
namespace CloudHub\Services\Jobs;

/**
 * The job could not do all it was asked, and says what it did do: a copy that
 * placed three items and could not place two fails with both lists, so its
 * owner sees what landed, and a retry only tries the two again.
 */
final class JobFailed extends \RuntimeException
{
    public function __construct(string $message, public readonly array $result = [], int $code = 422)
    {
        parent::__construct($message, $code);
    }
}
