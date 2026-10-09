<?php
declare(strict_types=1);

namespace CloudHub\Services\Mail;

/**
 * Something that can deliver an email.
 *
 * Two-step verification talks to nothing else, so changing how mail leaves
 * the server means writing one class and naming it in Mail::fromConfig() --
 * the authentication code does not change.
 */
interface MailSender
{
    /**
     * Hand one plain-text message to the mail server.
     *
     * Returning means the server accepted it for delivery. Whether it then
     * reaches the inbox is the mail system's business and is not confirmed
     * here.
     *
     * Implementations must never log the address, the subject or the body:
     * the body carries a verification code.
     *
     * @return string the Message-ID the message went out with
     * @throws MailException when the server did not accept it
     */
    public function send(string $to, string $subject, string $body): string;

    /** A short, stable name for logs and diagnostics: "smtp", "log". */
    public function name(): string;
}
