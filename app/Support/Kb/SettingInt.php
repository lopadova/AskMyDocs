<?php

declare(strict_types=1);

namespace App\Support\Kb;

/**
 * The ONE reading of a setting that must be a WHOLE number of something —
 * seconds, days, rows, versions. Every consumer of such a setting asks here,
 * so a misconfiguration gets the same answer everywhere and the caller is
 * left with one decision: which default to fall back to, and whether to say
 * so.
 *
 * `is_numeric($v) && (int) $v >= $min` is the shape this replaces, and it is
 * not the same test. It accepts a value that is not a whole number and then
 * TRUNCATES it: `1.9` becomes `1`, `0.5` becomes `0`. For a lock TTL that is
 * the documented hazard — a one-second lease lets the serialization lapse
 * mid-commit and nobody is told, because the value was "numeric" and the
 * clamp was satisfied (SEC-SETTING-SHAPE-001). A setting that cannot be read
 * as what it claims to be is a misconfiguration, and the protective default
 * plus a warning is the honest answer to it, not a silent rounding.
 *
 * `true` is not 1 here either: a boolean in a seconds setting is a wrong
 * type, and PHP's willingness to cast it is exactly what hides that.
 */
final class SettingInt
{
    /**
     * The configured value as a whole number ≥ `$min`, or null when it is
     * not one — a float with a fractional part, a boolean, an array, null,
     * a string that is not an integer literal, or a number below the floor.
     *
     * A float that is exactly whole (`600.0`, as a `.env` cast or a config
     * literal can produce) IS a whole number and is accepted; one that is
     * not (`1.9`) is not, and is never rounded into range.
     */
    public static function whole(mixed $configured, int $min): ?int
    {
        $value = self::toInt($configured);

        return $value !== null && $value >= $min ? $value : null;
    }

    private static function toInt(mixed $configured): ?int
    {
        if (is_int($configured)) {
            return $configured;
        }
        if (is_bool($configured) || $configured === null || is_array($configured) || is_object($configured)) {
            return null;
        }
        if (is_float($configured)) {
            // Whole, finite, and inside the integer range — `(int)` on a float
            // outside it is undefined, not a clamp.
            return is_finite($configured)
                && $configured === floor($configured)
                && $configured >= (float) PHP_INT_MIN
                && $configured <= (float) PHP_INT_MAX
                    ? (int) $configured
                    : null;
        }
        if (is_string($configured)) {
            $value = filter_var(trim($configured), FILTER_VALIDATE_INT);

            return $value === false ? null : $value;
        }

        return null;
    }
}
