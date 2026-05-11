<?php

declare(strict_types=1);

namespace App\Connectors;

use App\Connectors\Auth\OAuthCredentialVault;
use App\Models\ConnectorInstallation;
use App\Models\KbCanonicalAudit;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Padosoft\PiiRedactor\RedactorEngine;

/**
 * v4.5/W1 — Default implementations shared by every concrete connector.
 *
 * Subclasses MUST still implement {@see ConnectorInterface}'s abstract
 * surface (`key()`, `displayName()`, `iconUrl()`, `oauthScopes()`,
 * `initiateOAuth()`, `handleOAuthCallback()`, `syncFull()`,
 * `syncIncremental()`, `disconnect()`, `health()`). This base class
 * provides reusable helpers:
 *
 *   - {@see issueOAuthState()} / {@see consumeOAuthState()} — CSRF-safe
 *     state-token round-trip via the application cache. TTL controlled
 *     by `connectors.oauth_state_ttl_seconds` (default 600).
 *   - {@see refreshTokenIfExpired()} — placeholder helper that
 *     concrete connectors override per-provider. The default
 *     implementation is a no-op (returns the current access token if
 *     present, null otherwise) — useful for providers without refresh
 *     tokens (e.g. some PATs).
 *   - {@see emitAudit()} — records a `kb_canonical_audit` row with a
 *     stable `event_type='connector_*'` prefix so the admin log
 *     viewer can filter by connector activity.
 *   - {@see maybeRedactContent()} — applies the PII redactor at the
 *     ingest boundary when `kb.pii_redactor.enabled` is true. R26:
 *     concrete connectors call this on every fetched document body
 *     BEFORE handing it off to {@see \App\Jobs\IngestDocumentJob}.
 */
abstract class BaseConnector implements ConnectorInterface
{
    public function __construct(
        protected readonly OAuthCredentialVault $vault,
        protected readonly TenantContext $tenantContext,
        protected readonly RedactorEngine $redactor,
    ) {}

    /**
     * @return list<string>
     */
    public function oauthScopes(): array
    {
        return [];
    }

    public function iconUrl(): string
    {
        return asset("connectors/{$this->key()}.svg");
    }

    /**
     * Default no-op refresh. Override per-provider — e.g. Google's
     * `https://oauth2.googleapis.com/token` POST with
     * `grant_type=refresh_token`.
     */
    public function refreshTokenIfExpired(int $installationId): ?string
    {
        return $this->vault->getAccessToken($installationId);
    }

    /**
     * Generate a CSRF state token + store it in the cache, keyed to
     * the installation id, so the OAuth callback can verify the
     * round-trip. The token returned is what concrete connectors
     * include in their authorization URLs (`?state=...`).
     */
    protected function issueOAuthState(int $installationId): string
    {
        $token = Str::random(32);
        $ttl = (int) config('connectors.oauth_state_ttl_seconds', 600);

        Cache::put(
            $this->stateCacheKey($installationId, $token),
            [
                'tenant_id' => $this->tenantContext->current(),
                'installation_id' => $installationId,
                'issued_at' => Carbon::now()->toIso8601String(),
            ],
            $ttl,
        );

        return $token;
    }

    /**
     * Verify + consume a state token. Returns true if the token
     * matches a cached entry for this installation; false otherwise.
     * Single-use: the cache entry is removed before this returns so a
     * replay of the same `state=...` returns false on the second
     * call.
     */
    protected function consumeOAuthState(int $installationId, string $token): bool
    {
        $key = $this->stateCacheKey($installationId, $token);
        $entry = Cache::get($key);
        if ($entry === null) {
            return false;
        }

        Cache::forget($key);

        if (! is_array($entry)) {
            return false;
        }

        if (($entry['installation_id'] ?? null) !== $installationId) {
            return false;
        }

        // Defence in depth: also verify the tenant matches. Cross-
        // tenant state replay is otherwise structurally prevented by
        // the controller's tenant-scoped installation lookup, but the
        // cache is process-wide so a misbehaving connector could in
        // theory cross-pollinate. Belt + braces.
        if (($entry['tenant_id'] ?? null) !== $this->tenantContext->current()) {
            return false;
        }

        return true;
    }

    private function stateCacheKey(int $installationId, string $token): string
    {
        return "connector:oauth_state:{$installationId}:{$token}";
    }

    /**
     * Emit an immutable audit row. Concrete connectors call this on
     * every state-changing operation (install, sync_completed, sync_failed,
     * disconnect, token_refreshed) so the admin log viewer surfaces
     * connector activity alongside canonical compilation events.
     *
     * @param  array<string,mixed>|null  $before
     * @param  array<string,mixed>|null  $after
     * @param  array<string,mixed>|null  $metadata
     */
    protected function emitAudit(
        string $eventType,
        ?int $installationId = null,
        ?array $before = null,
        ?array $after = null,
        ?array $metadata = null,
    ): void {
        // Connector events live in the same audit table as canonical
        // events. The `event_type` is namespaced with `connector_` so
        // an admin filter on the log viewer can isolate them.
        $eventType = str_starts_with($eventType, 'connector_')
            ? $eventType
            : 'connector_'.$eventType;

        KbCanonicalAudit::create([
            'tenant_id' => $this->tenantContext->current(),
            'project_key' => 'connector:'.$this->key(),
            'doc_id' => null,
            'slug' => null,
            'event_type' => $eventType,
            'actor' => 'connector:'.$this->key(),
            'before_json' => $before,
            'after_json' => $after,
            'metadata_json' => array_merge(
                ['installation_id' => $installationId, 'connector' => $this->key()],
                $metadata ?? []
            ),
        ]);
    }

    /**
     * R26 — PII redaction at the ingest boundary. Concrete connectors
     * call this on every document body fetched from the upstream
     * provider BEFORE dispatching {@see \App\Jobs\IngestDocumentJob}.
     * When `kb.pii_redactor.enabled` is false (the default), the
     * content is returned unchanged. When true, the configured
     * redaction strategy (mask / tokenise) is applied.
     */
    protected function maybeRedactContent(string $content): string
    {
        $enabled = (bool) config('kb.pii_redactor.enabled', false);
        if (! $enabled || $content === '') {
            return $content;
        }

        return $this->redactor->redact($content);
    }

    /**
     * Locate the active installation by id, scoped to the active
     * tenant. Throws `InvalidArgumentException` if the installation
     * doesn't exist or belongs to a different tenant — the framework
     * uses this as a defence-in-depth check inside concrete connectors,
     * even though the controller / job layer already validates tenant
     * scope before calling in.
     */
    protected function loadInstallation(int $installationId): ConnectorInstallation
    {
        $installation = ConnectorInstallation::query()
            ->where('id', $installationId)
            ->where('tenant_id', $this->tenantContext->current())
            ->where('connector_name', $this->key())
            ->first();

        if ($installation === null) {
            throw new \InvalidArgumentException(
                "Connector installation {$installationId} not found for connector '{$this->key()}' in tenant '{$this->tenantContext->current()}'."
            );
        }

        return $installation;
    }
}
