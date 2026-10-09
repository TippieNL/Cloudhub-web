<?php
declare(strict_types=1);

namespace CloudHub\Services;

use CloudHub\Services\Sms\SmsException;

/**
 * Why a two-step verification request was refused, in terms a client acts on.
 *
 * The HTTP status is the exception code, so anything that only knows
 * RuntimeException still answers sensibly; two_factor_try() in the front
 * controller additionally sends the stable error code, the details (attempts
 * left, which number a code went to) and a Retry-After when there is a wait.
 *
 * Every message here is written for the person at the keyboard, and none of
 * them says whether an account or a phone number exists: they are only ever
 * reached by a session that has already proven the account's password.
 */
final class TwoFactorError extends \RuntimeException
{
    /**
     * @param bool $countsAsFailure a wrong code or recovery code, which the
     *             failure limits are there to count; everything else -- an
     *             expired code, a malformed one, a code never sent -- is not a
     *             guess and gives its throttle slot back
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        int $status,
        public readonly array $details = [],
        public readonly int $retryAfter = 0,
        public readonly bool $countsAsFailure = false,
    ) {
        parent::__construct($message, $status);
    }

    public static function signInExpired(): self
    {
        return new self('TWO_FACTOR_EXPIRED', 'Your sign-in timed out. Enter your password again.', 401);
    }

    public static function noAction(): self
    {
        return new self('TWO_FACTOR_NO_PENDING_CHANGE', 'That change has timed out or was replaced. Start again.', 409);
    }

    public static function codeExpired(): self
    {
        return new self('TWO_FACTOR_CODE_EXPIRED', 'That code has expired or was replaced by a newer one. Request a new code.', 410);
    }

    public static function noCodeSent(): self
    {
        return new self('TWO_FACTOR_CODE_NOT_SENT', 'No code has been sent yet. Request a code first.', 409);
    }

    public static function codeWrong(int $attemptsLeft): self
    {
        $message = $attemptsLeft > 0
            ? 'That code is not right. '.$attemptsLeft.' '.($attemptsLeft === 1 ? 'try' : 'tries').' left before it stops working.'
            : 'That code is not right, and it has now stopped working. Request a new code.';
        return new self('TWO_FACTOR_CODE_INVALID', $message, 422, ['attemptsLeft' => $attemptsLeft], 0, true);
    }

    public static function tooManyAttempts(): self
    {
        return new self('TWO_FACTOR_CODE_EXHAUSTED', 'Too many wrong codes. Request a new code.', 410);
    }

    public static function malformedCode(int $length): self
    {
        return new self('VALIDATION_FAILED', 'Enter the '.$length.'-digit code from the text message.', 422);
    }

    public static function recoveryWrong(): self
    {
        return new self('TWO_FACTOR_RECOVERY_INVALID', 'That recovery code is not valid, or has already been used.', 422, [], 0, true);
    }

    public static function recoveryNotAllowed(): self
    {
        return new self('TWO_FACTOR_RECOVERY_NOT_ALLOWED', 'A new number can only be confirmed with the code sent to it.', 422);
    }

    public static function locked(int $retryAfter): self
    {
        return new self('TWO_FACTOR_LOCKED', 'Too many wrong codes. Try again in '.self::wait($retryAfter).'.', 429,
            ['retryAfter' => $retryAfter], $retryAfter);
    }

    public static function resendCooldown(int $retryAfter): self
    {
        return new self('TWO_FACTOR_RESEND_COOLDOWN', 'A code was just sent. You can ask for another in '.self::wait($retryAfter).'.', 429,
            ['retryAfter' => $retryAfter], $retryAfter);
    }

    public static function smsLimit(int $retryAfter): self
    {
        return new self('TWO_FACTOR_SMS_LIMIT', 'Too many text messages were sent. Try again in '.self::wait($retryAfter).', or use a recovery code.', 429,
            ['retryAfter' => $retryAfter], $retryAfter);
    }

    public static function smsNotConfigured(): self
    {
        return new self('SMS_NOT_CONFIGURED', 'This server cannot send text messages, so no code can be sent. Use a recovery code, or ask your administrator.', 503);
    }

    public static function fromSms(SmsException $e, int $retryAfter): self
    {
        return match ($e->kind) {
            SmsException::REJECTED => new self('SMS_REJECTED', 'That number cannot receive text messages from this server. Check the number and try again.', 422),
            SmsException::NOT_CONFIGURED => self::smsNotConfigured(),
            default => new self('SMS_UNAVAILABLE', 'The text message could not be sent just now. If a code arrives anyway it will work; otherwise try again in '
                .self::wait($retryAfter).', or use a recovery code.', 503, ['retryAfter' => $retryAfter], $retryAfter),
        };
    }

    public static function wrongPassword(): self
    {
        return new self('FORBIDDEN', 'The current password is incorrect', 403);
    }

    public static function phoneInvalid(): self
    {
        return new self('VALIDATION_FAILED', 'Enter the number in international format: + and the country code, for example +31 6 12345678.', 422);
    }

    public static function samePhone(): self
    {
        return new self('VALIDATION_FAILED', 'That is already the number codes are sent to.', 422);
    }

    public static function notEnabled(): self
    {
        return new self('CONFLICT', 'Two-step verification is not turned on for this account.', 409);
    }

    public static function unknownAction(): self
    {
        return new self('VALIDATION_FAILED', 'action must be one of phone, disable, recovery', 422);
    }

    public static function notMigrated(): self
    {
        return new self('NOT_AVAILABLE', 'Two-step verification needs a database update: run php database/migrate.php on the server.', 503);
    }

    private static function wait(int $seconds): string
    {
        if ($seconds < 90) return max(1, $seconds).' second'.($seconds === 1 ? '' : 's');
        $minutes = (int)ceil($seconds / 60);
        return $minutes.' minutes';
    }
}
