<?php

declare(strict_types=1);

namespace App\Support;

final class SupportedLocale
{
    public static function fallback(): string
    {
        $configured = self::canonicalize((string) config('agent.locales.fallback', config('app.locale', 'en')));

        if ($configured !== null && self::isSupported($configured)) {
            return $configured;
        }

        foreach ((array) config('agent.locales.supported', ['en', 'it']) as $candidate) {
            $normalized = self::canonicalize(is_string($candidate) ? $candidate : '');
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return 'en';
    }

    public static function normalize(?string $locale): string
    {
        $normalized = self::canonicalize($locale ?? '');

        return $normalized !== null && self::isSupported($normalized)
            ? $normalized
            : self::fallback();
    }

    public static function isSupported(string $locale): bool
    {
        $normalized = self::canonicalize($locale);
        if ($normalized === null) {
            return false;
        }

        $language = strtolower(strtok($normalized, '-') ?: $normalized);

        foreach ((array) config('agent.locales.supported', ['en', 'it']) as $supported) {
            if (! is_string($supported)) {
                continue;
            }

            $candidate = self::canonicalize($supported);
            if ($candidate === null) {
                continue;
            }

            if ($candidate === $normalized || (! str_contains($candidate, '-') && $candidate === $language)) {
                return true;
            }
        }

        return false;
    }

    /** Return the translation-catalog language for a full BCP-47 locale. */
    public static function catalog(string $locale): string
    {
        $normalized = self::normalize($locale);
        $language = strtolower(strtok($normalized, '-') ?: $normalized);

        return in_array($language, self::supportedCatalogs(), true)
            ? $language
            : strtolower(strtok(self::fallback(), '-') ?: self::fallback());
    }

    /** @return list<string> */
    public static function supportedCatalogs(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static function (mixed $locale): ?string {
                if (! is_string($locale)) {
                    return null;
                }

                $canonical = self::canonicalize($locale);
                return $canonical === null ? null : strtolower(strtok($canonical, '-') ?: $canonical);
            },
            (array) config('agent.locales.supported', ['en', 'it']),
        ))));
    }

    private static function canonicalize(string $locale): ?string
    {
        $locale = str_replace('_', '-', trim($locale));
        if ($locale === '' || strlen($locale) > 35
            || preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $locale) !== 1) {
            return null;
        }

        $parts = explode('-', $locale);
        $parts[0] = strtolower($parts[0]);
        foreach (array_slice($parts, 1, null, true) as $index => $part) {
            $parts[$index] = match (true) {
                strlen($part) === 2 && ctype_alpha($part) => strtoupper($part),
                strlen($part) === 4 && ctype_alpha($part) => ucfirst(strtolower($part)),
                default => strtolower($part),
            };
        }

        return implode('-', $parts);
    }
}
