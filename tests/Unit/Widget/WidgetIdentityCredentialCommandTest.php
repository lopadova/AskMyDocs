<?php

declare(strict_types=1);

namespace Tests\Unit\Widget;

use App\Models\AdminCommandAudit;
use App\Models\WidgetKey;
use App\Services\Widget\WidgetIdentityCredentialAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WidgetIdentityCredentialCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_reports_non_sensitive_metadata(): void
    {
        $key = WidgetKey::factory()->create([
            'identity_credential_version' => 2,
            'user_auth_enabled' => true,
            'identity_secret_hash' => bcrypt('ik_never_print'),
        ]);

        $this->artisan('widget:identity-credential', [
            'action' => 'status',
            'key' => $key->id,
            '--tenant' => 'default',
        ])
            ->expectsTable(
                ['Key', 'Tenant', 'Project', 'Enabled', 'Version'],
                [[$key->id, 'default', $key->project_key, 'yes', 2]],
            )
            ->doesntExpectOutputToContain('ik_never_print')
            ->assertSuccessful();
    }

    public function test_enable_and_rotate_use_the_shared_audited_core(): void
    {
        $key = WidgetKey::factory()->create([
            'identity_credential_version' => 0,
            'user_auth_enabled' => false,
            'identity_secret_hash' => null,
        ]);

        $this->artisan('widget:identity-credential', [
            'action' => 'enable',
            'key' => $key->id,
            '--tenant' => 'default',
            '--expected-version' => 0,
            '--force' => true,
        ])
            ->expectsOutputToContain('version is now 1')
            ->expectsOutputToContain('Identity secret: ik_')
            ->assertSuccessful();

        $this->artisan('widget:identity-credential', [
            'action' => 'rotate',
            'key' => $key->id,
            '--tenant' => 'default',
            '--expected-version' => 1,
            '--force' => true,
        ])
            ->expectsOutputToContain('version is now 2')
            ->expectsOutputToContain('Identity secret: ik_')
            ->assertSuccessful();

        $this->assertDatabaseCount('admin_command_audit', 3);
        $this->assertDatabaseHas('admin_command_audit', [
            'command' => WidgetIdentityCredentialAudit::COMMAND,
            'status' => AdminCommandAudit::STATUS_COMPLETED,
        ]);
    }

    public function test_mutation_requires_an_expected_version(): void
    {
        $key = WidgetKey::factory()->create();

        $this->artisan('widget:identity-credential', [
            'action' => 'enable',
            'key' => $key->id,
            '--tenant' => 'default',
            '--force' => true,
        ])
            ->expectsOutputToContain('--expected-version is required')
            ->assertExitCode(2);

        $this->assertFalse($key->fresh()->user_auth_enabled);
        $this->assertDatabaseCount('admin_command_audit', 0);
    }
}
