<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

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
 * Security: NEVER exposes credentials. `config_json` is deliberately omitted — it
 * can carry connection metadata (host/username) but is not part of the admin
 * list/return contract, and the secret lives only in the encrypted vault, never
 * here.
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
        return [
            'id' => $this->id,
            'status' => $this->status,
            'last_sync_at' => $this->last_sync_at?->toIso8601String(),
            'error' => $this->error_json,
        ];
    }
}
