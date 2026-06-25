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

    public function test_empty_value_clears_a_nullable_number_back_to_default(): void
    {
        $inst = $this->imapInstallation();

        // Set an override, then clear it with an empty value — consistent with the
        // HTTP/UI behaviour where an emptied number field sends null.
        $this->artisan('connectors:configure', [
            'installation' => $inst->id,
            '--tenant' => 'default',
            '--set' => ['date_window_days=90'],
        ])->assertExitCode(0);
        $this->assertSame(90, data_get($inst->refresh()->config_json, 'date_window_days'));

        $this->artisan('connectors:configure', [
            'installation' => $inst->id,
            '--tenant' => 'default',
            '--set' => ['date_window_days='],
        ])->assertExitCode(0);

        $this->assertNull(data_get($inst->refresh()->config_json, 'date_window_days'));
    }

    public function test_out_of_range_number_is_rejected_matching_http_bounds(): void
    {
        $inst = $this->imapInstallation();

        // CLI must enforce the same min:0/max:1000000 the HTTP PATCH does (R44) —
        // no value the API would 422 may be persisted from the CLI.
        foreach (['date_window_days=-5', 'date_window_days=2000000'] as $bad) {
            $this->artisan('connectors:configure', [
                'installation' => $inst->id,
                '--tenant' => 'default',
                '--set' => [$bad],
            ])->assertExitCode(1);
        }

        $this->assertArrayNotHasKey('date_window_days', (array) $inst->refresh()->config_json);
    }

    public function test_set_on_a_connector_with_no_settings_fails_loudly(): void
    {
        // Notion exposes no editable settings (no SupportsConnectionSettings).
        $inst = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'notion',
            'label' => 'workspace',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);

        // --show is a benign no-op (exit 0), but --set must fail (exit 1) so
        // automation can't believe a value was written when nothing applies.
        $this->artisan('connectors:configure', [
            'installation' => $inst->id,
            '--tenant' => 'default',
            '--show' => true,
        ])->assertExitCode(0);

        $this->artisan('connectors:configure', [
            'installation' => $inst->id,
            '--tenant' => 'default',
            '--set' => ['anything=1'],
        ])->assertExitCode(1);
    }

    public function test_list_setting_enforces_the_same_item_length_as_http(): void
    {
        $inst = $this->imapInstallation();

        // A >255-char list item must be rejected (parity with the HTTP .* max:255).
        $this->artisan('connectors:configure', [
            'installation' => $inst->id,
            '--tenant' => 'default',
            '--set' => ['folders.exclude='.str_repeat('a', 256)],
        ])->assertExitCode(1);

        $this->assertArrayNotHasKey('folders', (array) $inst->refresh()->config_json);
    }

    public function test_empty_value_clears_a_nullable_select_back_to_default(): void
    {
        $inst = $this->imapInstallation();

        $this->artisan('connectors:configure', [
            'installation' => $inst->id,
            '--tenant' => 'default',
            '--set' => ['body_format=prefer_html'],
        ])->assertExitCode(0);
        $this->assertSame('prefer_html', data_get($inst->refresh()->config_json, 'body_format'));

        $this->artisan('connectors:configure', [
            'installation' => $inst->id,
            '--tenant' => 'default',
            '--set' => ['body_format='],
        ])->assertExitCode(0);

        $this->assertNull(data_get($inst->refresh()->config_json, 'body_format'));
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
