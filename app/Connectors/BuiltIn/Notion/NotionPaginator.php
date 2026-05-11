<?php

declare(strict_types=1);

namespace App\Connectors\BuiltIn\Notion;

use App\Connectors\Exceptions\ConnectorApiException;
use App\Connectors\Exceptions\ConnectorAuthException;
use App\Connectors\Exceptions\ConnectorPaginationLimitException;
use Generator;
use Illuminate\Http\Client\Response;

/**
 * v4.5/W2 — `next_cursor` pagination walker for Notion endpoints.
 *
 * Notion's paginated endpoints (search, blocks.children.list,
 * databases.query, users.list) share the same shape:
 *
 *   POST/GET /v1/<resource>
 *     body/query: { start_cursor?: string, page_size?: number, ... }
 *     200: { results: [...], next_cursor: string|null, has_more: bool }
 *
 * Two traversal modes:
 *
 *   - {@see walk()} — eager. Materialises every result into a single
 *     array. Use when the result set is bounded and small (block
 *     children of a single page, users list).
 *
 *   - {@see walkLazy()} — lazy. Yields one batch at a time as a
 *     {@see Generator<list<array<string,mixed>>>}, allowing the caller
 *     to process incrementally AND short-circuit (`break` out of the
 *     `foreach`) without paying for subsequent network calls. Use for
 *     workspace-wide search and any endpoint where the result set is
 *     potentially large (Copilot iter1 finding #3).
 *
 * Exception taxonomy (Copilot iter1 findings #4 + #5):
 *   - HTTP 401 / 403 → {@see ConnectorAuthException}
 *     (the job runner treats this as a permanent failure)
 *   - Any other non-2xx → {@see ConnectorApiException}
 *     (transient; the job runner retries per its backoff policy)
 *   - Non-JSON body → {@see ConnectorApiException}
 *   - `maxPages` reached while `has_more === true` →
 *     {@see ConnectorPaginationLimitException} (caller catches +
 *     records `errors[]` in SyncResult; partial truncation is NOT
 *     a silent success)
 *
 * Tested in isolation in tests/Feature/Connectors/NotionPaginatorTest.php.
 */
final class NotionPaginator
{
    /**
     * Eager traversal — materialise the full result set into one list.
     *
     * Implemented as a thin wrapper over {@see walkLazy()} so the two
     * code paths share the same exception semantics. Prefer
     * `walkLazy()` for any potentially-large endpoint.
     *
     * @param  \Closure(?string $cursor): Response  $fetch
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
     * Lazy traversal — yield one batch at a time. The caller drives
     * the loop, so an incremental sync can break out as soon as a
     * batch contains entries older than the watermark (Notion search
     * sorts by `last_edited_time` desc, so subsequent batches are
     * guaranteed to be older).
     *
     * Memory characteristics: only one batch (≤ `page_size` rows)
     * lives in PHP memory at any moment, regardless of total result
     * set size. Compare to {@see walk()} which holds every result
     * in memory simultaneously.
     *
     * @param  \Closure(?string $cursor): Response  $fetch
     * @return Generator<int, list<array<string,mixed>>>
     */
    public function walkLazy(\Closure $fetch, int $maxPages = 100): Generator
    {
        $cursor = null;
        $page = 0;
        $accumulated = [];

        do {
            $response = $fetch($cursor);
            if (! $response instanceof Response) {
                throw new \RuntimeException(
                    'NotionPaginator: fetch closure must return an Illuminate Http Response instance.'
                );
            }

            if (! $response->successful()) {
                $body = substr((string) $response->body(), 0, 200);
                $message = sprintf('Notion API call failed: HTTP %d %s', $response->status(), $body);

                // Finding #4 — auth vs api exception split. 401 / 403
                // are credential rejections (operator action required);
                // every other status is a transient API failure
                // (job-runner retries per its backoff policy).
                if ($response->status() === 401 || $response->status() === 403) {
                    throw new ConnectorAuthException($message);
                }

                throw new ConnectorApiException($message);
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                throw new ConnectorApiException('Notion API returned non-JSON body.');
            }

            $batch = [];
            foreach (($payload['results'] ?? []) as $row) {
                if (is_array($row)) {
                    $batch[] = $row;
                    $accumulated[] = $row;
                }
            }

            yield $batch;

            $hasMore = (bool) ($payload['has_more'] ?? false);
            $next = $payload['next_cursor'] ?? null;
            $cursor = (is_string($next) && $next !== '') ? $next : null;

            if (! $hasMore || $cursor === null) {
                return;
            }

            $page++;
            if ($page >= $maxPages && $hasMore) {
                // Finding #5 — never silently truncate. Surface the
                // truncation as a typed exception carrying the partial
                // results so the connector can record an `errors[]`
                // entry on the SyncResult.
                throw new ConnectorPaginationLimitException(
                    partialResults: $accumulated,
                    maxPages: $maxPages,
                );
            }
        } while (true);
    }
}
