<?php

declare(strict_types=1);

namespace App\Services\Kb\Canonical;

use App\Support\Canonical\CanonicalStatus;
use App\Support\Canonical\CanonicalType;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Parses canonical markdown into a typed {@see CanonicalParsedDocument} and
 * validates the extracted fields against the canonical schema.
 *
 * Contract:
 *   parse()    → null   when no `---\n...\n---` frontmatter block at the top
 *              → DTO    otherwise (even if YAML is malformed — parseErrors
 *                       populated, so the caller can log)
 *   validate() → ValidationResult with per-field error lists
 *
 * DocumentIngestor uses the parse/validate split to degrade gracefully:
 * invalid frontmatter does NOT fail ingestion — the doc is ingested as
 * non-canonical and the validation errors are logged (R4).
 */
class CanonicalParser
{
    private const SLUG_RE = '/^[a-z0-9][a-z0-9\-]*$/';
    private const FRONTMATTER_RE = '/\A---\r?\n(.*?)\r?\n---\r?\n?(.*)\z/s';
    private const WIKILINK_WRAPPER_RE = '/^\[\[([^\]]+)\]\]$/';

    public function parse(string $markdown): ?CanonicalParsedDocument
    {
        if (preg_match(self::FRONTMATTER_RE, $markdown, $matches) !== 1) {
            return null;
        }

        [$frontmatter, $parseErrors] = $this->decodeYaml($matches[1]);
        $body = ltrim($matches[2], "\r\n");

        return new CanonicalParsedDocument(
            frontmatter: $frontmatter,
            body: $body,
            type: $this->resolveType($frontmatter),
            status: $this->resolveStatus($frontmatter),
            slug: $this->stringOrNull($frontmatter, 'slug'),
            docId: $this->stringOrNull($frontmatter, 'id'),
            retrievalPriority: $this->resolveInt($frontmatter, 'retrieval_priority', 50),
            relatedSlugs: $this->extractSlugList($frontmatter, 'related'),
            supersedesSlugs: $this->extractSlugList($frontmatter, 'supersedes'),
            supersededBySlugs: $this->extractSlugList($frontmatter, 'superseded_by'),
            tags: $this->normalizeStringList($frontmatter['tags'] ?? []),
            owners: $this->normalizeStringList($frontmatter['owners'] ?? []),
            summary: $this->stringOrNull($frontmatter, 'summary'),
            parseErrors: $parseErrors,
        );
    }

    public function validate(CanonicalParsedDocument $doc): ValidationResult
    {
        $errors = [];

        if ($doc->parseErrors !== []) {
            $errors['frontmatter'] = $doc->parseErrors;
        }
        $errors = array_merge_recursive($errors, $this->validateSlug($doc));
        $errors = array_merge_recursive($errors, $this->validateType($doc));
        $errors = array_merge_recursive($errors, $this->validateStatus($doc));
        $errors = array_merge_recursive($errors, $this->validateRetrievalPriority($doc));

        if ($errors === []) {
            return ValidationResult::valid();
        }
        return ValidationResult::invalid($errors);
    }

    // -----------------------------------------------------------------
    // YAML decoding
    // -----------------------------------------------------------------

    /**
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function decodeYaml(string $yaml): array
    {
        try {
            $parsed = Yaml::parse($yaml);
        } catch (ParseException $e) {
            return [[], ['YAML parse error: ' . trim($e->getMessage())]];
        }

        if (! is_array($parsed)) {
            return [[], ['Frontmatter YAML did not decode to a map.']];
        }
        return [$parsed, []];
    }

    // -----------------------------------------------------------------
    // Per-field validators (each returns ['field' => ['error', ...]] or [])
    // -----------------------------------------------------------------

    /** @return array<string, list<string>> */
    private function validateSlug(CanonicalParsedDocument $doc): array
    {
        if ($doc->slug === null || $doc->slug === '') {
            return ['slug' => ['Missing required field `slug`.']];
        }
        if (preg_match(self::SLUG_RE, $doc->slug) !== 1) {
            return ['slug' => ["Slug `{$doc->slug}` does not match /[a-z0-9][a-z0-9-]*/."]];
        }
        return [];
    }

    /** @return array<string, list<string>> */
    private function validateType(CanonicalParsedDocument $doc): array
    {
        if ($doc->type === null) {
            return ['type' => ['Missing or invalid `type`. Must be one of the 9 canonical types.']];
        }
        return [];
    }

