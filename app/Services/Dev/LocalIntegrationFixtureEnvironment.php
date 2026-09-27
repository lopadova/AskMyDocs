<?php

declare(strict_types=1);

namespace App\Services\Dev;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/**
 * Narrow, local-only feature gate for the deterministic API + MCP fixtures.
 *
 * The mock servers are intentionally loopback-only, but the two production
 * connector packages correctly reject loopback HTTP by default. Enabling this
 * flag is therefore an explicit local-development action: it must never make
 * it into a deployed environment and it does not alter any remote endpoint.
 */
final readonly class LocalIntegrationFixtureEnvironment
{
    public const FLAG = 'LOCAL_INTEGRATION_FIXTURES_ENABLED';

    public const API_BASE_URL = 'http://127.0.0.1:4310';

    public const MCP_BASE_URL = 'http://127.0.0.1:4311/mcp';

    public function __construct(private Filesystem $files) {}

    public static function enabled(): bool
    {
        return env('APP_ENV') === 'local'
            && filter_var(env(self::FLAG, false), FILTER_VALIDATE_BOOL);
    }

    public function assertLocal(): void
    {
        if (env('APP_ENV') !== 'local') {
            throw new RuntimeException('The local integration fixtures can run only when APP_ENV=local.');
        }
    }

    /**
     * Persist the narrow local feature gate in the current environment file.
     *
     * Replacing only this exact key keeps manually maintained .env settings
     * intact and makes subsequent Herd requests observe the same safe local
     * connector policy. The caller must have passed assertLocal() first.
     */
    public function enable(string $environmentFile): void
    {
        $this->assertLocal();

        if (! $this->files->exists($environmentFile)) {
            throw new RuntimeException("Environment file not found: {$environmentFile}");
        }

        $contents = $this->files->get($environmentFile);
        $line = self::FLAG.'=true';
        $pattern = '/^\s*'.preg_quote(self::FLAG, '/').'\s*=.*$/m';
        $updated = preg_match($pattern, $contents) === 1
            ? (string) preg_replace($pattern, $line, $contents)
            : rtrim($contents, "\r\n").PHP_EOL.$line.PHP_EOL;

        if ($updated !== $contents) {
            $this->files->put($environmentFile, $updated);
        }

        putenv(self::FLAG.'=true');
        $_ENV[self::FLAG] = 'true';
        $_SERVER[self::FLAG] = 'true';
    }

    public static function apiBaseUrl(): string
    {
        return rtrim((string) env('LOCAL_INTEGRATIONS_API_BASE_URL', self::API_BASE_URL), '/');
    }

    public static function mcpEndpointFor(string $tenantId): string
    {
        return rtrim((string) env('LOCAL_INTEGRATIONS_MCP_BASE_URL', self::MCP_BASE_URL), '/')
            .'/'.rawurlencode($tenantId);
    }
}
