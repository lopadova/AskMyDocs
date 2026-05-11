<?php

declare(strict_types=1);

namespace App\Connectors\BuiltIn\Notion;

use App\Connectors\Exceptions\ConnectorAuthException;
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
 * `walk()` repeatedly calls the supplied closure with the current
 * `start_cursor` (or null for the first page), accumulates `results`
 * from each response, and stops when `has_more` is false. Bounded by
 * `$maxPages` so a runaway paginator can't run forever.
 *
 * The closure is responsible for returning a successful
 * {@see Response} — failures bubble out as
 * {@see ConnectorAuthException} so the connector's outer
 * try/catch records them as sync errors without retrying. The
 * Notion API uses 401 for token expiry; 5xx for transient outage
 * — the connector layer is expected to honour the
 * `ConnectorSyncJob` retry policy when it bubbles up.
 *
 * Tested in isolation in tests/Feature/Connectors/NotionPaginatorTest.php.
 */
final class NotionPaginator
{
    /**
     * @param  \Closure(?string $cursor): Response  $fetch
     * @return list<array<string,mixed>>
     */
    public function walk(\Closure $fetch, int $maxPages = 100): array
    {
        $cursor = null;
        $results = [];
        $page = 0;

        do {
            $response = $fetch($cursor);
            if (! $response instanceof Response) {
                throw new \RuntimeException(
                    'NotionPaginator: fetch closure must return an Illuminate Http Response instance.'
                );
            }

            if (! $response->successful()) {
                throw new ConnectorAuthException(sprintf(
                    'Notion API call failed: HTTP %d %s',
                    $response->status(),
                    substr((string) $response->body(), 0, 200),
                ));
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                throw new \RuntimeException('Notion API returned non-JSON body.');
            }

            foreach (($payload['results'] ?? []) as $row) {
                if (is_array($row)) {
                    $results[] = $row;
                }
            }

            $hasMore = (bool) ($payload['has_more'] ?? false);
            $next = $payload['next_cursor'] ?? null;
            $cursor = (is_string($next) && $next !== '') ? $next : null;

            if (! $hasMore || $cursor === null) {
                break;
            }

            $page++;
        } while ($page < $maxPages);

        return $results;
    }
}
