<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Runtime\McpRuntimeGate;
use App\Services\Admin\AppSettingsResolver;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class McpRuntimeGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_deploy_switch_is_a_hard_off_gate(): void
    {
        config()->set('connector-mcp.enabled', false);
        config()->set('connector-mcp.runtime_mode', 'active');

        $gate = app(McpRuntimeGate::class);

        $this->assertSame(McpRuntimeGate::OFF, $gate->mode());
        $this->assertFalse($gate->usesConnector());
        $this->assertTrue($gate->usesLegacy());
    }

    public function test_runtime_modes_are_isolated_per_tenant(): void
    {
        config()->set('connector-mcp.enabled', true);
        config()->set('connector-mcp.runtime_mode', 'off');
        $settings = app(AppSettingsResolver::class);
        $settings->set('connector.mcp.runtime_mode', 'shadow', 'tenant-a');
        $settings->set('connector.mcp.runtime_mode', 'active', 'tenant-b');

        $gate = app(McpRuntimeGate::class);

        $this->assertSame(McpRuntimeGate::SHADOW, $gate->mode('tenant-a'));
        $this->assertTrue($gate->runsShadow('tenant-a'));
        $this->assertTrue($gate->usesLegacy('tenant-a'));
        $this->assertFalse($gate->usesConnector('tenant-a'));

        $this->assertSame(McpRuntimeGate::ACTIVE, $gate->mode('tenant-b'));
        $this->assertFalse($gate->usesLegacy('tenant-b'));
        $this->assertTrue($gate->usesConnector('tenant-b'));
    }

    public function test_current_tenant_is_used_when_no_tenant_is_passed(): void
    {
        config()->set('connector-mcp.enabled', true);
        config()->set('connector-mcp.runtime_mode', 'off');
        app(AppSettingsResolver::class)->set('connector.mcp.runtime_mode', 'active', 'acme');
        app(TenantContext::class)->set('acme');

        $this->assertSame(McpRuntimeGate::ACTIVE, app(McpRuntimeGate::class)->mode());
    }
}
