<?php

namespace App\Support;

/**
 * A seller's Lipa Namba, normalised once and in one place.
 *
 * "Lipa Namba" is the till number a Tanzanian merchant publishes so customers
 * can pay them directly — Lipa kwa M-Pesa, Tigo Pesa, Airtel Money and
 * HaloPesa all issue one. Sellers type them inconsistently: `123456`,
 * `12 34 56`, `+255 764 224 477`, `0764-224-477`. Every consumer needs the
 * same answer to "is this payable, and what should the customer be shown?",
 * so the rules live here rather than being re-derived at each call site.
 *
 * Deliberately narrower than a free-text field and deliberately wider than a
 * phone number: a till number is five to nine digits, while a merchant using
 * their own mobile-money line gives nine to twelve. Rejecting either shape
 * would lock out real sellers, so the accepted range covers both and letters
 * are refused outright — a Lipa Namba containing letters is a typo, and the
 * customer would be unable to dial it.
 */
class LipaNamba
{
    /** Shortest real till number in circulation. */
    public const MIN_DIGITS = 5;

    /** Longest useful form: 255 plus a nine-digit mobile line. */
    public const MAX_DIGITS = 12;

    /**
     * Reduce a seller-entered value to bare digits, or null when it cannot be
     * a Lipa Namba at all.
     *
     * Separators sellers use for readability — spaces, dashes, brackets, a
     * leading plus — are stripped rather than rejected. Anything containing a
     * letter is returned as null, because it cannot be keyed into a handset.
     */
    public static function normalise(?string $raw): ?string
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        // Letters mean the entry was mistyped; it is not recoverable here.
        if (preg_match('/[a-z]/i', $raw)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if (strlen($digits) < self::MIN_DIGITS || strlen($digits) > self::MAX_DIGITS) {
            return null;
        }

        return $digits;
    }

    /** Whether a seller-entered value is usable as a Lipa Namba. */
    public static function isValid(?string $raw): bool
    {
        return self::normalise($raw) !== null;
    }

    /**
     * The number as a customer should read it off the screen.
     *
     * Grouped in threes from the *right*, so the groups line up the way people
     * read numbers aloud. Grouping from the left instead leaves whatever is
     * left over stranded at the end — an eight-digit till came out as
     * "876 543 21", which is harder to read than not grouping at all.
     *
     * A leftover single digit at the front is folded into the next group for
     * the same reason: "0 764 224 477" is worse than "0764 224 477".
     *
     * Short numbers are left alone — grouping a five-digit till makes it
     * harder to read, not easier.
     */
    public static function pretty(?string $raw): ?string
    {
        $digits = self::normalise($raw);

        if ($digits === null) {
            return null;
        }

        if (strlen($digits) <= 6) {
            return $digits;
        }

        // str_split pads the last group; reversing first makes it pad the
        // first group instead, which is the one allowed to be short.
        $groups = array_reverse(array_map('strrev', str_split(strrev($digits), 3)));

        if (count($groups) > 1 && strlen($groups[0]) === 1) {
            $groups[1] = $groups[0] . $groups[1];
            array_shift($groups);
        }

        return implode(' ', $groups);
    }
}
