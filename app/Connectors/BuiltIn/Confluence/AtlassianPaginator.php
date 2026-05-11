<?php

declare(strict_types=1);

namespace App\Connectors\BuiltIn\Confluence;

use App\Connectors\Exceptions\ConnectorApiException;
use App\Connectors\Exceptions\ConnectorAuthException;
use App\Connectors\Exceptions\ConnectorPaginationLimitException;
use Generator;
use Illuminate\Http\Client\Response;

/**
 * v4.5/W5 — Atlassian REST API pagination walker.
 *
 * Atlassian Cloud REST endpoints share a `_links.next` pagination
 * contract (with `start` + `limit` query params on every call):
 *
 *   GET /wiki/rest/api/<resource>?start=0&limit=25
 *     200: {
 *       "results": [ ... ],
 *       "start": 0,
 *       "limit": 25,
 *       "size": 25,
 *       "_links": {
 *         "next": "/wiki/rest/api/<resource>?start=25&limit=25"
 *       }
 *     }
 *
 * The `_links.next` value is a RELATIVE path — Atlassian's convention.
 * The fetch closure receives the absolute URL it should hit; on the
 * first iteration that's the caller-supplied initial URL, on
 * subsequent iterations it's the prefix-joined value of `_links.next`.
 *
 * Two traversal modes mirror {@see \App\Connectors\BuiltIn\Notion\NotionPaginator}:
 *
 *   - {@see walk()} — eager. Materialise every result.
 *   - {@see walkLazy()} — lazy. Yield one batch at a time so the
 *     caller can short-circuit + stay memory-bounded.
 *
 * Exception taxonomy (mirrors NotionPaginator):
 *   - HTTP 401 / 403 → {@see ConnectorAuthException}
 *   - Any other non-2xx → {@see ConnectorApiException}
 *   - Non-JSON body → {@see ConnectorApiException}
 *   - `maxPages` reached while `_links.next` non-null →
 *     {@see ConnectorPaginationLimitException}
 *
 * Tested in isolation in
 * tests/Feature/Connectors/Confluence/AtlassianPaginatorTest.php.
 */
final class AtlassianPaginator
{
    /**
     * Eager traversal — materialise the full result set into one list.
     *
     * @param  \Closure(?string $nextLink): Response  $fetch
     * @return list<array<string,mixed>>
     */
    public function walk(\Closure $fetch, int $maxPages = 100): array
    {
        $out = [];
        foreach ($this->walkLazy($fetch, $maxPages) as $batch) {
            foreach ($batch as $row) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Lazy traversal — yield one batch at a time. The fetch closure
     * receives the absolute URL to hit on each iteration (null on the
     * first call so the closure can use its initial URL).
     *
     * @param  \Closure(?string $nextLink): Response  $fetch
     * @return Generator<int, list<array<string,mixed>>>
     */
    public function walkLazy(\Closure $fetch, int $maxPages = 100): Generator
    {
        $nextLink = null;
        $page = 0;

        do {
            $response = $fetch($nextLink);
            if (! $response instanceof Response) {
                throw new \RuntimeException(
                    'AtlassianPaginator: fetch closure must return an Illuminate Http Response instance.'
                );
            }

            if (! $response->successful()) {
                $body = substr((string) $response->body(), 0, 200);
                $message = sprintf('Atlassian API call failed: HTTP %d %s', $response->status(), $body);

                if ($response->status() === 401 || $response->status() === 403) {
                    throw new ConnectorAuthException($message);
                }

                throw new ConnectorApiException($message);
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                throw new ConnectorApiException('Atlassian API returned non-JSON body.');
            }

            $batch = [];
            foreach (($payload['results'] ?? []) as $row) {
                if (is_array($row)) {
                    $batch[] = $row;
                }
            }

            yield $batch;

            $next = $payload['_links']['next'] ?? null;
            $nextLink = (is_string($next) && $next !== '') ? $next : null;

            if ($nextLink === null) {
                return;
            }

            $page++;
            if ($page >= $maxPages) {
                throw new ConnectorPaginationLimitException(
                    maxPages: $maxPages,
                );
            }
        } while (true);
    }
}
