<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\BuiltIn\Notion\NotionPaginator;
use App\Connectors\Exceptions\ConnectorApiException;
use App\Connectors\Exceptions\ConnectorAuthException;
use App\Connectors\Exceptions\ConnectorPaginationLimitException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * v4.5/W2 — NotionPaginator tests.
 *
 * Validates the next_cursor loop semantics against Notion-shaped
 * payloads (results / next_cursor / has_more). The paginator is
 * deliberately Notion-specific — every Notion endpoint shares the
 * same pagination contract so factoring it out is cheap.
 */
final class NotionPaginatorTest extends TestCase
{
    public function test_walk_terminates_on_has_more_false(): void
    {
        Http::fake([
            'api.notion.com/v1/search' => Http::sequence()
                ->push([
                    'results' => [
                        ['id' => 'page-1'],
                        ['id' => 'page-2'],
                    ],
                    'next_cursor' => 'cursor-2',
                    'has_more' => true,
                ], 200)
                ->push([
                    'results' => [
                        ['id' => 'page-3'],
                    ],
                    'next_cursor' => null,
                    'has_more' => false,
                ], 200),
        ]);

        $paginator = new NotionPaginator;
        $pages = $paginator->walk(function (?string $cursor) {
            $body = [];
            if ($cursor !== null) {
                $body['start_cursor'] = $cursor;
            }

            return Http::post('https://api.notion.com/v1/search', $body);
        });

        $this->assertCount(3, $pages);
        $this->assertSame('page-1', $pages[0]['id']);
        $this->assertSame('page-2', $pages[1]['id']);
        $this->assertSame('page-3', $pages[2]['id']);
    }

    public function test_walk_handles_single_page_response(): void
    {
        Http::fake([
            'api.notion.com/v1/search' => Http::response([
                'results' => [['id' => 'only-page']],
                'next_cursor' => null,
                'has_more' => false,
            ], 200),
        ]);

        $paginator = new NotionPaginator;
        $pages = $paginator->walk(fn (?string $cursor) => Http::post(
            'https://api.notion.com/v1/search',
            $cursor === null ? [] : ['start_cursor' => $cursor],
        ));

        $this->assertCount(1, $pages);
    }

    public function test_walk_handles_empty_results(): void
    {
        Http::fake([
            'api.notion.com/v1/search' => Http::response([
                'results' => [],
                'next_cursor' => null,
                'has_more' => false,
            ], 200),
        ]);

        $paginator = new NotionPaginator;
        $pages = $paginator->walk(fn (?string $cursor) => Http::post(
            'https://api.notion.com/v1/search',
            $cursor === null ? [] : ['start_cursor' => $cursor],
        ));

        $this->assertSame([], $pages);
    }

    public function test_walk_throws_connector_auth_exception_on_401(): void
    {
        Http::fake([
            'api.notion.com/v1/search' => Http::response([
                'message' => 'API token is invalid.',
            ], 401),
        ]);

        $paginator = new NotionPaginator;

        // Finding #4 — 401 / 403 → ConnectorAuthException (permanent
        // credential rejection; job runner stops retrying).
        $this->expectException(ConnectorAuthException::class);
        $this->expectExceptionMessage('Notion API call failed');

        $paginator->walk(fn (?string $cursor) => Http::post(
            'https://api.notion.com/v1/search',
            $cursor === null ? [] : ['start_cursor' => $cursor],
        ));
    }

    public function test_walk_throws_connector_auth_exception_on_403(): void
    {
        Http::fake([
            'api.notion.com/v1/search' => Http::response([
                'message' => 'Forbidden.',
            ], 403),
        ]);

        $paginator = new NotionPaginator;

        $this->expectException(ConnectorAuthException::class);

        $paginator->walk(fn (?string $cursor) => Http::post(
            'https://api.notion.com/v1/search',
            $cursor === null ? [] : ['start_cursor' => $cursor],
        ));
    }

    public function test_walk_throws_connector_api_exception_on_5xx(): void
    {
        Http::fake([
            'api.notion.com/v1/search' => Http::response([
                'message' => 'Internal Server Error',
            ], 500),
        ]);

        $paginator = new NotionPaginator;

        // Finding #4 — 5xx → ConnectorApiException (transient; job
        // runner retries per backoff).
        $this->expectException(ConnectorApiException::class);

        $paginator->walk(fn (?string $cursor) => Http::post(
            'https://api.notion.com/v1/search',
            $cursor === null ? [] : ['start_cursor' => $cursor],
        ));
    }

