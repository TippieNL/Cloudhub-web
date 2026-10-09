<?php
declare(strict_types=1);

namespace CloudHub\Services;

/**
 * Phone numbers for two-step verification, in E.164: "+" and 7 to 15 digits.
 *
 * Only the international form is accepted. Turning a local number into one
 * needs the country's own trunk rules -- a leading 0 is dropped in the
 * Netherlands and kept in Italy -- and a guess that is wrong sends a code to a
 * stranger. People type numbers with spaces, dashes, dots and brackets, and
 * write "00" for "+", so those are tidied away; the "(0)" printed after the
 * country code on many European business cards is dropped, as it is never
 * dialled from abroad.
 */
final class PhoneNumber
{
    /** The number in E.164, or null when it is not one. */
    public static function normalize(string $input): ?string
    {
        $number = trim($input);
        if ($number === '' || strlen($number) > 40) return null;
        $number = str_replace('(0)', '', $number);
        $number = preg_replace('/[\s().\/-]+/', '', $number) ?? '';
        if (str_starts_with($number, '00')) $number = '+'.substr($number, 2);
        return preg_match('/^\+[1-9][0-9]{6,14}$/', $number) === 1 ? $number : null;
    }

    /**
     * The last two digits, which is all any answer, message or log line
     * shows: enough to tell which of your phones it is, and nothing that
     * identifies the number to anybody else.
     */
    public static function ending(string $e164): string
    {
        return substr($e164, -2);
    }
}
