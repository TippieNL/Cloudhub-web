<?php
declare(strict_types=1);

namespace CloudHub\Services;

/**
 * Email addresses for two-step verification: one form to store, and a masked
 * one to show.
 *
 * The domain is lower-cased, the local part kept as typed (a few servers do
 * tell case apart there). Anything with whitespace, control characters or
 * angle brackets is refused before PHP's validator sees it, so an address can
 * never carry a second SMTP command or header with it.
 */
final class EmailAddress
{
    public const MAX_LENGTH = 254;

    /** The address as stored, or null when it is not one. */
    public static function normalize(string $input): ?string
    {
        $email = trim($input);
        if ($email === '' || strlen($email) > self::MAX_LENGTH || preg_match('/[\s\x00-\x1F\x7F<>]/', $email) === 1) return null;
        $at = strrpos($email, '@');
        if ($at === false || $at === 0 || $at > 64) return null;
        $email = substr($email, 0, $at).'@'.strtolower(substr($email, $at + 1));
        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email;
    }

    /**
     * Enough to recognise which mailbox a code went to, not enough to learn
     * it: "k•••@example.com".
     */
    public static function mask(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) return '•••';
        return mb_substr(substr($email, 0, $at), 0, 1).'•••'.substr($email, $at);
    }
}
