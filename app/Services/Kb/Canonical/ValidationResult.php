<?php

declare(strict_types=1);

namespace App\Services\Kb\Canonical;

/**
 * Result of {@see CanonicalParser::validate()}.
 *
 * `errors` is keyed by frontmatter field name (or 'frontmatter' for
 * structural YAML errors). Each entry is a list of human-readable strings.
 */
final class ValidationResult
{
    /**
     * @param  array<string, list<string>>  $errors  key = field, value = list of errors
     */
    public function __construct(
        public readonly bool $valid,
        public readonly array $errors = [],
    ) {
    }

    public static function valid(): self
    {
        return new self(true);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function invalid(array $errors): self
    {
        return new self(false, $errors);
    }
}
