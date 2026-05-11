<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors\Confluence;

use App\Connectors\BuiltIn\Confluence\AtlassianPaginator;
use App\Connectors\Exceptions\ConnectorApiException;
use App\Connectors\Exceptions\ConnectorAuthException;
use App\Connectors\Exceptions\ConnectorPaginationLimitException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * v4.5/W5 — AtlassianPaginator tests.
 *
 * Validates the `_links.next` loop against Atlassian-shaped payloads.
 * The fetch closure receives the absolute URL the caller should hit
 * (null on first iteration so the closure can use its own seed URL,
 * then the absolutised `_links.next` value).
 */
final class AtlassianPaginatorTest extends TestCase
{
    public function test_walk_terminates_when_no_next_link(): void
    {
        Http::fake([
            'api.atlassian.com/*' => Http::sequence()
                ->push([
                    'results' => [['id' => '1'], ['id' => '2']],
                    '_links' => ['next' => 'https://api.atlassian.com/page2'],
                ], 200)
                ->push([
                    'results' => [['id' => '3']],
                    '_links' => [],
                ], 200),
        ]);

        $paginator = new AtlassianPaginator;
        $items = $paginator->walk(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://api.atlassian.com/page1',
        ));

        $this->assertCount(3, $items);
    }

    public function test_walk_handles_single_page(): void
    {
        Http::fake([
            'api.atlassian.com/*' => Http::response([
                'results' => [['id' => 'one']],
                '_links' => [],
            ], 200),
        ]);

        $paginator = new AtlassianPaginator;
        $items = $paginator->walk(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://api.atlassian.com/page1',
        ));

        $this->assertCount(1, $items);
    }

    public function test_walk_handles_empty_results(): void
    {
        Http::fake([
            'api.atlassian.com/*' => Http::response([
                'results' => [],
                '_links' => [],
            ], 200),
        ]);

        $paginator = new AtlassianPaginator;
        $items = $paginator->walk(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://api.atlassian.com/page1',
        ));

        $this->assertSame([], $items);
    }

    public function test_walk_throws_connector_auth_exception_on_401(): void
    {
        Http::fake([
            'api.atlassian.com/*' => Http::response(['message' => 'invalid token'], 401),
        ]);

        $paginator = new AtlassianPaginator;

        $this->expectException(ConnectorAuthException::class);
        $this->expectExceptionMessage('Atlassian API call failed');

        $paginator->walk(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://api.atlassian.com/page1',
        ));
    }

    public function test_walk_throws_connector_auth_exception_on_403(): void
    {
        Http::fake([
            'api.atlassian.com/*' => Http::response(['message' => 'forbidden'], 403),
        ]);

        $paginator = new AtlassianPaginator;

        $this->expectException(ConnectorAuthException::class);

        $paginator->walk(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://api.atlassian.com/page1',
        ));
    }

    public function test_walk_throws_connector_api_exception_on_5xx(): void
    {
        Http::fake([
            'api.atlassian.com/*' => Http::response(['message' => 'down'], 502),
        ]);

        $paginator = new AtlassianPaginator;

        $this->expectException(ConnectorApiException::class);

        $paginator->walk(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://api.atlassian.com/page1',
        ));
    }

    public function test_walk_throws_connector_api_exception_on_429(): void
    {
        Http::fake([
            'api.atlassian.com/*' => Http::response(['message' => 'rate'], 429),
        ]);

        $paginator = new AtlassianPaginator;

        $this->expectException(ConnectorApiException::class);

        $paginator->walk(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://api.atlassian.com/page1',
        ));
    }

    public function test_walkLazy_yields_one_batch_at_a_time(): void
    {
        Http::fake([
            'api.atlassian.com/*' => Http::sequence()
                ->push([
                    'results' => [['id' => '1'], ['id' => '2']],
                    '_links' => ['next' => 'https://api.atlassian.com/page2'],
                ], 200)
                ->push([
                    'results' => [['id' => '3']],
                    '_links' => [],
                ], 200),
        ]);

        $paginator = new AtlassianPaginator;
        $batches = [];
        foreach ($paginator->walkLazy(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://api.atlassian.com/page1',
        )) as $batch) {
            $batches[] = array_map(static fn ($r) => $r['id'], $batch);
        }

        $this->assertSame([['1', '2'], ['3']], $batches);
    }

    public function test_walkLazy_allows_early_break_to_skip_remaining_calls(): void
    {
        Http::fake([
            'api.atlassian.com/*' => Http::sequence()
                ->push([
                    'results' => [['id' => 'first']],
                    '_links' => ['next' => 'https://api.atlassian.com/page2'],
                ], 200)
                ->push([
                    'results' => [['id' => 'should-not-fetch']],
                    '_links' => [],
                ], 200),
        ]);

        $paginator = new AtlassianPaginator;
        $seen = [];
        foreach ($paginator->walkLazy(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://api.atlassian.com/page1',
        )) as $batch) {
            foreach ($batch as $row) {
                $seen[] = $row['id'];
            }
            break;
        }

        $this->assertSame(['first'], $seen);
        Http::assertSentCount(1);
    }

    public function test_walk_throws_when_max_pages_reached_with_next_link(): void
    {
        Http::fake([
            'api.atlassian.com/*' => Http::response([
                'results' => [['id' => 'forever']],
                '_links' => ['next' => 'https://api.atlassian.com/pageN'],
            ], 200),
        ]);

        $paginator = new AtlassianPaginator;

        $this->expectException(ConnectorPaginationLimitException::class);

        $paginator->walk(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://api.atlassian.com/page1',
        ), maxPages: 2);
    }
}
