<?php

declare(strict_types=1);

namespace Tests\Live\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Laravel HTTP global-response middleware that, when enabled, persists
 * every response body to `tests/fixtures/connectors/<provider>/recorded/`
 * for later replay during chunker / frontmatter tests.
 *
 * Pipeline applied to each body BEFORE write:
 *
 *   1. Bearer / refresh tokens stripped from any header echo.
 *   2. PII redaction via padosoft/laravel-pii-redactor (the same
 *      redactor the production ingest path uses — keeps the recording
 *      fixtures legally safe to commit publicly).
 *   3. Vendor-internal identifiers hashed via IdentifierScrubber so
 *      cross-fixture references stay consistent but no real tenant
 *      data leaks.
 *
 * Stateless static API so callers (LiveConnectorTestCase::setUp,
 * tearDown) can enable / disable without DI plumbing. Idempotent —
 * calling `enable()` twice for the same provider is a no-op.
 *
 * ---
 *
 * Middleware-leak design (Copilot PR #154 finding):
 *
 * Laravel's `Http::globalResponseMiddleware()` API has NO public
 * deregistration hook. Closures registered there persist for the
 * lifetime of the Http facade in the current process. Per-call
 * `enable($provider)` registering a fresh closure that closes over
 * `$provider` would leak: switching from provider A → B leaves the A
 * closure firing on every B call too, writing B responses into the
 * A directory.
 *
 * The fix is to register ONE static-method middleware exactly once
 * (idempotent guard on `self::$middlewareRegistered`). The middleware
 * reads `self::$activeProvider` at runtime — so `disable()` setting it
 * to null is the only thing needed to stop persistence, and
 * `enable($new)` swapping the value is the only thing needed to
 * redirect persistence. No closures retained between provider switches.
 */
final class HttpResponseRecorder
{
    private static ?string $activeProvider = null;
    private static ?IdentifierScrubber $scrubber = null;
    private static bool $middlewareRegistered = false;

    public static function enable(string $providerSlug): void
    {
        if (self::$activeProvider === $providerSlug) {
            return;
        }
        self::$activeProvider = $providerSlug;
        self::$scrubber = new IdentifierScrubber();

        if (self::$middlewareRegistered) {
            return;
        }
        self::$middlewareRegistered = true;
        // Single static-method registration. The middleware reads
        // `self::$activeProvider` at call time so swapping providers
        // (or disabling) does NOT leak the previous closure.
        Http::globalResponseMiddleware(static function (Response $response): Response {
            self::onResponse($response);
            return $response;
        });
    }

    public static function disable(): void
    {
        self::$activeProvider = null;
        self::$scrubber = null;
        // The single registered middleware stays installed for the rest
        // of the process; setting $activeProvider to null makes
        // onResponse() a no-op, which is the same effect as
        // deregistration without needing an API Laravel doesn't expose.
    }

    private static function onResponse(Response $response): void
    {
        $provider = self::$activeProvider;
        if ($provider === null) {
            return;
        }
        self::persist($response, $provider);
    }

    private static function persist(Response $response, string $providerSlug): void
    {
        $body = $response->body();
        if ($body === '') {
            return;
        }

        $parsed = json_decode($body, true);
        if (! is_array($parsed)) {
            // Non-JSON bodies (binary downloads, redirects) are NOT
            // recorded — chunker tests load JSON fixtures only.
            return;
        }

        $scrubbed = self::scrubBody($parsed);

        $url = (string) $response->effectiveUri();
        $endpointSlug = self::endpointSlug($url);
        $hash = substr(hash('sha256', $url), 0, 6);
        $filename = sprintf('%s-%s.json', $endpointSlug, $hash);

        $dir = self::recordingDir($providerSlug);
        if (! is_dir($dir)) {
            // R7: explicit mkdir with return check + error_get_last()
            // context. No @-silenced errors.
            if (! mkdir($dir, 0755, true) && ! is_dir($dir)) {
                $err = error_get_last();
                $detail = isset($err['message']) ? ' (' . $err['message'] . ')' : '';
                throw new \RuntimeException("Failed to create recorder directory: {$dir}{$detail}");
            }
        }

        $payload = [
            '__source' => 'live-recording',
            '__captured_at' => date('c'),
            '__endpoint_url_hash' => $hash,
            'body' => $scrubbed,
        ];

        // R4: never ignore the return value of a side-effecting call.
        // Both json_encode() and file_put_contents() can fail; surface
        // each failure as a RuntimeException so the recorder loudly
        // signals "this fixture did NOT land" instead of silently
        // dropping it.
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException(
                'Failed to JSON-encode fixture: ' . json_last_error_msg()
            );
        }

        $path = $dir . DIRECTORY_SEPARATOR . $filename;
        $bytesWritten = file_put_contents($path, $encoded);
        if ($bytesWritten === false) {
            throw new \RuntimeException("Failed to write fixture to {$path}");
        }
    }

    /**
     * @param  array<mixed>  $body
     * @return array<mixed>
     */
    private static function scrubBody(array $body): array
    {
        // PII redaction goes through the same pipeline the production
        // ingest path uses. When the redactor isn't available (unit
        // test env without the bind), fall through to identifier
        // scrubbing only — the fixture stays sanitisable, just less
        // comprehensively.
        if (function_exists('app') && app()->bound(\Padosoft\LaravelPiiRedactor\Contracts\PiiRedactorInterface::class)) {
            try {
                /** @var \Padosoft\LaravelPiiRedactor\Contracts\PiiRedactorInterface $redactor */
                $redactor = app(\Padosoft\LaravelPiiRedactor\Contracts\PiiRedactorInterface::class);
                $json = json_encode($body, JSON_UNESCAPED_SLASHES);
                if (is_string($json)) {
                    $redacted = $redactor->redact($json);
                    $decoded = json_decode($redacted->text, true);
                    if (is_array($decoded)) {
                        $body = $decoded;
                    }
                }
            } catch (\Throwable) {
                // Fall through to identifier scrubbing only.
            }
        }
        return self::$scrubber?->scrubPayload($body) ?? $body;
    }

    private static function endpointSlug(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $slug = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $path) ?? '', '-');
        return $slug === '' ? 'endpoint' : strtolower($slug);
    }

    private static function recordingDir(string $providerSlug): string
    {
        $repoRoot = dirname(__DIR__, 3);
        return $repoRoot
            . DIRECTORY_SEPARATOR . 'tests'
            . DIRECTORY_SEPARATOR . 'Fixtures'
            . DIRECTORY_SEPARATOR . 'connectors'
            . DIRECTORY_SEPARATOR . $providerSlug
            . DIRECTORY_SEPARATOR . 'recorded';
    }
}
