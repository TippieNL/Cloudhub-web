<?php
declare(strict_types=1);

namespace CloudHub\Services\Mail;

/**
 * A message the mail server did not accept, and why, in the terms the caller
 * acts on.
 *
 * The message is for the server log and names the step and the SMTP status
 * -- never the address, the text, the code in it, or the server's own reply,
 * which can quote the address. What a person is told is decided from the
 * kind, not from this message.
 */
final class MailException extends \RuntimeException
{
    /** The server refused this address; sending again will not help. */
    public const REJECTED = 'rejected';

    /**
     * The server was unreachable, refused CloudHub's credentials, would not
     * encrypt, or answered with something that cannot be read. It may work
     * later.
     */
    public const UNAVAILABLE = 'unavailable';

    public function __construct(public readonly string $kind, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
