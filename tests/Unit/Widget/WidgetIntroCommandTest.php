<?php

declare(strict_types=1);

namespace Tests\Unit\Widget;

use App\Models\WidgetKey;
use Tests\TestCase;

final class WidgetIntroCommandTest extends TestCase
{
    public function test_command_updates_and_disables_intro_for_the_selected_tenant(): void
    {
        $key = WidgetKey::query()->create([
            'tenant_id' => 'test-tenant',
            'project_key' => 'p',
            'public_key' => 'pk_intro_command',
            'label' => 'Command intro',
            'allowed_origins' => [],
            'rate_limit' => 60,
            'skill' => 'askmydocs-assistant@1',
            'is_active' => true,
        ]);

        $this->artisan('widget:intro', [
            'key' => $key->id,
            '--tenant' => 'test-tenant',
            '--json' => json_encode(['enabled' => true, 'title' => 'Welcome']),
        ])->assertSuccessful()->expectsOutputToContain('"title": "Welcome"');
        $this->assertTrue($key->fresh()->intro_config['enabled']);

        $this->artisan('widget:intro', [
            'key' => $key->id,
            '--tenant' => 'test-tenant',
            '--disable' => true,
        ])->assertSuccessful()->expectsOutputToContain('"enabled": false');
        $this->assertFalse($key->fresh()->intro_config['enabled']);
    }
}
