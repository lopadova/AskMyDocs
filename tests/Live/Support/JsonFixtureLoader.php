<?php

declare(strict_types=1);

namespace Tests\Live\Support;

/**
 * Loads a recorded or hand-crafted JSON fixture from
 * `tests/fixtures/connectors/<provider>/<recorded|hand-crafted>/...`
 * for replay during chunker / frontmatter tests.
 *
 * Hand-crafted fixtures carry `"__source": "hand-crafted-sample"` at
 * the top level so reviewers can tell them apart from recorded
 * fixtures (`"__source": "live-recording"`). Both are stripped at
 * load-time — only the `body` element survives.
 */
final class JsonFixtureLoader
{
    /**
     * @return array<mixed>
     */
    public static function load(string $relativePath): array
    {
        $repoRoot = dirname(__DIR__, 3);
        $full = $repoRoot
            . DIRECTORY_SEPARATOR . 'tests'
            . DIRECTORY_SEPARATOR . 'Fixtures'
            . DIRECTORY_SEPARATOR . 'connectors'
            . DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\');

        if (! is_file($full)) {
            throw new \RuntimeException("Live fixture not found: {$full}");
        }

        $raw = file_get_contents($full);
        if ($raw === false) {
            throw new \RuntimeException("Failed to read live fixture: {$full}");
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException("Live fixture is not a JSON object: {$full}");
        }

        // Hand-crafted samples and recorded fixtures wrap the actual
        // payload in `body`. Strip the wrapper.
        return is_array($decoded['body'] ?? null) ? $decoded['body'] : $decoded;
    }
}
