<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval\CrossEncoder;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * v8.43/W3 (ADR 0035 §D4) — cross-encoder behind a `/rerank`-style JSON API.
 *
 * One driver, two PROFILES chosen by `KB_CROSS_RERANK_PROFILE` (default
 * `self_hosted`): `self_hosted` (an inference server you run — vLLM, TEI,
 * Infinity, serving e.g. Qwen3-Reranker) and `api` (a hosted rerank API such
 * as Cohere / Jina / Voyage). They differ only in endpoint, key, model and
 * whether plain http is tolerated.
 *
 * Hardening (ARCH-HTTP-001 / SEC-SSRF-001 — this is outbound egress of user
 * query text and chunk text):
 *  - the endpoint host[:port] must be in the profile's EXACT allow-list;
 *    no userinfo, query or fragment in the URL; redirects are NOT followed;
 *  - `https` always, except the `self_hosted` profile with an explicit
 *    `allow_http` (intra-cluster endpoints) — never for `api`;
 *  - bounded timeout, bounded request (passage count and characters) and a
 *    bounded response body read through the stream;
 *  - the response is validated against a closed shape (count, indexes,
 *    finite numbers) — anything else is "unavailable", never a guess.
 *
 * Wire format (covers Cohere / Jina / Voyage / vLLM / TEI): request
 * `{model, query, documents:[…]}` (or `texts` when `body_style=texts`);
 * response `{results|data:[{index, relevance_score|score}]}` or a bare
 * `[{index, score}]`. Scores outside 0..1 (raw logits) go through a sigmoid.
 */
final class HttpCrossEncoderReranker implements CrossEncoderReranker
{
    private const MAX_BODY_BYTES = 1_048_576;

    /** @param  array<string,mixed>  $profile  resolved `kb.cross_rerank.profiles.<name>` */
    public function __construct(
        private readonly string $profileName,
        private readonly array $profile,
        private readonly int $timeoutSeconds = 10,
        private readonly int $maxPassages = 50,
        private readonly int $maxPassageChars = 2000,
    ) {}

    public function name(): string
    {
        return 'http-'.str_replace('_', '-', $this->profileName);
    }

    public function model(): string
    {
        return (string) ($this->profile['model'] ?? '');
    }

    public function score(string $query, array $passages): array
    {
        $passages = array_values($passages);
        if ($passages === []) {
            return [];
        }
        if (count($passages) > $this->maxPassages) {
            throw new CrossEncoderUnavailableException('too_many_passages', sprintf('%d passages exceed the cap of %d.', count($passages), $this->maxPassages));
        }

        $url = $this->assertAllowedEndpoint((string) ($this->profile['endpoint'] ?? ''));
        $documents = array_map(fn (string $p): string => mb_substr($p, 0, $this->maxPassageChars), $passages);
        $bodyKey = ($this->profile['body_style'] ?? 'documents') === 'texts' ? 'texts' : 'documents';

        $payload = [$bodyKey => $documents, 'query' => $query];
        if ($this->model() !== '') {
            $payload['model'] = $this->model();
        }

        try {
            $request = Http::acceptJson()
                ->asJson()
                ->withoutRedirecting()
                ->timeout(max(1, $this->timeoutSeconds))
                ->connectTimeout(max(1, min(5, $this->timeoutSeconds)))
                ->withOptions(['stream' => true]);

            $key = trim((string) ($this->profile['api_key'] ?? ''));
            if ($key !== '') {
                $request = $request->withToken($key);
            }

            $response = $request->post($url, $payload);
        } catch (ConnectionException $e) {
            throw new CrossEncoderUnavailableException('transport', 'Cross-encoder transport failure: '.$e::class, $e);
        } catch (Throwable $e) {
            throw new CrossEncoderUnavailableException('transport', 'Cross-encoder request failed: '.$e::class, $e);
        }

        $status = $response->status();
        if ($status < 200 || $status >= 300) {
            // 4xx are permanent for this request (bad key, bad model): no retry here.
            throw new CrossEncoderUnavailableException('http_'.$status, "Cross-encoder answered HTTP {$status}.");
        }

        $contentType = strtolower((string) $response->header('Content-Type'));
        if ($contentType !== '' && ! str_contains($contentType, 'json')) {
            throw new CrossEncoderUnavailableException('bad_content_type', 'Cross-encoder answered a non-JSON content type.');
        }

        $decoded = json_decode($this->readBounded($response->toPsrResponse()->getBody()), true);
        if (! is_array($decoded)) {
            throw new CrossEncoderUnavailableException('bad_json', 'Cross-encoder answered a body that is not a JSON object/array.');
        }

        return $this->parse($decoded, count($passages));
    }