    /** @return array<string, list<string>> */
    private function validateStatus(CanonicalParsedDocument $doc): array
    {
        if ($doc->status === null) {
            return ['status' => ['Missing or invalid `status`. Must be one of draft/review/accepted/superseded/deprecated/archived.']];
        }
        return [];
    }

    /** @return array<string, list<string>> */
    private function validateRetrievalPriority(CanonicalParsedDocument $doc): array
    {
        if ($doc->retrievalPriority < 0 || $doc->retrievalPriority > 100) {
            return ['retrieval_priority' => ["retrieval_priority must be in [0, 100]; got {$doc->retrievalPriority}."]];
        }
        return [];
    }

    // -----------------------------------------------------------------
    // Frontmatter field resolution helpers
    // -----------------------------------------------------------------

    /** @param array<string, mixed> $frontmatter */
    private function resolveType(array $frontmatter): ?CanonicalType
    {
        $raw = $frontmatter['type'] ?? null;
        if (! is_string($raw)) {
            return null;
        }
        return CanonicalType::tryFrom($raw);
    }

    /** @param array<string, mixed> $frontmatter */
    private function resolveStatus(array $frontmatter): ?CanonicalStatus
    {
        $raw = $frontmatter['status'] ?? null;
        if (! is_string($raw)) {
            return null;
        }
        return CanonicalStatus::tryFrom($raw);
    }

    /** @param array<string, mixed> $frontmatter */
    private function stringOrNull(array $frontmatter, string $key): ?string
    {
        $v = $frontmatter[$key] ?? null;
        if (! is_string($v) || $v === '') {
            return null;
        }
        return $v;
    }

    /** @param array<string, mixed> $frontmatter */
    private function resolveInt(array $frontmatter, string $key, int $default): int
    {
        $v = $frontmatter[$key] ?? null;
        if (is_int($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v;
        }
        return $default;
    }

    /**
     * @param  mixed  $input
     * @return list<string>
     */
    private function normalizeStringList(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }
        $out = [];
        foreach ($input as $v) {
            if (! is_string($v) || $v === '') {
                continue;
            }
            $out[] = $v;
        }
        return $out;
    }

    /**
     * Extract slugs from a YAML list that may contain plain strings,
     * "[[slug]]" wrapped strings, or YAML-unquoted [[slug]] nested arrays.
     *
     * @param  array<string, mixed>  $frontmatter
     * @return list<string>
     */
    private function extractSlugList(array $frontmatter, string $key): array
    {
        $raw = $frontmatter[$key] ?? null;
        if (! is_array($raw)) {
            return [];
        }
        $seen = [];
        $out = [];
        foreach ($raw as $entry) {
            $slug = $this->unwrapSlug($entry);
            if ($slug === null) {
                continue;
            }
            if (isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $out[] = $slug;
        }
        return $out;
    }

    private function unwrapSlug(mixed $entry): ?string
    {
        if (is_string($entry)) {
            return $this->unwrapSlugFromString($entry);
        }
        if (is_array($entry)) {
            return $this->unwrapSlugFromNestedArray($entry);
        }
        return null;
    }

    private function unwrapSlugFromString(string $s): ?string
    {
        $trimmed = trim($s);
        if (preg_match(self::WIKILINK_WRAPPER_RE, $trimmed, $m) === 1) {
            $trimmed = trim($m[1]);
        }
        if (preg_match(self::SLUG_RE, $trimmed) !== 1) {
            return null;
        }
        return $trimmed;
    }

    /**
     * YAML parses `[[foo]]` unquoted as a nested array structure. Flatten
     * one level and look for a slug-shaped string anywhere inside.
     */
    private function unwrapSlugFromNestedArray(array $entry): ?string
    {
        foreach ($entry as $inner) {
            $slug = $this->firstSlugInValue($inner);
            if ($slug !== null) {
                return $slug;
            }
        }
        return null;
    }

    private function firstSlugInValue(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if (preg_match(self::SLUG_RE, $trimmed) === 1) {
                return $trimmed;
            }
            return null;
        }
        if (! is_array($value)) {
            return null;
        }
        foreach ($value as $deeper) {
            if (! is_string($deeper)) {
                continue;
            }
            $trimmed = trim($deeper);
            if (preg_match(self::SLUG_RE, $trimmed) === 1) {
                return $trimmed;
            }
        }
        return null;
    }
}
