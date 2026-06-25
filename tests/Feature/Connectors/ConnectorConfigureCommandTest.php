<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Tests\TestCase;

/**
 * v8.25 — `connectors:configure` (R44 PHP write surface). Shows the connector's
 * settings schema + values, and writes `--set name=value` overrides into
 * config_json via the SAME core as the HTTP PATCH. Tenant-scoped (R30); unknown
 * field names are rejected (no silent typo'd config).
 */
final class ConnectorConfigureCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Keep permission/cache state deterministic across the suite (Spatie's
        // cache can survive RefreshDatabase rollbacks under Testbench).
        Cache::flush();
        app(TenantContext::class)->set('default');
    }

    private function imapInstallation(): ConnectorInstallation
    {
        return ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'imap',
            'label' => 'mbox',
            'config_json' => ['auth_mode' => 'basic', 'connection' => ['host' => 'h', 'username' => 'u']],
            'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);
    }

    public function test_show_prints_the_settings_schema(): void
    {
        $inst = $this->imapInstallation();

        $this->artisan('connectors:configure', ['installation' => $inst->id, '--tenant' => 'default', '--show' => true])
            ->expectsOutputToContain('folders.include')
            ->expectsOutputToContain('date_window_days')
            ->assertExitCode(0);
    }

    public function test_set_writes_overrides_into_config_json(): void
    {
        $inst = $this->imapInstallation();

        $this->artisan('connectors:configure', [
            'installation' => $inst->id,
            '--tenant' => 'default',
            '--set' => ['date_window_days=90', 'folders.exclude=Trash,Spam'],
        ])->assertExitCode(0);

        $inst->refresh();
        $this->assertSame(90, data_get($inst->config_json, 'date_window_days'));
        $this->assertSame(['Trash', 'Spam'], data_get($inst->config_json, 'folders.exclude'));
        // Connection config preserved.
        $this->assertSame('h', data_get($inst->config_json, 'connection.host'));
    }

    public function test_unknown_setting_is_rejected(): void
    {
        $inst = $this->imapInstallation();

        $this->artisan('connectors:configure', [
            'installation' => $inst->id,
            '--tenant' => 'default',
            '--set' => ['not_a_real_setting=1'],
        ])->assertExitCode(1);
    }

    public function test_invalid_value_for_a_typed_setting_is_rejected_not_coerced(): void
    {
        $inst = $this->imapInstallation();

        // A non-numeric value must fail fast, never silently become 0.
        $this->artisan('connectors:configure', [
            'installation' => $inst->id,
            '--tenant' => 'default',
            '--set' => ['date_window_days=not-a-number'],
        ])->assertExitCode(1);

        $inst->refresh();
        $this->assertArrayNotHasKey('date_window_days', (array) $inst->config_json);
    }

    public function test_missing_installation_is_rejected(): void
    {
        $this->artisan('connectors:configure', ['installation' => 999999, '--tenant' => 'default', '--show' => true])
            ->assertExitCode(1);
    }
}
