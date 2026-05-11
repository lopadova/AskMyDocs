<?php

declare(strict_types=1);

namespace App\Connectors\BuiltIn\Jira;

use App\Connectors\Exceptions\ConnectorApiException;
use App\Connectors\Exceptions\ConnectorAuthException;
use App\Connectors\Exceptions\ConnectorPaginationLimitException;
use Generator;
use Illuminate\Http\Client\Response;

/**
 * v4.5/W6 — Pagination walker for Jira Cloud REST endpoints.
 *
 * Jira's pagination is endpoint-dependent — a long-standing wart of
 * the Cloud REST API. Two flavours coexist:
 *
 *   - **Classic offset pagination** (most endpoints, including
 *     `/rest/api/3/project/search` and the legacy `/rest/api/3/search`):
 *     each response carries `startAt`, `maxResults`, `total`, and a
 *     `isLast` boolean. The next call passes `startAt = startAt +
 *     length(items)`.
 *   - **Token pagination** (newer endpoints, including the newer
 *     `/rest/api/3/search/jql` enhanced search): each response carries
 *     `nextPageToken` + `isLast`. The next call passes
 *     `nextPageToken = <token>`.
 *
 * This paginator auto-detects which mode the upstream is using on the
 * first response and locks into that mode for the lifetime of the
 * walk. Callers pass the same fetch closure either way; the closure
 * receives a `?string $cursor` argument that holds the next-page
 * marker (a `startAt` integer encoded as string, or a `nextPageToken`
 * string), null for the first call.
 *
 * Result extraction is also endpoint-dependent — most endpoints stash
 * the rows under a top-level key matching the resource type
 * (`issues`, `values`, `results`, ...). The closure tells us the
 * results key via the optional `$resultsKey` constructor arg;
 * defaults to `issues` (Jira's most common shape).
 *
 * Exception taxonomy (mirrors {@see NotionPaginator}):
 *   - HTTP 401 / 403 → {@see ConnectorAuthException}
 *   - Any other non-2xx → {@see ConnectorApiException}
 *   - Non-JSON body → {@see ConnectorApiException}
 *   - `maxPages` reached while upstream still signals more results →
 *     {@see ConnectorPaginationLimitException}
 */
final class JiraPaginator
{
    public const MODE_AUTO = 'auto';
    public const MODE_OFFSET = 'offset';
    public const MODE_TOKEN = 'token';

    /**
     * @param  string  $resultsKey  The key under which the upstream
     *                              nests the row array (e.g. `issues`,
     *                              `values`, `results`).
     * @param  string  $mode        `auto` to detect from the first
     *                              response, `offset` / `token` to
     *                              force a mode.
     */
    public function __construct(
        private readonly string $resultsKey = 'issues',
        private readonly string $mode = self::MODE_AUTO,
    ) {
    }

    /**
     * Eager traversal — materialise every result into one list.
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
     * Lazy traversal — yield one batch at a time. The fetch closure
     * receives the cursor for the next page (null on first call).
     *
     * @param  \Closure(?string $cursor): Response  $fetch
     * @return Generator<int, list<array<string,mixed>>>
     */
    public function walkLazy(\Closure $fetch, int $maxPages = 100): Generator
    {
        $cursor = null;
        $page = 0;
        $mode = $this->mode === self::MODE_AUTO ? null : $this->mode;
        $startAt = 0;

        do {
            $response = $fetch($cursor);
            if (! $response instanceof Response) {
                throw new \RuntimeException(
                    'JiraPaginator: fetch closure must return an Illuminate Http Response instance.'
                );
            }

            if (! $response->successful()) {
                $body = substr((string) $response->body(), 0, 200);
                $message = sprintf('Jira API call failed: HTTP %d %s', $response->status(), $body);

                if ($response->status() === 401 || $response->status() === 403) {
                    throw new ConnectorAuthException($message);
                }

                throw new ConnectorApiException($message);
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                throw new ConnectorApiException('Jira API returned non-JSON body.');
            }

            $batch = $this->extractBatch($payload);

            // Auto-detect mode on first response. `nextPageToken` is
            // the modern shape; anything else falls back to offset.
            if ($mode === null) {
                $mode = array_key_exists('nextPageToken', $payload)
                    ? self::MODE_TOKEN
                    : self::MODE_OFFSET;
            }

            yield $batch;

            if ($mode === self::MODE_TOKEN) {
                $next = $payload['nextPageToken'] ?? null;
                $isLast = (bool) ($payload['isLast'] ?? false);
                $cursor = (is_string($next) && $next !== '') ? $next : null;

                if ($cursor === null || $isLast) {
                    return;
                }
            } else {
                // Offset pagination. Compute the next `startAt` from
                // the batch length so we stay correct even when the
                // upstream omits an explicit `startAt` field (rare but
                // possible on degenerate responses).
                $isLast = $this->offsetIsLast($payload, $batch);
                if ($isLast || $batch === []) {
                    return;
                }
                $advance = count($batch);
                $reported = $payload['startAt'] ?? null;
                $startAt = is_int($reported) ? $reported + $advance : $startAt + $advance;
                $cursor = (string) $startAt;
            }

            $page++;
            if ($page >= $maxPages) {
                throw new ConnectorPaginationLimitException(
                    maxPages: $maxPages,
                );
            }
        } while (true);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<array<string,mixed>>
     */
    private function extractBatch(array $payload): array
    {
        $rows = $payload[$this->resultsKey] ?? [];
        if (! is_array($rows)) {
            return [];
        }
        $batch = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $batch[] = $row;
            }
        }

        return $batch;
    }

    /**
     * Offset-mode "is this the last page?" detector. The Cloud REST
     * API exposes three signals depending on the endpoint:
     *
     *   - `isLast` (true/false) — preferred
     *   - `total` + `startAt` — second-best
     *   - neither — assume done when the batch is shorter than the
     *     requested `maxResults` (the closure's responsibility to set
     *     this correctly via the URL query; we just observe the result)
     *
     * @param  array<string,mixed>  $payload
     * @param  list<array<string,mixed>>  $batch
     */
    private function offsetIsLast(array $payload, array $batch): bool
    {
        if (array_key_exists('isLast', $payload)) {
            return (bool) $payload['isLast'];
        }

        $total = $payload['total'] ?? null;
        $startAt = $payload['startAt'] ?? null;
        if (is_int($total) && is_int($startAt)) {
            return ($startAt + count($batch)) >= $total;
        }

        // Without a definitive signal: stop when the batch is empty
        // (handled by the caller) OR shorter than the page size we
        // would have liked. We can't see the requested page size from
        // here, so assume the upstream returns full pages until the
        // very last one — i.e. don't stop early.
        return false;
    }
}
