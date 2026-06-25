<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Services\Admin\Connectors\ConnectorSettingsService;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Tests\TestCase;

/**
 * v8.25 — the shared settings core (R44). Resolves a connector's editable schema,
 * the current value of each field (config_json value, else schema default), and
 * merges a settings payload into config_json overwriting only schema fields.
 */
final class ConnectorSettingsServiceTest extends TestCase
{
    private function service(): ConnectorSettingsService
    {
        return app(ConnectorSettingsService::class);
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function imap(array $config = []): ConnectorInstallation
    {
        return new ConnectorInstallation(['connector_name' => 'imap', 'config_json' => $config]);
    }

    public function test_schema_for_imap_is_the_full_editable_surface(): void
    {
        $names = array_map(static fn ($f) => $f['name'], $this->service()->schemaFor($this->imap()));

        foreach (['folders.include', 'folders.exclude', 'date_window_days', 'senders.exclude'] as $expected) {
            $this->assertContains($expected, $names);
        }
        $this->assertTrue($this->service()->supports($this->imap()));
    }

    public function test_a_non_settings_connector_has_an_empty_schema(): void
    {
        $gdrive = new ConnectorInstallation(['connector_name' => 'google-drive', 'config_json' => []]);

        $this->assertSame([], $this->service()->schemaFor($gdrive));
        $this->assertFalse($this->service()->supports($gdrive));
    }

    public function test_current_settings_prefer_config_then_fall_back_to_schema_default(): void
    {
        $current = $this->service()->currentSettings($this->imap(['date_window_days' => 30]));

        // Stored value wins...
        $this->assertSame(30, data_get($current, 'date_window_days'));
        // ...absent value falls back to the schema default (the engine default list).
        $this->assertSame(
            ['Trash', 'Spam', 'Junk', '[Gmail]/Spam', '[Gmail]/Trash'],
            data_get($current, 'folders.exclude'),
        );
    }

    public function test_merge_overwrites_only_schema_fields_and_preserves_the_rest(): void
    {
        $inst = $this->imap([
            'connection' => ['host' => 'h'],
            'folders' => ['include' => ['Old']],
        ]);

        $next = $this->service()->mergeIntoConfig($inst, [
            'folders' => ['include' => ['INBOX', 'Sent']],
            'date_window_days' => 45,
        ]);

        // List value is replaced whole (not element-merged).
        $this->assertSame(['INBOX', 'Sent'], data_get($next, 'folders.include'));
        $this->assertSame(45, data_get($next, 'date_window_days'));
        // Connection config the operator never sees is preserved untouched.
        $this->assertSame('h', data_get($next, 'connection.host'));
    }

    public function test_merge_ignores_keys_that_are_not_schema_fields(): void
    {
        $next = $this->service()->mergeIntoConfig($this->imap(), [
            'date_window_days' => 10,
            'not_a_setting' => 'evil',
        ]);

        $this->assertSame(10, data_get($next, 'date_window_days'));
        $this->assertArrayNotHasKey('not_a_setting', $next);
    }

    public function test_merge_null_clears_the_override_back_to_default(): void
    {
        $inst = $this->imap(['date_window_days' => 90]);

        // A present-but-null value must REMOVE the key (clear → connector default),
        // not leave an explicit null — otherwise currentSettings() can't fall back
        // to the schema default.
        $next = $this->service()->mergeIntoConfig($inst, ['date_window_days' => null]);

        $this->assertArrayNotHasKey('date_window_days', $next);

        // And currentSettings on the cleared row reports the schema default again.
        $inst->config_json = $next;
        $current = $this->service()->currentSettings($inst);
        $this->assertNotNull(data_get($current, 'date_window_days'));
    }
}
