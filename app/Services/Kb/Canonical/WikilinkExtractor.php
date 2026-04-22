<?php

declare(strict_types=1);

namespace App\Services\Kb\Canonical;

/**
 * Extract Obsidian-style `[[slug]]` wikilinks from markdown prose.
 *
 * - strips fenced code blocks (```...```) and inline code spans (`...`)
 *   so code samples don't leak false positives into the graph.
 * - returns a **deduplicated, order-preserving** list of valid slugs.
 * - accepts slugs that match `[a-z0-9][a-z0-9\-]*` (lowercase, digits, hyphen).
 *   Rejects uppercase, whitespace, or anything starting with `_`/`-`.
 *
 * The output feeds {@see \App\Jobs\CanonicalIndexerJob} which upserts the
 * referenced slugs as `kb_nodes` (possibly dangling) and creates
 * `kb_edges` with provenance='wikilink'.
 */
class WikilinkExtractor
{
    /**
     * Single slug shape — keep in sync with CanonicalParser regex.
     */
    private const SLUG_RE = '/^[a-z0-9][a-z0-9\-]*$/';

    /**
     * Wikilink shape — matches the *innermost* [[...]] so `[[[x]]]` → `x`.
     */
    private const LINK_RE = '/\[\[([^\[\]]+)\]\]/';

    /**
     * @return list<string> deduplicated, order-preserving list of slugs
     */
    public function extract(string $markdown): array
    {
        if ($markdown === '') {
            return [];
        }

        // strip fenced code blocks first (they can contain backticks)
        $stripped = preg_replace('/```.*?```/s', '', $markdown);
        // then strip inline code spans
        $stripped = preg_replace('/`[^`]*`/', '', $stripped ?? '');

        if ($stripped === null || $stripped === '') {
            return [];
        }

        $seen = [];
        $out = [];
        if (preg_match_all(self::LINK_RE, $stripped, $matches)) {
            foreach ($matches[1] as $target) {
                $target = trim($target);
                if ($target === '' || isset($seen[$target])) {
                    continue;
                }
                if (preg_match(self::SLUG_RE, $target) === 1) {
                    $seen[$target] = true;
                    $out[] = $target;
                }
            }
        }
        return $out;
    }
}
