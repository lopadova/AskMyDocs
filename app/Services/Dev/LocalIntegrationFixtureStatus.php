<?php

declare(strict_types=1);

namespace App\Services\Dev;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Read-only status and telemetry bridge for the loopback-only Node fixtures.
 * The browser never receives their ports directly: Laravel performs the local
 * probes and returns an intentionally small, credential-free projection.
 */
final class LocalIntegrationFixtureStatus
{
    private const LOOPBACK_HOSTS = ['127.0.0.1', '::1', 'localhost'];

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $apiBaseUrl = LocalIntegrationFixtureEnvironment::apiBaseUrl();
        $mcpOrigin = $this->originFor(LocalIntegrationFixtureEnvironment::mcpEndpointFor('status'));

        return [
            'local_only' => true,
            'fixture_gate_enabled' => LocalIntegrationFixtureEnvironment::enabled(),
            'refreshed_at' => now()->toIso8601String(),
            'api' => $this->probe($apiBaseUrl, 'askmydocs-local-api-fixture'),
            'mcp' => $this->probe($mcpOrigin, 'askmydocs-local-mcp-fixture'),
        ];
    }

    /**
     * @return array{state:'running'|'stopped'|'degraded',health:array<string,mixed>|null,metrics:array<string,mixed>|null,error:?string}
     */
    private function probe(string $baseUrl, string $expectedService): array
    {
        $baseUrl = rtrim($baseUrl, '/');
        $this->assertLoopbackUrl($baseUrl);

        try {
            $healthResponse = Http::acceptJson()->connectTimeout(1)->timeout(2)->get($baseUrl.'/health');
        } catch (ConnectionException) {
            return $this->stopped();
        } catch (Throwable) {
            return $this->stopped();
        }

        $health = $healthResponse->json();
        if (! $healthResponse->successful() || ! is_array($health) || ($health['service'] ?? null) !== $expectedService) {
            return [
                'state' => 'degraded',
                'health' => is_array($health) ? $health : null,
                'metrics' => null,
                'error' => 'The local service did not return the expected health response.',
            ];
        }

        try {
            $metricsResponse = Http::acceptJson()->connectTimeout(1)->timeout(2)->get($baseUrl.'/_dev/metrics');
            $metrics = $metricsResponse->json();
        } catch (Throwable) {
            $metricsResponse = null;
            $metrics = null;
        }

        if ($metricsResponse === null || ! $metricsResponse->successful() || ! is_array($metrics)) {
            return [
                'state' => 'degraded',
                'health' => $health,
                'metrics' => null,
                'error' => 'The local service is healthy but its development telemetry is unavailable.',
            ];
        }

        return [
            'state' => 'running',
            'health' => $health,
            'metrics' => $metrics,
            'error' => null,
        ];
    }

    /** @return array{state:'stopped',health:null,metrics:null,error:string} */
    private function stopped(): array
    {
        return [
            'state' => 'stopped',
            'health' => null,
            'metrics' => null,
            'error' => 'The local process is not reachable.',
        ];
    }

    private function originFor(string $url): string
    {
        $this->assertLoopbackUrl($url);
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('The local MCP fixture URL is invalid.');
        }

        $host = str_contains($parts['host'], ':') ? '['.$parts['host'].']' : $parts['host'];
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $parts['scheme'].'://'.$host.$port;
    }

    private function assertLoopbackUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;

        if (! is_string($host) || ! in_array($host, self::LOOPBACK_HOSTS, true) || ! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Local integration status may probe loopback HTTP services only.');
        }
    }
}
