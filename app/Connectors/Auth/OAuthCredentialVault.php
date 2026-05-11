<?php

declare(strict_types=1);

namespace App\Connectors\Auth;

use App\Connectors\Exceptions\ConnectorAuthException;
use App\Models\ConnectorCredential;
use App\Models\ConnectorInstallation;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

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
     * v4.5/W2 — granular helper that reads a single key out of the
     * `extra_json` blob. Returns `null` when the credential row is
     * missing, the key is absent, OR the stored value is `null`. The
     * caller cannot distinguish "missing" from "null-by-value" without
     * `getExtra()` + `array_key_exists()`; in practice every consumer
     * just needs the value (Notion `bot_id`, Drive `changes_page_token`,
     * MS Graph `delta_link`) and treats null as "go fetch a fresh one".
     */
    public function getExtraKey(int $installationId, string $key): mixed
    {
        $extra = $this->getExtra($installationId);

        return $extra[$key] ?? null;
    }

    /**
     * v4.5/W2 — granular helper that updates a single key in the
     * `extra_json` blob, preserving every other key already stored.
     *
     * **R21 — atomic invariant.** Two concurrent connectors writing
     * different `extra_json` keys would otherwise race on the
     * read-modify-write window: thread A reads `{a: 1}`, thread B
     * reads `{a: 1}`, A writes `{a: 1, b: 2}`, B writes `{a: 1, c: 3}`
     * — B's write loses A's `b: 2`. The implementation now holds a
     * `SELECT ... FOR UPDATE` row lock inside a `DB::transaction`,
     * so the read and the write are atomic relative to other writers.
     *
     * Concurrent-delete safety: if `disconnect()` deletes the row
     * between an outer `getCredentialRow()` check and this call,
     * the `lockForUpdate()->first()` returns null inside the
     * transaction → throw {@see ConnectorAuthException}. We do NOT
     * recreate the row via `updateOrCreate()` — a recreated credential
     * row without an encrypted access token would be impossible to
     * authenticate with anyway, and silently recreating "looks like
     * the disconnect worked" from the operator's perspective.
     *
     * @throws ConnectorAuthException when the credential row vanished
     *         mid-operation (e.g. concurrent disconnect).
     */
    public function setExtraKey(int $installationId, string $key, mixed $value): void
    {
        $installation = $this->findInstallation($installationId);
        if ($installation === null) {
            return;
        }

        DB::transaction(function () use ($installationId, $installation, $key, $value): void {
            $row = ConnectorCredential::query()
                ->where('connector_installation_id', $installationId)
                ->where('tenant_id', $installation->tenant_id)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new ConnectorAuthException(
                    "Cannot update extra_json for installation {$installationId}: credential row was deleted concurrently (likely a parallel disconnect)."
                );
            }

            $extra = $row->extra_json ?? [];
            $extra[$key] = $value;

            // Mutate in-place + save — guarantees we touch exactly the
            // row we held the lock on. `updateOrCreate()` would have
            // happily re-created a freshly-deleted row.
            $row->extra_json = $extra === [] ? null : $extra;
            $row->save();
        });
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
