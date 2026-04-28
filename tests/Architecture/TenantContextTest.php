<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Support\TenantContext;
use Tests\TestCase;

/**
 * R30 — TenantContext singleton contract.
 *
 * Validates the request-scoped tenant_id holder used by every domain
 * Model's BelongsToTenant trait. Cross-tenant leak prevention happens
 * at two levels:
 *
 *   1. Write side: the BelongsToTenant trait fills `tenant_id` from
 *      this singleton on `creating`. If misused, it auto-assigns the
 *      WRONG tenant — but at least the row is tagged consistently.
 *
 *   2. Read side: query authors must pass `forTenant($id)` (or filter
 *      manually). This test does NOT cover read-side enforcement; that
 *      is a per-service concern audited via Copilot reviews.
 */
final class TenantContextTest extends TestCase
{
    public function test_default_tenant_is_default(): void
    {
        $ctx = $this->app->make(TenantContext::class);
        $ctx->reset();
        $this->assertSame('default', $ctx->current());
        $this->assertTrue($ctx->isDefault());
    }

    public function test_set_and_current_round_trip(): void
    {
        $ctx = $this->app->make(TenantContext::class);
        $ctx->set('lvr-store');
        $this->assertSame('lvr-store', $ctx->current());
        $this->assertFalse($ctx->isDefault());
    }

    public function test_set_rejects_uppercase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(TenantContext::class)->set('LVR-Store');
    }

    public function test_set_rejects_special_chars(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(TenantContext::class)->set('lvr/store');
    }

    public function test_set_rejects_too_long(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(TenantContext::class)->set(str_repeat('x', 51));
    }

    public function test_set_normalises_empty_to_default(): void
    {
        $ctx = $this->app->make(TenantContext::class);
        $ctx->set('lvr-store');
        $ctx->set('   ');
        $this->assertSame('default', $ctx->current());
    }

    public function test_singleton_is_request_scoped(): void
    {
        $a = $this->app->make(TenantContext::class);
        $b = $this->app->make(TenantContext::class);
        // Same instance within one request lifecycle.
        $this->assertSame($a, $b);
    }
}
