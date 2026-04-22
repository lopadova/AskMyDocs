<?php

declare(strict_types=1);

namespace App\Services\Kb\Canonical;

/**
 * Extract Obsidian-style `[[slug]]` wikilinks from markdown prose.
 *
 * - strips fenced code blocks and inline code spans first so code samples
 *   don't leak false positives into the graph.
 * - returns a deduplicated, order-preserving list of valid slugs
 *   (lowercase, digits, hyphen — same shape as CanonicalParser::SLUG_RE).
 *
 * The output feeds {@see \App\Jobs\CanonicalIndexerJob} which upserts the
 * referenced slugs as `kb_nodes` and creates `kb_edges`.
 */
class WikilinkExtractor
{
    private const SLUG_RE = '/^[a-z0-9][a-z0-9\-]*$/';
    private const LINK_RE = '/\[\[([^\[\]]+)\]\]/';
    private const CODE_BLOCK_RE = '/```.*?```/s';
    private const INLINE_CODE_RE = '/`[^`]*`/';

    /**
     * @return list<string>
     */
    public function extract(string $markdown): array
    {
        if ($markdown === '') {
            return [];
        }

        $cleaned = $this->stripCode($markdown);
        if ($cleaned === '') {
            return [];
        }

        if (preg_match_all(self::LINK_RE, $cleaned, $matches) === false) {
            return [];
        }

        return $this->dedupeValidSlugs($matches[1]);
    }

    private function stripCode(string $markdown): string
    {
        $noBlocks = preg_replace(self::CODE_BLOCK_RE, '', $markdown) ?? '';
        return preg_replace(self::INLINE_CODE_RE, '', $noBlocks) ?? '';
    }

    /**
     * @param  list<string>  $candidates
     * @return list<string>
     */
    private function dedupeValidSlugs(array $candidates): array
    {
        $seen = [];
        $out = [];
        foreach ($candidates as $candidate) {
            $slug = $this->normalizeSlug($candidate);
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

    private function normalizeSlug(string $candidate): ?string
    {
        $trimmed = trim($candidate);
        if ($trimmed === '') {
            return null;
        }
        if (preg_match(self::SLUG_RE, $trimmed) !== 1) {
            return null;
        }
        return $trimmed;
    }
}
