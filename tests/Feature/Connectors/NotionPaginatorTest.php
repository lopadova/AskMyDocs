<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\BuiltIn\Notion\NotionPaginator;
use App\Connectors\Exceptions\ConnectorAuthException;
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

    public function test_walk_throws_connector_auth_exception_on_http_failure(): void
    {
        Http::fake([
            'api.notion.com/v1/search' => Http::response([
                'message' => 'API token is invalid.',
            ], 401),
        ]);

        $paginator = new NotionPaginator;

        $this->expectException(ConnectorAuthException::class);
        $this->expectExceptionMessage('Notion API call failed');

        $paginator->walk(fn (?string $cursor) => Http::post(
            'https://api.notion.com/v1/search',
            $cursor === null ? [] : ['start_cursor' => $cursor],
        ));
    }
}
