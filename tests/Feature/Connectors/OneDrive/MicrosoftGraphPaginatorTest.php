<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors\OneDrive;

use App\Connectors\BuiltIn\OneDrive\MicrosoftGraphPaginator;
use App\Connectors\Exceptions\ConnectorApiException;
use App\Connectors\Exceptions\ConnectorAuthException;
use App\Connectors\Exceptions\ConnectorPaginationLimitException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * v4.5/W5 — MicrosoftGraphPaginator tests.
 *
 * Validates the `@odata.nextLink` loop + `@odata.deltaLink` capture
 * against Graph-shaped payloads (value / @odata.nextLink /
 * @odata.deltaLink). The paginator is Graph-specific because Graph
 * uses absolute continuation URLs (not bare cursors) — the fetch
 * closure receives the full nextLink and forwards it verbatim.
 */
final class MicrosoftGraphPaginatorTest extends TestCase
{
    public function test_walk_terminates_when_nextlink_is_null(): void
    {
        Http::fake([
            'graph.microsoft.com/*' => Http::sequence()
                ->push([
                    'value' => [['id' => 'a'], ['id' => 'b']],
                    '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/drive/root/children?$skiptoken=tok2',
                ], 200)
                ->push([
                    'value' => [['id' => 'c']],
                    '@odata.nextLink' => null,
                ], 200),
        ]);

        $paginator = new MicrosoftGraphPaginator;
        $items = $paginator->walk(function (?string $nextLink) {
            $url = $nextLink ?? 'https://graph.microsoft.com/v1.0/me/drive/root/children';

            return Http::get($url);
        });

        $this->assertCount(3, $items);
        $this->assertSame('a', $items[0]['id']);
        $this->assertSame('c', $items[2]['id']);
    }

    public function test_walk_handles_single_page_response(): void
    {
        Http::fake([
            'graph.microsoft.com/*' => Http::response([
                'value' => [['id' => 'only']],
                '@odata.nextLink' => null,
            ], 200),
        ]);

        $paginator = new MicrosoftGraphPaginator;
        $items = $paginator->walk(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://graph.microsoft.com/v1.0/me/drive/root/children',
        ));

