<?php
declare(strict_types=1);

namespace CloudHub\Services\Mail;

/**
 * The socket under an SMTP conversation.
 *
 * SmtpMailer speaks the protocol; this only moves lines. Tests hand it a
 * scripted fake that answers the way a real server does -- a refusal, a
 * server that will not encrypt, one that hangs up -- without a network.
 */
interface SmtpConnection
{
    /**
     * Connect, encrypting from the first byte when $implicitTls (port 465).
     * $timeoutSeconds bounds the whole conversation, not each step.
     *
     * @throws MailException when no connection could be made
     */
    public function open(string $host, int $port, bool $implicitTls, int $timeoutSeconds): void;

    /**
     * Upgrade the connection to TLS after STARTTLS, verifying the server's
     * certificate against its host name.
     *
     * @throws MailException when the handshake or the verification fails
     */
    public function enableTls(): void;

    /** Send one command; the line ending is added here. */
    public function writeLine(string $line): void;

    /** Send the message body, already CRLF-terminated and dot-stuffed. */
    public function writeRaw(string $data): void;

    /**
     * One line of the server's reply, without its line ending.
     *
     * @throws MailException when the server hung up or the time ran out
     */
    public function readLine(): string;

    public function close(): void;
}
