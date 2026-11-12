<?php

declare(strict_types=1);

namespace App\Support\Phone;

use App\Support\Numbers\Digits;

/** Bangladeshi mobile numbers to E.164 (+8801XXXXXXXXX). Anything else is invalid (null). */
final class BdPhone
{
    public static function toE164(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $digits = (string) preg_replace('/\D/', '', Digits::toLatin($input));

        $national = match (true) {
            strlen($digits) === 11 && str_starts_with($digits, '01') => substr($digits, 1),
            strlen($digits) === 13 && str_starts_with($digits, '8801') => substr($digits, 3),
            strlen($digits) === 15 && str_starts_with($digits, '008801') => substr($digits, 5),
            strlen($digits) === 10 && str_starts_with($digits, '1') => $digits,
            default => null,
        };

        return $national !== null && preg_match('/^1[3-9]\d{8}$/', $national) === 1 ? '+880'.$national : null;
    }

    /**
     * LIKE pattern for a phone typed into a search box, against stored `+880…` numbers. Input written as the
     * start of a number (0…, 880…, +880…) matches by prefix at any length: "01" → `+8801%`, "019" → `+88019%`.
     * Bare digits (the last digits a customer remembers) match anywhere from three digits. Null when the
     * input is not phone-like.
     */
    public static function searchPattern(string $input): ?string
    {
        $latin = Digits::toLatin(trim($input));
        if (preg_match('/^\+?[\d\s\-().]+$/', $latin) !== 1) {
            return null;
        }
        $digits = (string) preg_replace('/\D/', '', $latin);
        if (preg_match('/^(?:00880|880|0)(\d*)$/', $digits, $m) === 1) {
            return '+880'.ltrim($m[1], '0').'%';
        }

        return strlen($digits) >= 3 ? '%'.$digits.'%' : null;
    }

    /** Whether the input is written as the start of a phone number (0…, 880…, +880…), not a customer number. */
    public static function looksLikePhoneStart(string $input): bool
    {
        return preg_match('/^\s*(\+|0|880)/', Digits::toLatin($input)) === 1;
    }
}
