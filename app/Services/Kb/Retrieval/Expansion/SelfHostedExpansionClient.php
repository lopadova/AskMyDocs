<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval\Expansion;

use App\Support\Http\AllowListedEndpoint;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

/**
 * W6 (qmd parity — "local models") — the `self_hosted` transport of typed
 * query expansion: an OpenAI-compatible `/chat/completions` endpoint run by the
 * operator (vLLM, llama.cpp server, Ollama's OpenAI API, TGI …), so expansion
 * can run with NO third-party processor, the way qmd runs its local model.
 *
 * Same posture as the cross-encoder's self-hosted profile (ADR 0035 §D4):
 *  - the endpoint must pass {@see AllowListedEndpoint} against the profile's
 *    EXACT host allow-list (SEC-SSRF-001); plain http only with `allow_http`;
 *  - no redirects, bounded connect/total timeouts, a bounded response body,
 *    a JSON content type and the OpenAI `choices[0].message.content` shape —
 *    anything else is a failure with a machine reason (R14);
 *  - the caller ({@see QueryExpansionService}) has already PII-masked the
 *    query and wraps it in the fixed delimited prompt; this class adds nothing
 *    to the text and logs nothing of it.
 *
 * No FinOps ledger row: the endpoint is the operator's own hardware (no
 * per-call spend); the call count is still visible through the expansion meta.
 */
final class SelfHostedExpansionClient
{
    private const MAX_BODY_BYTES = 65_536;

    /** @param  array<string,mixed>  $profile */
    public function __construct(private readonly array $profile, private readonly int $timeoutSeconds = 10) {}

    public function model(): string
    {
        return trim((string) ($this->profile['model'] ?? ''));
    }

    /** @throws RuntimeException with a machine reason as message */
    public function complete(string $system, string $user): string
    {
        $endpoint = trim((string) ($this->profile['endpoint'] ?? ''));
        $violation = AllowListedEndpoint::violation($endpoint, (array) ($this->profile['allowed_hosts'] ?? []), (bool) ($this->profile['allow_http'] ?? false));
        if ($violation !== null) {
            throw new RuntimeException($violation);
        }
        if ($this->model() === '' || preg_match('#^[A-Za-z0-9._:/-]{1,100}$#', $this->model()) !== 1) {
            throw new RuntimeException('invalid_model');
        }

        try {
            $request = Http::acceptJson()->asJson()->withoutRedirecting()
                ->timeout(max(1, $this->timeoutSeconds))
                ->connectTimeout(max(1, min(5, $this->timeoutSeconds)))
                ->withOptions(['stream' => true]);
            $key = trim((string) ($this->profile['api_key'] ?? ''));
            if ($key !== '') {
                $request = $request->withToken($key);
            }
            $response = $request->post($endpoint, [
                'model' => $this->model(),
                'temperature' => 0.2,
                'max_tokens' => 400,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
            ]);
        } catch (Throwable) {
            throw new RuntimeException('transport');
        }

        if (! $response->successful()) {
            throw new RuntimeException('http_'.$response->status());
        }
        // Only a declared JSON answer is parsed (no sniffing an untyped body).
        if (! str_contains(strtolower((string) $response->header('Content-Type')), 'json')) {
            throw new RuntimeException('bad_content_type');
        }
        $body = $this->readBounded($response->toPsrResponse()->getBody());
        $content = data_get(json_decode($body, true), 'choices.0.message.content');
        if (! is_string($content) || $content === '') {
            throw new RuntimeException('bad_shape');
        }

        return $content;
    }

    /** Read at most MAX_BODY_BYTES, never the whole body into memory first. */
    private function readBounded(StreamInterface $stream): string
    {
        $buffer = '';
        try {
            while (! $stream->eof()) {
                $chunk = $stream->read(8192);
                if ($chunk === '') {
                    break;
                }
                $buffer .= $chunk;
                if (strlen($buffer) > self::MAX_BODY_BYTES) {
                    throw new RuntimeException('response_too_large');
                }
            }
        } finally {
            $stream->close();
        }

        return $buffer;
    }
}