        $this->assertCount(1, $items);
    }

    public function test_walk_handles_empty_value_array(): void
    {
        Http::fake([
            'graph.microsoft.com/*' => Http::response([
                'value' => [],
                '@odata.nextLink' => null,
            ], 200),
        ]);

        $paginator = new MicrosoftGraphPaginator;
        $items = $paginator->walk(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://graph.microsoft.com/v1.0/me/drive/root/children',
        ));

        $this->assertSame([], $items);
    }

    public function test_walk_throws_connector_auth_exception_on_401(): void
    {
        Http::fake([
            'graph.microsoft.com/*' => Http::response(['error' => 'invalid token'], 401),
        ]);

        $paginator = new MicrosoftGraphPaginator;

        $this->expectException(ConnectorAuthException::class);
        $this->expectExceptionMessage('Microsoft Graph API call failed');

        $paginator->walk(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://graph.microsoft.com/v1.0/me/drive/root/children',
        ));
    }

    public function test_walk_throws_connector_auth_exception_on_403(): void
    {
        Http::fake([
            'graph.microsoft.com/*' => Http::response(['error' => 'forbidden'], 403),
        ]);

        $paginator = new MicrosoftGraphPaginator;

        $this->expectException(ConnectorAuthException::class);

        $paginator->walk(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://graph.microsoft.com/v1.0/me/drive/root/children',
        ));
    }

    public function test_walk_throws_connector_api_exception_on_5xx(): void
    {
        Http::fake([
            'graph.microsoft.com/*' => Http::response(['error' => 'server'], 500),
        ]);

        $paginator = new MicrosoftGraphPaginator;

        $this->expectException(ConnectorApiException::class);

        $paginator->walk(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://graph.microsoft.com/v1.0/me/drive/root/children',
        ));
    }

    public function test_walk_throws_connector_api_exception_on_429(): void
    {
        Http::fake([
            'graph.microsoft.com/*' => Http::response(['error' => 'too many'], 429),
        ]);

        $paginator = new MicrosoftGraphPaginator;

        $this->expectException(ConnectorApiException::class);

        $paginator->walk(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://graph.microsoft.com/v1.0/me/drive/root/children',
        ));
    }

    public function test_walkLazy_yields_one_batch_at_a_time(): void
    {
        Http::fake([
            'graph.microsoft.com/*' => Http::sequence()
                ->push([
                    'value' => [['id' => 'p1'], ['id' => 'p2']],
                    '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/drive/root/children?$skiptoken=t2',
                ], 200)
                ->push([
                    'value' => [['id' => 'p3']],
                    '@odata.nextLink' => null,
                ], 200),
        ]);

        $paginator = new MicrosoftGraphPaginator;
        $batches = [];
        foreach ($paginator->walkLazy(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://graph.microsoft.com/v1.0/me/drive/root/children',
        )) as $batch) {
            $batches[] = array_map(static fn ($r) => $r['id'], $batch);
        }

        $this->assertSame([['p1', 'p2'], ['p3']], $batches);
    }

    public function test_walkLazy_allows_early_break_to_skip_remaining_network_calls(): void
    {
        Http::fake([
            'graph.microsoft.com/*' => Http::sequence()
                ->push([
                    'value' => [['id' => 'first']],
                    '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/drive/root/children?$skiptoken=t2',
                ], 200)
                ->push([
                    'value' => [['id' => 'should-not-fetch']],
                    '@odata.nextLink' => null,
                ], 200),
        ]);

        $paginator = new MicrosoftGraphPaginator;
        $seen = [];
        foreach ($paginator->walkLazy(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://graph.microsoft.com/v1.0/me/drive/root/children',
        )) as $batch) {
            foreach ($batch as $row) {
                $seen[] = $row['id'];
            }
            break;
        }

        $this->assertSame(['first'], $seen);
        Http::assertSentCount(1);
    }

    public function test_walk_throws_when_max_pages_reached(): void
    {
        Http::fake([
            'graph.microsoft.com/*' => Http::response([
                'value' => [['id' => 'endless']],
                '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/drive/root/children?$skiptoken=next',
            ], 200),
        ]);

        $paginator = new MicrosoftGraphPaginator;

        $this->expectException(ConnectorPaginationLimitException::class);
        $this->expectExceptionMessage('Pagination limit');

        $paginator->walk(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://graph.microsoft.com/v1.0/me/drive/root/children',
        ), maxPages: 2);
    }

    public function test_pagination_limit_exception_exposes_max_pages(): void
    {
        Http::fake([
            'graph.microsoft.com/*' => Http::response([
                'value' => [['id' => 'partial']],
                '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/drive/root/children?$skiptoken=t2',
            ], 200),
        ]);

        $paginator = new MicrosoftGraphPaginator;

        try {
            $paginator->walk(fn (?string $nextLink) => Http::get(
                $nextLink ?? 'https://graph.microsoft.com/v1.0/me/drive/root/children',
            ), maxPages: 3);
            $this->fail('Expected ConnectorPaginationLimitException');
        } catch (ConnectorPaginationLimitException $e) {
            $this->assertSame(3, $e->maxPages);
            $this->assertSame([], $e->partialResults);
        }
    }

    public function test_walkLazy_captures_delta_link_for_caller(): void
    {
        Http::fake([
            'graph.microsoft.com/*' => Http::response([
                'value' => [['id' => 'changed']],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/drive/root/delta?token=new-cursor',
            ], 200),
        ]);

        $paginator = new MicrosoftGraphPaginator;
        $items = [];
        foreach ($paginator->walkLazy(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://graph.microsoft.com/v1.0/me/drive/root/delta',
        )) as $batch) {
            foreach ($batch as $row) {
                $items[] = $row;
            }
        }

        $this->assertCount(1, $items);
        $this->assertSame(
            'https://graph.microsoft.com/v1.0/me/drive/root/delta?token=new-cursor',
            $paginator->deltaLink(),
        );
    }

    public function test_walkLazy_delta_link_null_when_endpoint_did_not_return_one(): void
    {
        Http::fake([
            'graph.microsoft.com/*' => Http::response([
                'value' => [['id' => 'x']],
                '@odata.nextLink' => null,
            ], 200),
        ]);

        $paginator = new MicrosoftGraphPaginator;
        iterator_to_array($paginator->walkLazy(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://graph.microsoft.com/v1.0/me/drive/root/children',
        )));

        $this->assertNull($paginator->deltaLink());
    }
}
