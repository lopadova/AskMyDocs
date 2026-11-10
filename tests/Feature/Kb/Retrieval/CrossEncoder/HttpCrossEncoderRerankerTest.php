<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Retrieval\CrossEncoder;

use App\Services\Kb\Retrieval\CrossEncoder\CrossEncoderUnavailableException;
use App\Services\Kb\Retrieval\CrossEncoder\HttpCrossEncoderReranker;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * v8.43/W3 (ADR 0035 §D4) — the hardened HTTP driver: allow-list, scheme,
 * closed response shape, bounded everything.
 */
final class HttpCrossEncoderRerankerTest extends TestCase
{
    /** @param array<string,mixed> $over */
    private function driver(string $profile = 'self_hosted', array $over = [], int $maxPassages = 50): HttpCrossEncoderReranker
    {
        return new HttpCrossEncoderReranker($profile, array_merge([
            'endpoint' => 'https://rerank.internal.example/rerank',
            'model' => 'Qwen3-Reranker-0.6B',
            'api_key' => 'k-test',
            'allowed_hosts' => ['rerank.internal.example'],
            'allow_http' => false,
        ], $over), 5, $maxPassages, 2000);
    }

    /** A FRESH fake per scenario — Http::fake() stacks, and a reused response's stream is already consumed. */
    private function freshFake(mixed $response): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*' => $response]);
    }

    private function expectReason(string $reason, callable $fn): void
    {
        try {
            $fn();
            $this->fail("expected CrossEncoderUnavailableException [{$reason}]");
        } catch (CrossEncoderUnavailableException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function test_parses_the_cohere_jina_vllm_shape_in_index_order(): void
    {
        Http::fake(['rerank.internal.example/*' => Http::response(['results' => [
            ['index' => 1, 'relevance_score' => 0.9],
            ['index' => 0, 'relevance_score' => 0.2],
        ]])]);

        $this->assertSame([0.2, 0.9], $this->driver()->score('q', ['a', 'b']));
    }

    public function test_parses_the_bare_array_tei_shape(): void
    {
        Http::fake(['*' => Http::response([['index' => 0, 'score' => 0.4], ['index' => 1, 'score' => 0.6]])]);

        $this->assertSame([0.4, 0.6], $this->driver()->score('q', ['a', 'b']));
    }

    public function test_raw_logits_go_through_a_sigmoid_into_0_1(): void
    {
        Http::fake(['*' => Http::response(['results' => [['index' => 0, 'score' => 3.0], ['index' => 1, 'score' => -3.0]]])]);

        [$hi, $lo] = $this->driver()->score('q', ['a', 'b']);

        $this->assertEqualsWithDelta(0.952574, $hi, 1e-5);
        $this->assertEqualsWithDelta(0.047426, $lo, 1e-5);
    }

    public function test_sends_a_bearer_key_the_model_and_the_documents_and_never_follows_redirects(): void
    {
        Http::fake(['*' => Http::response(['results' => [['index' => 0, 'relevance_score' => 0.5]]])]);

        $this->driver()->score('the query', ['one passage']);

        Http::assertSent(function (Request $r): bool {
            return $r->hasHeader('Authorization', 'Bearer k-test')
                && $r['query'] === 'the query'
                && $r['documents'] === ['one passage']
                && $r['model'] === 'Qwen3-Reranker-0.6B';
        });
    }

    public function test_texts_body_style_for_tei(): void
    {
        Http::fake(['*' => Http::response([['index' => 0, 'score' => 0.5]])]);

        $this->driver('self_hosted', ['body_style' => 'texts'])->score('q', ['p']);

        Http::assertSent(fn (Request $r): bool => $r['texts'] === ['p'] && ! isset($r['documents']));
    }

    public function test_long_passages_are_truncated_before_egress(): void
    {
        Http::fake(['*' => Http::response(['results' => [['index' => 0, 'relevance_score' => 0.5]]])]);

        $this->driver()->score('q', [str_repeat('x', 5000)]);

        Http::assertSent(fn (Request $r): bool => mb_strlen($r['documents'][0]) === 2000);
    }

    public function test_a_host_outside_the_allow_list_is_refused_before_any_request(): void
    {
        Http::fake();

        $this->expectReason('endpoint_not_allowed', fn () => $this->driver('self_hosted', ['endpoint' => 'https://evil.example/rerank'])->score('q', ['a']));
        Http::assertNothingSent();
    }

    public function test_a_host_port_entry_pins_the_port(): void
    {
        Http::fake(['*' => Http::response(['results' => [['index' => 0, 'relevance_score' => 0.5]]])]);
        $profile = ['endpoint' => 'https://rerank.internal.example:8443/rerank', 'allowed_hosts' => ['rerank.internal.example:8443']];

        $this->assertSame([0.5], $this->driver('self_hosted', $profile)->score('q', ['a']));
        $this->expectReason('endpoint_not_allowed', fn () => $this->driver('self_hosted', ['endpoint' => 'https://rerank.internal.example:9999/rerank', 'allowed_hosts' => ['rerank.internal.example:8443']])->score('q', ['a']));
    }

    public function test_plain_http_is_tolerated_only_for_self_hosted_with_allow_http(): void
    {
        Http::fake(['*' => Http::response(['results' => [['index' => 0, 'relevance_score' => 0.5]]])]);
        $http = ['endpoint' => 'http://rerank.internal.example:8080/rerank', 'allowed_hosts' => ['rerank.internal.example']];

        $this->expectReason('endpoint_scheme', fn () => $this->driver('self_hosted', $http + ['allow_http' => false])->score('q', ['a']));
        $this->expectReason('endpoint_scheme', fn () => $this->driver('api', $http + ['allow_http' => true])->score('q', ['a']));
        $this->assertSame([0.5], $this->driver('self_hosted', $http + ['allow_http' => true])->score('q', ['a']));
    }

    public function test_malformed_endpoints_are_refused(): void
    {
        foreach (['', 'not a url', 'https://user:pw@rerank.internal.example/rerank', 'https://rerank.internal.example/rerank?x=1', 'https://rerank.internal.example/rerank#f'] as $endpoint) {
            $this->expectReason('endpoint_invalid', fn () => $this->driver('self_hosted', ['endpoint' => $endpoint])->score('q', ['a']));
        }
    }

    public function test_non_2xx_is_unavailable_with_the_status_as_reason(): void
    {
        foreach ([401, 404, 429, 500, 503] as $status) {
            $this->freshFake(Http::response('nope', $status));
            $this->expectReason('http_'.$status, fn () => $this->driver()->score('q', ['a']));
        }
    }

    public function test_a_redirect_is_not_followed_and_counts_as_unavailable(): void
    {
        Http::fake(['rerank.internal.example/*' => Http::response('', 302, ['Location' => 'https://evil.example/x'])]);

        $this->expectReason('http_302', fn () => $this->driver()->score('q', ['a']));
        Http::assertSentCount(1);
    }

    public function test_every_malformed_answer_is_unavailable_never_a_guess(): void
    {
        $bad = [
            'wrong count' => ['results' => [['index' => 0, 'relevance_score' => 0.5]]],
            'duplicate index' => ['results' => [['index' => 0, 'relevance_score' => 0.5], ['index' => 0, 'relevance_score' => 0.6]]],
            'out-of-range index' => ['results' => [['index' => 0, 'relevance_score' => 0.5], ['index' => 7, 'relevance_score' => 0.6]]],
            'string index' => ['results' => [['index' => '0', 'relevance_score' => 0.5], ['index' => '1', 'relevance_score' => 0.6]]],
            'missing score' => ['results' => [['index' => 0], ['index' => 1]]],
            'non-numeric score' => ['results' => [['index' => 0, 'relevance_score' => 'high'], ['index' => 1, 'relevance_score' => 0.6]]],
            'object not list' => ['results' => ['a' => ['index' => 0, 'relevance_score' => 0.5], 'b' => ['index' => 1, 'relevance_score' => 0.6]]],
        ];

        foreach ($bad as $label => $body) {
            $this->freshFake(Http::response($body));
            $this->expectReason('bad_shape', fn () => $this->driver()->score('q', ['a', 'b']));
        }
    }

    public function test_a_non_json_body_and_a_non_json_content_type_are_unavailable(): void
    {
        $this->freshFake(Http::response('<html>', 200, ['Content-Type' => 'text/html']));
        $this->expectReason('bad_content_type', fn () => $this->driver()->score('q', ['a']));

        $this->freshFake(Http::response('not json at all', 200, ['Content-Type' => 'application/json']));
        $this->expectReason('bad_json', fn () => $this->driver()->score('q', ['a']));
    }

    public function test_too_many_passages_are_refused_before_any_request(): void
    {
        Http::fake();

        $this->expectReason('too_many_passages', fn () => $this->driver('self_hosted', [], 2)->score('q', ['a', 'b', 'c']));
        Http::assertNothingSent();
    }

    public function test_an_empty_batch_makes_no_request(): void
    {
        Http::fake();

        $this->assertSame([], $this->driver()->score('q', []));
        Http::assertNothingSent();
    }

    public function test_a_connection_failure_is_unavailable_with_a_transport_reason(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 7: refused'));

        $this->expectReason('transport', fn () => $this->driver()->score('q', ['a']));
    }

    public function test_names_are_stable_identifiers_for_cache_keys_and_metering(): void
    {
        $this->assertSame('http-self-hosted', $this->driver('self_hosted')->name());
        $this->assertSame('http-api', $this->driver('api')->name());
        $this->assertSame('Qwen3-Reranker-0.6B', $this->driver()->model());
    }
}
