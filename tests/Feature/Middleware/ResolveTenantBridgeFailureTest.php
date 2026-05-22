<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Compliance\TenantContextBridge;
use App\Http\Middleware\ResolveTenant;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * v8.0.2 / deep-review D — when the TenantContextBridge throws (DB
 * outage, package model drift, schema misalignment), the
 * ResolveTenant middleware must NOT swallow the exception
 * silently. A bare `catch (Throwable) {}` was fail-open compliance:
 * per-tenant policy resolution on the AI Act package would silently
 * fall back to the host config block, with NO log line for the
 * operator to find post-incident.
 *
 * These tests bind a fake TenantContextBridge that throws, drive
 * one request through the middleware, and assert:
 *   - the request still succeeds (best-effort behaviour preserved);
 *   - report() was invoked (asserted indirectly via Log::shouldReceive
 *     on the warning level, since report() forwards to the configured
 *     handler — testing it directly requires mocking the global helper);
 *   - the Log::warning() entry carries the tenant_id + exception
 *     class + message in its context array so the operator can
 *     correlate the silent fall-back to a real root cause.
 */
final class ResolveTenantBridgeFailureTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_middleware_logs_warning_and_reports_when_bridge_throws(): void
    {
        // Exceptions::fake() captures every report()-routed
        // exception so we can assert on the routed contract without
        // letting the real exception handler keep its hooks (which
        // PHPUnit flags as risky if we leave them in place).
        Exceptions::fake();
        $this->bindFailingBridge();

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                $messageOk = str_contains($message, 'TenantContextBridge::syncFromHost() failed');
                $tenantOk = ($context['tenant_id'] ?? null) === 'default';
                $exceptionOk = ($context['exception'] ?? null) === RuntimeException::class;
                $hasMessage = isset($context['message']) && $context['message'] === 'bridge-down-simulated';
                return $messageOk && $tenantOk && $exceptionOk && $hasMessage;
            });

        $request = Request::create('/anywhere');
        $response = app(ResolveTenant::class)->handle($request, fn () => response('ok'));

        $this->assertSame(200, $response->getStatusCode(), 'D: request must still succeed when the bridge throws — host tenant scoping continues.');
        $this->assertSame('default', app(TenantContext::class)->current(), 'D: host TenantContext must still be set even when bridge fails.');

        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_middleware_does_not_log_when_bridge_succeeds(): void
    {
        Exceptions::fake();
        $this->bindHealthyBridge();

        Log::shouldReceive('warning')->never();

        $request = Request::create('/anywhere');
        $response = app(ResolveTenant::class)->handle($request, fn () => response('ok'));

        $this->assertSame(200, $response->getStatusCode());
        Exceptions::assertNothingReported();
    }

    private function bindFailingBridge(): void
    {
        // TenantContextBridge is marked `final`, so Mockery can't
        // proxy it. We bind a duck-typed anonymous-class stub
        // instead — the middleware calls `->syncFromHost()` via
        // `app(...)`, and the container hands back whatever we
        // instanced, regardless of type. Sufficient for asserting
        // the catch-block behaviour without subclassing a final
        // class.
        $this->app->instance(TenantContextBridge::class, new class {
            public function syncFromHost(): void
            {
                throw new RuntimeException('bridge-down-simulated');
            }
        });
    }

    private function bindHealthyBridge(): void
    {
        $this->app->instance(TenantContextBridge::class, new class {
            public function syncFromHost(): ?\stdClass
            {
                return null;
            }
        });
    }
}
