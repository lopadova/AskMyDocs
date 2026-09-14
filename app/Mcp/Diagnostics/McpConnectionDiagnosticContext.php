<?php

declare(strict_types=1);

namespace App\Mcp\Diagnostics;

use Illuminate\Support\Str;

/**
 * Request-scoped trace of the HTTP probes performed while connecting an MCP
 * server. Payloads and headers are deliberately never captured: OAuth tokens,
 * Bearer credentials and client secrets must not reach logs or the browser.
 */
final class McpConnectionDiagnosticContext
{
    private ?string $id = null;

    /** @var list<array<string, mixed>> */
    private array $outboundAttempts = [];

    public function id(): string
    {
        return $this->id ??= (string) Str::ulid();
    }

    public function recordResponse(
        string $method,
        string $url,
        int $status,
        ?string $reason,
        ?string $contentType,
        int $durationMs,
    ): void
    {
        $this->outboundAttempts[] = [
            'method' => $this->displayMethod($method),
            'url' => $this->sanitizeUrl($url),
            'status' => $status,
            'reason' => $reason ?: null,
            'content_type' => $contentType ?: null,
            'duration_ms' => $durationMs,
        ];
    }

    public function recordException(string $method, string $url, \Throwable $exception, int $durationMs): void
    {
        $this->outboundAttempts[] = [
            'method' => $this->displayMethod($method),
            'url' => $this->sanitizeUrl($url),
            'status' => null,
            'duration_ms' => $durationMs,
            'exception' => class_basename($exception),
            'detail' => $this->sanitizeText($exception->getMessage()),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function outboundAttempts(): array
    {
        return $this->outboundAttempts;
    }

    public function sanitizeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '[invalid URL]';
        }

        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
        $path = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';

        return strtolower((string) $parts['scheme']).'://'.strtolower((string) $parts['host']).$port.$path;
    }

    public function sanitizeText(string $message): string
    {
        $message = preg_replace_callback(
            '~https?://[^\s<>"\']+~i',
            fn (array $match): string => $this->sanitizeUrl($match[0]),
            $message,
        ) ?? $message;
        $message = preg_replace('/(Bearer\s+)[^\s,;]+/i', '$1[redacted]', $message) ?? $message;
        $message = preg_replace(
            '/((?:access_token|refresh_token|client_secret|api_key|authorization|cookie|credential|code|state|token|password|secret)\s*[=:]\s*)[^\s,;&]+/i',
            '$1[redacted]',
            $message,
        ) ?? $message;
        $message = preg_replace(
            '/([?&](?:access_token|refresh_token|client_secret|api_key|authorization|cookie|credential|code|state|token|password|secret)=)[^&\s]+/i',
            '$1[redacted]',
            $message,
        ) ?? $message;

        return mb_substr($message, 0, 4000);
    }

    private function displayMethod(string $method): string
    {
        return str_replace('_FORM', '', str_replace('_JSON', '', strtoupper($method)));
    }
}
