<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Services\Admin\Connectors\ConnectorSettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;

/**
 * v8.17 — the public shape of a connector installation row. The installation
 * fields ({id, status, last_sync_at, error}) are identical across the `index`
 * listing and the `configure` response so the FE reads one contract
 * (R: route-contracts-match-fe-shape); the `configure` response merges one extra
 * sibling key, `redirect_to` (xoauth2 only), onto these fields.
 *
 * Security: NEVER exposes credentials. The bulk of `config_json` (connection
 * host/username) is deliberately omitted; the secret lives only in the encrypted
 * vault, never here. v8.24 surfaces ONLY the picker-owned sub-keys
 * (`folders.include` + `date_window_days`) so the edit form can pre-fill them —
 * kept in lockstep with {@see \App\Services\Admin\Connectors\ConnectorInstallationService::installationArray}
 * (R27 additive).
 *
 * @mixin ConnectorInstallation
 */
final class ConnectorInstallationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $config = (array) ($this->config_json ?? []);
        $include = (array) (($config['folders'] ?? [])['include'] ?? []);
        // Resolve the settings core once (used for both keys below).
        $settings = app(ConnectorSettingsService::class);

        return [
            'id' => $this->id,
            // v8.20 — multi-account: `label` disambiguates the N accounts a
            // tenant connects on the same connector; `project_key` is the
            // optional KB project binding (null = the tenant default).
            'label' => $this->label,
            'project_key' => $this->project_key,
            'status' => $this->status,
            'last_sync_at' => $this->last_sync_at?->toIso8601String(),
            'error' => $this->error_json,
            // v8.24 — picker-owned connection settings (NEVER connection/secret).
            'folders' => ['include' => array_values(array_map('strval', $include))],
            'date_window_days' => isset($config['date_window_days']) ? (int) $config['date_window_days'] : null,
            // v8.25 (R27 additive) — the connector's FULL editable settings schema +
            // current values (the schema-driven editor). [] when the connector
            // advertises none. Resolved here to keep this in lockstep with
            // ConnectorInstallationService::installationArray (one contract, R44).
            'connection_settings_schema' => $settings->schemaFor($this->resource),
            'settings' => $settings->currentSettings($this->resource),
        ];
    }
}
