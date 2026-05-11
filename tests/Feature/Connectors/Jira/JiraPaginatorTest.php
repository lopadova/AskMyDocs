<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors\Jira;

use App\Connectors\BuiltIn\Jira\JiraPaginator;
use App\Connectors\Exceptions\ConnectorApiException;
use App\Connectors\Exceptions\ConnectorAuthException;
use App\Connectors\Exceptions\ConnectorPaginationLimitException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * v4.5/W6 — JiraPaginator behaviour: offset-mode + token-mode auto-
 * detect, exception taxonomy, pagination cap.
 */
final class JiraPaginatorTest extends TestCase
{
    private function makeResponse(array $payload, int $status = 200): Response
    {
        // Build a real Http\Client\Response without going through any
        // fake middleware so each closure invocation produces one
        // independent Response object.
        $factory = new HttpFactory();
        $psr = new \GuzzleHttp\Psr7\Response(
            $status,
            ['Content-Type' => 'application/json'],
            (string) json_encode($payload),
        );

        return new Response($psr);
    }

    private function makeNonJsonResponse(string $body, int $status = 200): Response
    {
        $psr = new \GuzzleHttp\Psr7\Response(
            $status,
            ['Content-Type' => 'text/html'],
            $body,
        );

        return new Response($psr);
    }

    #[Test]
    public function walk_offset_mode_paginates_via_start_at(): void
    {
        $batches = [
            ['issues' => [['id' => 'A'], ['id' => 'B']], 'startAt' => 0, 'maxResults' => 2, 'total' => 5, 'isLast' => false],
            ['issues' => [['id' => 'C'], ['id' => 'D']], 'startAt' => 2, 'maxResults' => 2, 'total' => 5, 'isLast' => false],
            ['issues' => [['id' => 'E']], 'startAt' => 4, 'maxResults' => 2, 'total' => 5, 'isLast' => true],
        ];
        $calls = [];

        $paginator = new JiraPaginator(resultsKey: 'issues', mode: JiraPaginator::MODE_AUTO);
        $fetch = function (?string $cursor) use (&$calls, &$batches) {
            $calls[] = $cursor;
            $batch = array_shift($batches);

            return $this->makeResponse($batch);
        };

        $rows = $paginator->walk($fetch);

        $this->assertCount(5, $rows);
        $this->assertSame(['A', 'B', 'C', 'D', 'E'], array_column($rows, 'id'));
        // The first call passes null; subsequent calls pass startAt as
        // a string-encoded integer.
        $this->assertSame([null, '2', '4'], $calls);
    }

    #[Test]
    public function walk_token_mode_paginates_via_next_page_token(): void
    {
        $batches = [
            ['issues' => [['id' => 'A']], 'nextPageToken' => 'tok-2', 'isLast' => false],
            ['issues' => [['id' => 'B']], 'nextPageToken' => 'tok-3', 'isLast' => false],
            ['issues' => [['id' => 'C']], 'nextPageToken' => null,    'isLast' => true],
        ];
        $calls = [];

        $paginator = new JiraPaginator(resultsKey: 'issues', mode: JiraPaginator::MODE_AUTO);
        $fetch = function (?string $cursor) use (&$calls, &$batches) {
            $calls[] = $cursor;
            $batch = array_shift($batches);

            return $this->makeResponse($batch);
        };

        $rows = $paginator->walk($fetch);

        $this->assertCount(3, $rows);
        $this->assertSame([null, 'tok-2', 'tok-3'], $calls);
    }

    #[Test]
    public function walk_handles_offset_without_is_last_via_total(): void
    {
        $batches = [
            ['issues' => [['id' => 'A'], ['id' => 'B']], 'startAt' => 0, 'maxResults' => 2, 'total' => 3],
            ['issues' => [['id' => 'C']],               'startAt' => 2, 'maxResults' => 2, 'total' => 3],
        ];
        $paginator = new JiraPaginator(resultsKey: 'issues');
        $fetch = function (?string $cursor) use (&$batches) {
            return $this->makeResponse(array_shift($batches));
        };

        $rows = $paginator->walk($fetch);
        $this->assertCount(3, $rows);
    }

