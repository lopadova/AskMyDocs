<?php

declare(strict_types=1);

namespace App\Services\Invite\Support;

/**
 * Crockford Base32 encode/decode over the alphabet
 * `0123456789ABCDEFGHJKMNPQRSTVWXYZ` (no I L O U).
 *
 * Used by signed codes so the encoded body+signature are stable under
 * CodeNormalizer: the output is already uppercase, contains none of the
 * folded confusables, and uses no separator characters that normalize()
 * strips. Encoding the signed payload this way is what lets a signed code go
 * through the same normalize → lookup path as a random code without
 * corruption.
 */
final class CrockfordBase32
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function encode(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $out .= self::ALPHABET[bindec($chunk)];
        }

        return $out;
    }

    public static function decode(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($encoded) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                return '';
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) < 8) {
                break; // trailing padding bits
            }
            $out .= chr(bindec($chunk));
        }

        return $out;
    }
}
