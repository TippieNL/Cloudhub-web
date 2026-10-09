<?php
declare(strict_types=1);

namespace CloudHub\Services;

use CloudHub\Services\Mail\MailException;

/**
 * Why a two-step verification request was refused, in terms a client acts on.
 *
 * The HTTP status is the exception code, so anything that only knows
 * RuntimeException still answers sensibly; two_factor_try() in the front
 * controller additionally sends the stable error code, the details (attempts
 * left, how long to wait) and a Retry-After when there is a wait.
 *
 * Every message here is written for the person at the keyboard, and none of
 * them says whether an account or an email address exists: they are only ever
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
        return new self('VALIDATION_FAILED', 'Enter the '.$length.'-digit code from the email.', 422);
    }

    public static function recoveryWrong(): self
    {
        return new self('TWO_FACTOR_RECOVERY_INVALID', 'That recovery code is not valid, or has already been used.', 422, [], 0, true);
    }

    public static function recoveryNotAllowed(): self
    {
        return new self('TWO_FACTOR_RECOVERY_NOT_ALLOWED', 'A new email address can only be confirmed with the code sent to it.', 422);
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

    public static function emailLimit(int $retryAfter): self
    {
        return new self('TWO_FACTOR_EMAIL_LIMIT', 'Too many codes were emailed. Try again in '.self::wait($retryAfter).', or use a recovery code.', 429,
            ['retryAfter' => $retryAfter], $retryAfter);
    }

    public static function emailNotConfigured(): self
    {
        return new self('EMAIL_NOT_CONFIGURED', 'This server cannot send email, so no code can be sent. Use a recovery code, or ask your administrator.', 503);
    }

    /**
     * Two-step verification is on for this account but there is no address
     * to send its code to: it was turned on when codes went by text message.
     */
    public static function noEmailAddress(): self
    {
        return new self('TWO_FACTOR_NO_EMAIL', 'Sign-in codes now go by email, and this account has no address for them yet. Use a recovery code, or ask your administrator.', 409);
    }

    public static function fromMail(MailException $e, int $retryAfter): self
    {
        return match ($e->kind) {
            MailException::REJECTED => new self('EMAIL_REJECTED', 'The mail server refused that address. Check it and try again.', 422),
            default => new self('EMAIL_UNAVAILABLE', 'The email could not be sent just now. If a code arrives anyway it will work; otherwise try again in '
                .self::wait($retryAfter).', or use a recovery code.', 503, ['retryAfter' => $retryAfter], $retryAfter),
        };
    }

    public static function wrongPassword(): self
    {
        return new self('FORBIDDEN', 'The current password is incorrect', 403);
    }

    public static function emailInvalid(): self
    {
        return new self('VALIDATION_FAILED', 'Enter a valid email address, for example name@example.com.', 422);
    }

    public static function sameEmail(): self
    {
        return new self('VALIDATION_FAILED', 'That is already the address codes are sent to.', 422);
    }

    public static function notEnabled(): self
    {
        return new self('CONFLICT', 'Two-step verification is not turned on for this account.', 409);
    }

    public static function unknownAction(): self
    {
        return new self('VALIDATION_FAILED', 'action must be one of email, disable, recovery', 422);
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
