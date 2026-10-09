<?php
declare(strict_types=1);

namespace CloudHub\Services\Sms;

/**
 * Development only: "send" by appending to a file.
 *
 * Lets two-step verification be exercised on a laptop without a gateway
 * account. The file holds working codes, which is the point -- and why
 * Sms::fromConfig() refuses this driver unless APP_ENV=development, and why it
 * lives in logs/, which no web server layout serves. The number is masked: a
 * developer needs the code, not the digits.
 */
final class LogSms implements SmsSender
{
    public function __construct(private readonly string $file) {}

    public function name(): string
    {
        return 'log';
    }

    public function send(string $to, string $message): string
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new SmsException(SmsException::UNAVAILABLE, 'log: cannot create '.$dir);
        }
        $line = gmdate('c').' to ••'.substr($to, -2).': '.str_replace(["\r", "\n"], ' ', $message).PHP_EOL;
        // No LOCK_EX: Android shared storage does not do advisory locks, and an
        // appended line this short lands whole anyway.
        if (@file_put_contents($this->file, $line, FILE_APPEND) === false) {
            throw new SmsException(SmsException::UNAVAILABLE, 'log: cannot write '.$this->file);
        }
        return 'log-'.bin2hex(random_bytes(6));
    }
}
