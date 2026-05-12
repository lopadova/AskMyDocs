<?php

declare(strict_types=1);

namespace App\Connectors\BuiltIn\OneDrive;

use App\Connectors\Exceptions\ConnectorApiException;
use App\Connectors\Exceptions\ConnectorAuthException;
use App\Connectors\Exceptions\ConnectorPaginationLimitException;
use Generator;
use Illuminate\Http\Client\Response;

/**
 * v4.5/W5 — Microsoft Graph `@odata.nextLink` pagination walker.
 *
 * Every Microsoft Graph collection endpoint shares the same envelope:
 *
 *   GET /v1.0/<resource>
 *     200: {
 *       "value": [ ... ],
 *       "@odata.nextLink": "https://graph.microsoft.com/v1.0/.../next-cursor",
 *       "@odata.deltaLink": "https://graph.microsoft.com/v1.0/.../delta?token=..." (delta endpoints only)
 *     }
 *
 * The delta query (`/me/drive/root/delta`) terminates with
 * `@odata.deltaLink` instead of `@odata.nextLink`; the caller picks
 * that link off the final batch's payload (it surfaces via the
 * paginator's {@see deltaLink()} sidechannel, populated as the walker
 * consumes responses) and persists it as the cursor for the next
 * incremental run.
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
 *   - `maxPages` reached while `@odata.nextLink` non-null →
 *     {@see ConnectorPaginationLimitException}
 *
 * The fetch closure receives the FULL `@odata.nextLink` URL (not a
 * bare cursor) because Graph's continuation links are absolute and
 * already carry every original query parameter; the closure typically
 * forwards them verbatim via `Http::withToken(...)->get($nextLink)`.
 *
 * Tested in isolation in
 * tests/Feature/Connectors/OneDrive/MicrosoftGraphPaginatorTest.php.
 */
final class MicrosoftGraphPaginator
{
    /**
     * Most-recent `@odata.deltaLink` observed during walkLazy(). Read
     * by the delta-query caller after the walk terminates so the
     * connector can persist the new cursor for the next incremental
     * sync.
     */
    private ?string $deltaLink = null;

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
     * Lazy traversal — yield one batch at a time.
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
                    'MicrosoftGraphPaginator: fetch closure must return an Illuminate Http Response instance.'
                );
            }

            if (! $response->successful()) {
                $body = substr((string) $response->body(), 0, 200);
                $message = sprintf('Microsoft Graph API call failed: HTTP %d %s', $response->status(), $body);

                if ($response->status() === 401 || $response->status() === 403) {
                    throw new ConnectorAuthException($message);
                }

                throw new ConnectorApiException($message);
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                throw new ConnectorApiException('Microsoft Graph API returned non-JSON body.');
            }

            $batch = [];
            foreach (($payload['value'] ?? []) as $row) {
                if (is_array($row)) {
                    $batch[] = $row;
                }
            }

            yield $batch;

            // Delta endpoints return `@odata.deltaLink` on the final
            // batch — stash it so the caller can read it after the
            // walk terminates.
            $delta = $payload['@odata.deltaLink'] ?? null;
            if (is_string($delta) && $delta !== '') {
                $this->deltaLink = $delta;
            }

            $next = $payload['@odata.nextLink'] ?? null;
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

    /**
     * The most-recent `@odata.deltaLink` observed by `walkLazy()` /
     * `walk()`. Null when none was seen (non-delta endpoints, or the
     * walk terminated early via pagination cap).
     */
    public function deltaLink(): ?string
    {
        return $this->deltaLink;
    }
}