    /**
     * @param  array<mixed>  $decoded
     * @return list<float>
     */
    private function parse(array $decoded, int $expected): array
    {
        $rows = $decoded['results'] ?? $decoded['data'] ?? $decoded;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) !== $expected) {
            throw new CrossEncoderUnavailableException('bad_shape', sprintf('Expected %d scored passages, got a different shape/count.', $expected));
        }

        $byIndex = [];
        foreach ($rows as $row) {
            $score = is_array($row) ? ($row['relevance_score'] ?? $row['score'] ?? null) : null;
            if (! is_array($row) || ! is_int($row['index'] ?? null) || ! is_numeric($score)) {
                throw new CrossEncoderUnavailableException('bad_shape', 'A scored passage lacks an integer index or a numeric score.');
            }
            $index = $row['index'];
            $value = (float) $score;
            if ($index < 0 || $index >= $expected || isset($byIndex[$index]) || ! is_finite($value)) {
                throw new CrossEncoderUnavailableException('bad_shape', 'A scored passage has an out-of-range, duplicate or non-finite value.');
            }
            $byIndex[$index] = $value;
        }

        ksort($byIndex);
        $scores = array_values($byIndex);

        // Hosted APIs return 0..1; raw-logit servers return unbounded numbers.
        $needsSigmoid = min($scores) < 0.0 || max($scores) > 1.0;

        return array_map(
            static fn (float $s): float => round($needsSigmoid ? 1.0 / (1.0 + exp(-$s)) : $s, 6),
            $scores,
        );
    }

    private function assertAllowedEndpoint(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        if ($endpoint === '' || $parts === false || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new CrossEncoderUnavailableException('endpoint_invalid', 'Cross-encoder endpoint is missing or malformed (no userinfo/query/fragment allowed).');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme === '' || $host === '') {
            throw new CrossEncoderUnavailableException('endpoint_invalid', 'Cross-encoder endpoint has no scheme or host.');
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $hostPort = $host.':'.$port;

        $httpTolerated = $this->profileName === 'self_hosted' && (bool) ($this->profile['allow_http'] ?? false);
        if ($scheme !== 'https' && ! ($scheme === 'http' && $httpTolerated)) {
            throw new CrossEncoderUnavailableException('endpoint_scheme', 'Cross-encoder endpoint must be https (plain http is tolerated only for the self_hosted profile with allow_http).');
        }

        $allowed = array_values(array_filter(array_map(
            static fn ($h): string => strtolower(trim((string) $h)),
            (array) ($this->profile['allowed_hosts'] ?? []),
        )));
        // An entry is `host` (any port) or `host:port` (that port only).
        if ($host === '' || (! in_array($host, $allowed, true) && ! in_array($hostPort, $allowed, true))) {
            throw new CrossEncoderUnavailableException('endpoint_not_allowed', sprintf('Cross-encoder host "%s" is not in the profile allow-list.', $hostPort));
        }

        return $endpoint;
    }

    private function readBounded(StreamInterface $stream): string
    {
        $buffer = '';
        try {
            while (! $stream->eof()) {
                $chunk = $stream->read(65536);
                if ($chunk === '') {
                    break;
                }
                $buffer .= $chunk;
                if (strlen($buffer) > self::MAX_BODY_BYTES) {
                    throw new CrossEncoderUnavailableException('response_too_large', 'Cross-encoder response exceeds the size limit.');
                }
            }
        } finally {
            $stream->close();
        }

        return $buffer;
    }
}