    #[Test]
    public function walk_stops_when_batch_is_empty(): void
    {
        $paginator = new JiraPaginator(resultsKey: 'issues');
        $payload = ['issues' => []];
        $fetch = fn (?string $cursor) => $this->makeResponse($payload);

        $rows = $paginator->walk($fetch);
        $this->assertSame([], $rows);
    }

    #[Test]
    public function results_key_can_be_overridden_for_project_search(): void
    {
        $paginator = new JiraPaginator(resultsKey: 'values');
        $payload = ['values' => [['key' => 'PROJ']], 'isLast' => true];
        $fetch = fn (?string $cursor) => $this->makeResponse($payload);

        $rows = $paginator->walk($fetch);
        $this->assertCount(1, $rows);
        $this->assertSame('PROJ', $rows[0]['key']);
    }

    #[Test]
    public function raises_auth_exception_on_401(): void
    {
        $paginator = new JiraPaginator(resultsKey: 'issues');
        $fetch = fn (?string $cursor) => $this->makeResponse(['error' => 'denied'], 401);

        $this->expectException(ConnectorAuthException::class);
        $paginator->walk($fetch);
    }

    #[Test]
    public function raises_auth_exception_on_403(): void
    {
        $paginator = new JiraPaginator(resultsKey: 'issues');
        $fetch = fn (?string $cursor) => $this->makeResponse(['error' => 'forbidden'], 403);

        $this->expectException(ConnectorAuthException::class);
        $paginator->walk($fetch);
    }

    #[Test]
    public function raises_api_exception_on_500(): void
    {
        $paginator = new JiraPaginator(resultsKey: 'issues');
        $fetch = fn (?string $cursor) => $this->makeResponse(['error' => 'oops'], 500);

        $this->expectException(ConnectorApiException::class);
        $paginator->walk($fetch);
    }

    #[Test]
    public function raises_api_exception_on_non_json_body(): void
    {
        $paginator = new JiraPaginator(resultsKey: 'issues');
        $fetch = fn (?string $cursor) => $this->makeNonJsonResponse('<html>not-json</html>', 200);

        $this->expectException(ConnectorApiException::class);
        $paginator->walk($fetch);
    }

    #[Test]
    public function raises_pagination_limit_exception_at_max_pages(): void
    {
        // Always return "more available" — never terminates naturally.
        $payload = ['issues' => [['id' => 'X']], 'isLast' => false, 'nextPageToken' => 'always'];
        $paginator = new JiraPaginator(resultsKey: 'issues', mode: JiraPaginator::MODE_TOKEN);
        $fetch = fn (?string $cursor) => $this->makeResponse($payload);

        $this->expectException(ConnectorPaginationLimitException::class);
        $paginator->walk($fetch, maxPages: 3);
    }

    #[Test]
    public function forced_offset_mode_ignores_next_page_token(): void
    {
        // If a response carries both `nextPageToken` AND `isLast=true`,
        // forced offset mode should still terminate via `isLast`.
        $payload = ['issues' => [['id' => 'A']], 'nextPageToken' => 'ghost', 'isLast' => true];
        $paginator = new JiraPaginator(resultsKey: 'issues', mode: JiraPaginator::MODE_OFFSET);
        $fetch = fn (?string $cursor) => $this->makeResponse($payload);

        $rows = $paginator->walk($fetch);
        $this->assertCount(1, $rows);
    }

    #[Test]
    public function lazy_walk_yields_one_batch_at_a_time(): void
    {
        $batches = [
            ['issues' => [['id' => 'A']], 'nextPageToken' => 'b', 'isLast' => false],
            ['issues' => [['id' => 'B']], 'nextPageToken' => null, 'isLast' => true],
        ];
        $paginator = new JiraPaginator(resultsKey: 'issues');
        $fetch = function (?string $cursor) use (&$batches) {
            return $this->makeResponse(array_shift($batches));
        };

        $collected = [];
        foreach ($paginator->walkLazy($fetch) as $batch) {
            $collected[] = array_column($batch, 'id');
        }

        $this->assertSame([['A'], ['B']], $collected);
    }

    #[Test]
    public function raises_runtime_when_fetch_closure_returns_non_response(): void
    {
        $paginator = new JiraPaginator(resultsKey: 'issues');
        $fetch = fn (?string $cursor) => 'not-a-response';

        $this->expectException(\RuntimeException::class);
        $paginator->walk($fetch);
    }
}
