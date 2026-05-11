<?php

declare(strict_types=1);

namespace App\Connectors\Auth;

use App\Models\ConnectorCredential;
use App\Models\ConnectorInstallation;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * v4.5/W1 — Encrypted-at-rest OAuth credential vault.
 *
 * Single chokepoint for every connector that needs to read or write
 * its access / refresh tokens. Connectors NEVER touch
 * {@see ConnectorCredential} directly — going through the vault
 * guarantees:
 *
 *   1. Tokens are encrypted with Laravel `Crypt` (AES-256-CBC + HMAC)
 *      before persisting and decrypted on retrieval. The DB row never
 *      sees plaintext.
 *   2. Every query is scoped to the active tenant (R30). The vault
 *      reads `TenantContext::current()` and rejects cross-tenant
 *      reads even when the caller passes an arbitrary
 *      `$installationId`.
 *   3. Stale access tokens (`expires_at < now()`) are returned as
 *      `null` from {@see getAccessToken()}, so callers see "credential
 *      missing" semantics instead of an expired token they might leak
 *      to the upstream. Refresh is the connector's responsibility —
 *      the vault provides {@see setCredentials()} for the rotated pair.
 *
 * The vault does NOT itself call the provider's refresh endpoint —
 * each connector knows its own refresh contract and is the only
 * party that can drive it. {@see \App\Connectors\BaseConnector::refreshTokenIfExpired()}
 * is the standard helper that orchestrates `getAccessToken()` →
 * provider refresh call → `setCredentials()` round-trip.
 */
class OAuthCredentialVault
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Returns the decrypted access token for the installation if it
     * exists AND is not expired. Returns `null` otherwise — the caller
     * MUST treat this as a "must refresh" signal, not as a permanent
     * disconnect (rows with `expires_at IN THE PAST` are still
     * recoverable via the refresh token).
     */
    public function getAccessToken(int $installationId): ?string
    {
        $row = $this->findCredential($installationId);
        if ($row === null) {
            return null;
        }

        if ($row->isExpired()) {
            return null;
        }

        return Crypt::decryptString($row->encrypted_access_token);
    }

    /**
     * Returns the decrypted refresh token, regardless of expiry. Used
     * by the per-connector refresh dance to mint a fresh access token
     * when the access token is stale.
     */
    public function getRefreshToken(int $installationId): ?string
    {
        $row = $this->findCredential($installationId);
        if ($row === null || $row->encrypted_refresh_token === null) {
            return null;
        }

        return Crypt::decryptString($row->encrypted_refresh_token);
    }

    /**
     * Returns the raw credential row (still encrypted). Useful for
     * inspectors / audit trails. Do NOT decrypt the access/refresh
     * tokens here — go through `getAccessToken()` /
     * `getRefreshToken()` instead so logging / dumping the result
     * never leaks plaintext.
     */
    public function getCredentialRow(int $installationId): ?ConnectorCredential
    {
        return $this->findCredential($installationId);
    }

    /**
     * @return array<string,mixed>
     */
    public function getExtra(int $installationId): array
    {
        $row = $this->findCredential($installationId);
        if ($row === null) {
            return [];
        }

        return $row->extra_json ?? [];
    }

    /**
     * Persist or rotate the credential pair for the installation.
     * Encrypts BOTH tokens before write. `$extra` carries provider-
     * specific metadata (e.g. Notion `bot_id`, Atlassian `cloudId`).
     *
     * @param  array<string,mixed>  $extra
     */
    public function setCredentials(
        int $installationId,
        string $accessToken,
        ?string $refreshToken = null,
        ?Carbon $expiresAt = null,
        array $extra = [],
    ): void {
        $installation = $this->findInstallation($installationId);
        if ($installation === null) {
            throw new \InvalidArgumentException(
                "Installation {$installationId} not found (or belongs to a different tenant)."
            );
        }

        $tenantId = $installation->tenant_id;

        ConnectorCredential::query()->updateOrCreate(
            ['connector_installation_id' => $installationId],
            [
                'tenant_id' => $tenantId,
                'encrypted_access_token' => Crypt::encryptString($accessToken),
                'encrypted_refresh_token' => $refreshToken === null
                    ? null
                    : Crypt::encryptString($refreshToken),
                'expires_at' => $expiresAt,
                'extra_json' => $extra === [] ? null : $extra,
            ]
        );
    }

    /**
     * Remove the credential row for the installation. Safe to call
     * when no row exists. Returns the number of rows deleted (0 or 1).
     */
    public function clearCredentials(int $installationId): int
    {
        $installation = $this->findInstallation($installationId);
        if ($installation === null) {
            return 0;
        }

        return ConnectorCredential::query()
            ->where('connector_installation_id', $installationId)
            ->where('tenant_id', $installation->tenant_id)
            ->delete();
    }

    /**
     * Lookup helper — always scoped to the active tenant. Returns
     * `null` if the installation does not exist OR belongs to a
     * different tenant. Cross-tenant access is silent (returns null)
     * rather than throwing because the controller layer is the
     * authoritative authorization boundary; the vault is a
     * defence-in-depth secondary check.
     */
    private function findInstallation(int $installationId): ?ConnectorInstallation
    {
        return ConnectorInstallation::query()
            ->where('id', $installationId)
            ->where('tenant_id', $this->tenantContext->current())
            ->first();
    }

    private function findCredential(int $installationId): ?ConnectorCredential
    {
        $installation = $this->findInstallation($installationId);
        if ($installation === null) {
            return null;
        }

        return ConnectorCredential::query()
            ->where('connector_installation_id', $installationId)
            ->where('tenant_id', $installation->tenant_id)
            ->first();
    }
}
