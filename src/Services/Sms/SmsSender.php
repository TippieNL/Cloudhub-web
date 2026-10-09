<?php
declare(strict_types=1);

namespace CloudHub\Services\Sms;

/**
 * Something that can deliver a text message.
 *
 * Two-step verification talks to nothing else, so changing SMS gateway means
 * writing one class and naming it in Sms::fromConfig() -- the authentication
 * code does not change.
 */
interface SmsSender
{
    /**
     * Hand one message to the gateway.
     *
     * Returning means the gateway accepted it. Whether it then reaches the
     * handset is the gateway's business and is not confirmed here.
     *
     * Implementations must never log the destination number or the message:
     * the message carries a verification code.
     *
     * @param string $to E.164, e.g. "+31612345678"
     * @return string the gateway's reference for the message, '' when it gives none
     * @throws SmsException when the gateway did not accept it
     */
    public function send(string $to, string $message): string;

    /** A short, stable name for logs and diagnostics: "twilio", "webhook", "log". */
    public function name(): string;
}