    public function test_walk_throws_connector_api_exception_on_429(): void
    {
        Http::fake([
            'api.notion.com/v1/search' => Http::response([
                'message' => 'Rate limit exceeded.',
            ], 429),
        ]);

        $paginator = new NotionPaginator;

        $this->expectException(ConnectorApiException::class);

        $paginator->walk(fn (?string $cursor) => Http::post(
            'https://api.notion.com/v1/search',
            $cursor === null ? [] : ['start_cursor' => $cursor],
        ));
    }

    public function test_walkLazy_yields_one_batch_at_a_time(): void
    {
        Http::fake([
            'api.notion.com/v1/search' => Http::sequence()
                ->push([
                    'results' => [['id' => 'p1'], ['id' => 'p2']],
                    'next_cursor' => 'c2',
                    'has_more' => true,
                ], 200)
                ->push([
                    'results' => [['id' => 'p3']],
                    'next_cursor' => null,
                    'has_more' => false,
                ], 200),
        ]);

        $paginator = new NotionPaginator;
        $batches = [];
        foreach ($paginator->walkLazy(fn (?string $cursor) => Http::post(
            'https://api.notion.com/v1/search',
            $cursor === null ? [] : ['start_cursor' => $cursor],
        )) as $batch) {
            $batches[] = array_map(static fn ($r) => $r['id'], $batch);
        }

        // Each batch maps 1:1 to one upstream API call — memory stays
        // bounded by page_size regardless of total result set size.
        $this->assertSame([['p1', 'p2'], ['p3']], $batches);
    }

    public function test_walkLazy_allows_early_break_to_skip_remaining_network_calls(): void
    {
        // Set up two pages but only consume the first. The second
        // page MUST NOT be requested (otherwise the lazy benefit is
        // missing and incremental sync would still pay for every
        // workspace page on huge tenants).
        Http::fake([
            'api.notion.com/v1/search' => Http::sequence()
                ->push([
                    'results' => [['id' => 'fresh']],
                    'next_cursor' => 'c2',
                    'has_more' => true,
                ], 200)
                ->push([
                    'results' => [['id' => 'should-not-fetch']],
                    'next_cursor' => null,
                    'has_more' => false,
                ], 200),
        ]);

        $paginator = new NotionPaginator;
        $seen = [];
        foreach ($paginator->walkLazy(fn (?string $cursor) => Http::post(
            'https://api.notion.com/v1/search',
            $cursor === null ? [] : ['start_cursor' => $cursor],
        )) as $batch) {
            foreach ($batch as $row) {
                $seen[] = $row['id'];
            }
            break; // skip the rest
        }

        $this->assertSame(['fresh'], $seen);
        // Only one HTTP call should have been issued; the second
        // page's fake is still queued — assert by counting recorded
        // requests.
        Http::assertSentCount(1);
    }

    public function test_walk_throws_when_max_pages_reached_with_has_more(): void
    {
        // Every page returns `has_more=true` so the paginator can
        // never hit the natural terminator. With maxPages=2, the
        // exception must fire after the 2nd batch.
        Http::fake([
            'api.notion.com/v1/search' => Http::response([
                'results' => [['id' => 'page-x']],
                'next_cursor' => 'cursor-next',
                'has_more' => true,
            ], 200),
        ]);

        $paginator = new NotionPaginator;

        $this->expectException(ConnectorPaginationLimitException::class);
        $this->expectExceptionMessage('Pagination limit');

        $paginator->walk(fn (?string $cursor) => Http::post(
            'https://api.notion.com/v1/search',
            $cursor === null ? [] : ['start_cursor' => $cursor],
        ), maxPages: 2);
    }

    public function test_pagination_limit_exception_exposes_max_pages(): void
    {
        // Verifies that ConnectorPaginationLimitException correctly
        // carries the maxPages cap and does NOT accumulate partial
        // results (the caller already processed every yielded batch
        // incrementally, so doubling memory here would violate the
        // memory-bounded contract).
        Http::fake([
            'api.notion.com/v1/search' => Http::response([
                'results' => [['id' => 'partial-page']],
                'next_cursor' => 'cursor-next',
                'has_more' => true,
            ], 200),
        ]);

        $paginator = new NotionPaginator;

        try {
            $paginator->walk(fn (?string $cursor) => Http::post(
                'https://api.notion.com/v1/search',
                $cursor === null ? [] : ['start_cursor' => $cursor],
            ), maxPages: 2);
            $this->fail('Expected ConnectorPaginationLimitException');
        } catch (ConnectorPaginationLimitException $e) {
            $this->assertSame(2, $e->maxPages);
            // partialResults is always empty — the connector already
            // processed every batch before the cap fires.
            $this->assertSame([], $e->partialResults);
        }
    }
}
