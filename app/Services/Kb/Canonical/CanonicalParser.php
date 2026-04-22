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
 *   parse()    → null  when no `---\n...\n---` frontmatter block at the top
 *              → DTO   otherwise (even if YAML is malformed — parseErrors
 *                      populated, so the caller can log)
 *   validate() → ValidationResult with per-field error lists
 *
 * The two methods are separate so the caller can distinguish
 * "not a canonical doc" (null parse result) from "canonical intent, invalid"
 * (DTO with errors). DocumentIngestor uses this distinction to degrade
 * gracefully (R4): invalid frontmatter does NOT fail ingestion — the doc is
 * ingested as non-canonical and the validation errors are logged.
 */
class CanonicalParser
{
    /**
     * Slug shape — mirrored from {@see WikilinkExtractor::SLUG_RE}. Kept in
     * sync deliberately: wikilink targets and frontmatter slugs share the
     * same namespace.
     */
    private const SLUG_RE = '/^[a-z0-9][a-z0-9\-]*$/';

    /**
     * Frontmatter block detection — must begin with `---` on line 1 and
     * close with another `---` on its own line. Captures inner YAML and
     * trailing body.
     */
    private const FRONTMATTER_RE = '/\A---\r?\n(.*?)\r?\n---\r?\n?(.*)\z/s';

    public function parse(string $markdown): ?CanonicalParsedDocument
    {
        if (! preg_match(self::FRONTMATTER_RE, $markdown, $m)) {
            return null;
        }

        [$yamlRaw, $bodyRaw] = [$m[1], $m[2]];
        $body = ltrim($bodyRaw, "\r\n");

        $frontmatter = [];
        $parseErrors = [];
        try {
            $parsed = Yaml::parse($yamlRaw);
            if (is_array($parsed)) {
                $frontmatter = $parsed;
            } else {
                $parseErrors[] = 'Frontmatter YAML did not decode to a map.';
            }
        } catch (ParseException $e) {
            $parseErrors[] = 'YAML parse error: ' . trim($e->getMessage());
        }

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

        if ($doc->slug === null || $doc->slug === '') {
            $errors['slug'][] = 'Missing required field `slug`.';
        } elseif (preg_match(self::SLUG_RE, $doc->slug) !== 1) {
            $errors['slug'][] = "Slug `{$doc->slug}` does not match /[a-z0-9][a-z0-9-]*/.";
        }

        if ($doc->type === null) {
            $errors['type'][] = 'Missing or invalid `type`. Must be one of the 9 canonical types.';
        }

        if ($doc->status === null) {
            $errors['status'][] = 'Missing or invalid `status`. Must be one of draft/review/accepted/superseded/deprecated/archived.';
        }

        if ($doc->retrievalPriority < 0 || $doc->retrievalPriority > 100) {
            $errors['retrieval_priority'][] = "retrieval_priority must be in [0, 100]; got {$doc->retrievalPriority}.";
        }

        return $errors === [] ? ValidationResult::valid() : ValidationResult::invalid($errors);
    }

    // ---------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------

    private function resolveType(array $frontmatter): ?CanonicalType
    {
        $raw = $frontmatter['type'] ?? null;
        return is_string($raw) ? CanonicalType::tryFrom($raw) : null;
    }

    private function resolveStatus(array $frontmatter): ?CanonicalStatus
    {
        $raw = $frontmatter['status'] ?? null;
        return is_string($raw) ? CanonicalStatus::tryFrom($raw) : null;
    }

    private function stringOrNull(array $frontmatter, string $key): ?string
    {
        $v = $frontmatter[$key] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    private function resolveInt(array $frontmatter, string $key, int $default): int
    {
        $v = $frontmatter[$key] ?? null;
        return is_int($v) ? $v : (is_numeric($v) ? (int) $v : $default);
    }

    /**
     * Normalize a YAML list-of-strings, dropping non-strings and empties.
     *
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
            if (is_string($v) && $v !== '') {
                $out[] = $v;
            }
        }
        return $out;
    }

    /**
     * Extract slugs from a frontmatter list that may contain:
     *   - plain strings: "module-cache"
     *   - wikilink strings: "[[module-cache]]"
     *   - YAML-unquoted wikilinks which parse to nested arrays: [[module-cache]]
     *     → YAML sees this as array(array('module-cache'))
     *
     * Any string matching the wikilink shape `[[slug]]` is unwrapped; the
     * resulting slug must match SLUG_RE or it's silently dropped.
     *
     * @return list<string>
     */
    private function extractSlugList(array $frontmatter, string $key): array
    {
        $raw = $frontmatter[$key] ?? null;
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        $seen = [];

        foreach ($raw as $entry) {
            $slug = $this->unwrapSlug($entry);
            if ($slug !== null && ! isset($seen[$slug])) {
                $seen[$slug] = true;
                $out[] = $slug;
            }
        }
        return $out;
    }

    private function unwrapSlug(mixed $entry): ?string
    {
        if (is_string($entry)) {
            $s = trim($entry);
            // strip [[...]] wrapper if present
            if (preg_match('/^\[\[([^\]]+)\]\]$/', $s, $m)) {
                $s = trim($m[1]);
            }
            return preg_match(self::SLUG_RE, $s) === 1 ? $s : null;
        }
        // YAML `[[foo]]` unquoted → array<array<string>>
        if (is_array($entry)) {
            foreach ($entry as $inner) {
                if (is_array($inner)) {
                    foreach ($inner as $candidate) {
                        if (is_string($candidate) && preg_match(self::SLUG_RE, trim($candidate)) === 1) {
                            return trim($candidate);
                        }
                    }
                } elseif (is_string($inner) && preg_match(self::SLUG_RE, trim($inner)) === 1) {
                    return trim($inner);
                }
            }
        }
        return null;
    }
}
