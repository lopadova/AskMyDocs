<?php

declare(strict_types=1);

namespace App\Mcp\Diagnostics;

use Illuminate\Http\Client\Response;
use Padosoft\AskMyDocsConnectorMcp\Contracts\SafeHttpClientContract;

/** Decorates the package SSRF-safe client with request-scoped diagnostics. */
final readonly class DiagnosticSafeHttpClient implements SafeHttpClientContract
{
    public function __construct(
        private SafeHttpClientContract $client,
        private McpConnectionDiagnosticContext $diagnostics,
    ) {}

    /** @param array<string, string> $headers */
    public function get(string $url, array $headers = [], bool $personal = true): Response
    {
        return $this->record('GET', $url, fn (): Response => $this->client->get($url, $headers, $personal));
    }

    /**
     * @param  array<string, mixed>  $form
     * @param  array<string, string>  $headers
     */
    public function postForm(string $url, array $form, array $headers = [], bool $personal = true): Response
    {
        return $this->record('POST_FORM', $url, fn (): Response => $this->client->postForm($url, $form, $headers, $personal));
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  array<string, string>  $headers
     */
    public function postJson(string $url, array $json, array $headers = [], bool $personal = true): Response
    {
        return $this->record('POST_JSON', $url, fn (): Response => $this->client->postJson($url, $json, $headers, $personal));
    }

    /** @param \Closure(): Response $request */
    private function record(string $method, string $url, \Closure $request): Response
    {
        $startedAt = hrtime(true);

        try {
            $response = $request();
            $this->diagnostics->recordResponse(
                $method,
                $url,
                $response->status(),
                $response->reason(),
                $response->header('Content-Type'),
                $this->elapsedMilliseconds($startedAt),
            );

            return $response;
        } catch (\Throwable $exception) {
            $this->diagnostics->recordException(
                $method,
                $url,
                $exception,
                $this->elapsedMilliseconds($startedAt),
            );

            throw $exception;
        }
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }
}
