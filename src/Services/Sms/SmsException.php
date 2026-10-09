<?php
declare(strict_types=1);

namespace CloudHub\Services\Sms;

/**
 * A text message the gateway did not accept, and why, in the terms the caller
 * acts on.
 *
 * The message is for the server log and names the gateway, its HTTP status and
 * its own error code -- never the destination number, the text, or the code in
 * it. What a person is told is decided from the kind, not from this message.
 */
final class SmsException extends \RuntimeException
{
    /** The gateway refused this number; sending again will not help. */
    public const REJECTED = 'rejected';

    /**
     * The gateway was unreachable, refused CloudHub's credentials, or answered
     * with something that cannot be read. It may work later.
     */
    public const UNAVAILABLE = 'unavailable';

    /** No gateway is configured, or the one named cannot be used here. */
    public const NOT_CONFIGURED = 'not_configured';

    public function __construct(public readonly string $kind, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
