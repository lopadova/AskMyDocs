<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\WidgetIntroConfigTool;
use App\Models\WidgetKey;
use App\Services\Widget\WidgetIntroService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Tests\TestCase;

final class WidgetIntroConfigToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_only_the_active_tenants_widget_intro(): void
    {
        $key = WidgetKey::query()->create([
            'tenant_id' => 'tenant-a',
            'project_key' => 'docs',
            'public_key' => 'pk_mcp_intro',
            'label' => 'Docs assistant',
            'allowed_origins' => [],
            'rate_limit' => 60,
            'skill' => 'askmydocs-assistant@1',
            'intro_config' => ['enabled' => true, 'title' => 'Ask the docs'],
            'is_active' => true,
        ]);

        $tenants = app(TenantContext::class);
        $tenants->set('tenant-a');
        $response = (new WidgetIntroConfigTool())->handle(
            new Request(['widget_key_id' => $key->id]),
            app(WidgetIntroService::class),
            $tenants,
        );
        $payload = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('Ask the docs', $payload['intro']['title']);
        $this->assertTrue($payload['intro']['enabled']);

        $tenants->set('tenant-b');
        $foreign = (new WidgetIntroConfigTool())->handle(
            new Request(['widget_key_id' => $key->id]),
            app(WidgetIntroService::class),
            $tenants,
        );
        $this->assertArrayHasKey(
            'error',
            json_decode((string) $foreign->content(), true, flags: JSON_THROW_ON_ERROR),
        );
    }
}
