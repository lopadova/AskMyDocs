<?php

declare(strict_types=1);

namespace App\Services\Admin\Connectors;

use App\Support\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsCredentialForm;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * v8.20 — the SINGLE core (R44) for connector-installation lifecycle + the
 * read surface. Every surface delegates here:
 *   - PHP   : the `connectors:list` Artisan command ({@see summary}) and
 *             `connectors:install` (OAuth/credential creation).
 *   - HTTP  : {@see \App\Http\Controllers\Api\Admin\ConnectorAdminController}.
 *   - MCP   : {@see \App\Mcp\Tools\ConnectorInstallationsTool} ({@see summary}).
 *
 * Credential (form-driven) account CREATION lives in the sibling
 * {@see ConfigureConnectorService} (it owns the secret/vault round-trip); this
 * service owns OAuth-account creation, the read summary, metadata edits and
 * deletion. Both are tenant-scoped (R30) on every query.
 *
 * Multi-account: a tenant connects N accounts per connector, disambiguated by
 * `label`, each optionally bound to a real `project_key` (null = the tenant
 * default). The composite unique is (tenant_id, connector_name, label).
 */
final class ConnectorInstallationService
{
    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly TenantContext $tenantContext,
        private readonly ConnectorSettingsService $settings,
    ) {}

    /**
     * Read core: every registered connector with the active tenant's installed
     * ACCOUNTS (a list — v8.20 multi-account). Shared verbatim by the HTTP
     * `index`, the MCP read tool and the `connectors:list` command so the three
     * surfaces never drift.
     *
     * @return list<array<string,mixed>>
     */
    public function summary(): array
    {
        /** @var Collection<string, Collection<int, ConnectorInstallation>> $byConnector */
        // Select only the columns the roster serializes — never hydrate the
        // potentially-large `config_json` (or other unused columns) just to
        // render a status list (lean as installations grow).
        // `config_json` is hydrated so the read surface can expose the
        // picker-owned sub-keys (folders.include + date_window_days); only those
        // are serialized — never connection/host/username/secret (see
        // {@see installationArray}). The blob is small for credential connectors.
        $byConnector = ConnectorInstallation::query()
            ->where('tenant_id', $this->tenantContext->current())
            ->orderBy('connector_name')
            ->orderBy('label')
            ->get(['id', 'connector_name', 'label', 'project_key', 'status', 'last_sync_at', 'error_json', 'config_json'])
            ->groupBy('connector_name');

        return $this->registry->all()->map(function ($connector) use ($byConnector): array {
            $isCredential = $connector instanceof SupportsCredentialForm;
            $installations = $byConnector->get($connector->key(), collect())
                ->map(fn (ConnectorInstallation $i) => $this->installationArray($i))
                ->values()
                ->all();

            return [
                'key' => $connector->key(),
                'display_name' => $connector->displayName(),
                'icon_url' => $connector->iconUrl(),
                'oauth_scopes' => $connector->oauthScopes(),
                'auth_kind' => $isCredential ? 'credential' : 'oauth',
                'credential_form_schema' => $isCredential ? $connector->credentialFormSchema() : null,
                // v8.20 — a LIST of accounts (was a single nullable installation).
                'installations' => $installations,
                // Back-compat (R27 additive): the pre-v8.20 single-installation
                // shape. Prefer the legacy 'default'-label account so a tenant
                // that later adds more accounts doesn't surface an arbitrary one
                // (e.g. 'sales') to the not-yet-migrated FE; PR2 switches it to
                // `installations` and removes this.
                'installation' => $this->backCompatInstallation($installations),
            ];
        })->values()->all();
    }

    /**
     * The canonical serialized shape of ONE installation, shared by
     * {@see summary} and {@see \App\Http\Resources\Admin\ConnectorInstallationResource}.
     * Keep the two in lockstep (R27 — additive only).
     *
     * @return array<string,mixed>
     */
    public function installationArray(ConnectorInstallation $i): array
    {
        return [
            'id' => $i->id,
            'label' => $i->label,
            'project_key' => $i->project_key,
            'status' => $i->status,
            'last_sync_at' => $i->last_sync_at?->toIso8601String(),
            'error' => $i->error_json,
            // v8.24 (R27 additive) — the picker-owned connection settings, read
            // back for the edit form. ONLY folders.include + date_window_days are
            // exposed; the rest of config_json (connection/host/username) stays
            // private and the secret lives only in the vault.
            'folders' => ['include' => $this->folderIncludeOf($i)],
            'date_window_days' => $this->dateWindowOf($i),
            // v8.25 (R27 additive) — the connector's FULL editable settings
            // surface + the current value of each field, as a nested partial of
            // config_json (the schema-driven editor seeds from this and PATCHes
            // the same shape). [] when the connector advertises no settings; never
            // exposes connection/auth/secret config.
            'connection_settings_schema' => $this->settings->schemaFor($i),
            'settings' => $this->settings->currentSettings($i),
        ];
    }

    /**
     * The sync whitelist (config_json.folders.include) of an installation, or []
     * when unset / config_json not hydrated.
     *
     * @return list<string>
     */
    private function folderIncludeOf(ConnectorInstallation $i): array
    {
        $config = (array) ($i->config_json ?? []);
        $include = (array) (($config['folders'] ?? [])['include'] ?? []);

        return array_values(array_map('strval', $include));
    }

    /**
     * The sync window (config_json.date_window_days) of an installation, or null
     * when unset / config_json not hydrated.
     */
    private function dateWindowOf(ConnectorInstallation $i): ?int
    {
        $config = (array) ($i->config_json ?? []);

        return isset($config['date_window_days']) ? (int) $config['date_window_days'] : null;
    }

    /**
     * The pre-v8.20 single-installation back-compat shape: the legacy
     * 'default'-label account when present, else the first account, else null.
     *
     * @param  list<array<string,mixed>>  $installations
     * @return array<string,mixed>|null
     */
    private function backCompatInstallation(array $installations): ?array
    {
        foreach ($installations as $installation) {
            if (($installation['label'] ?? null) === 'default') {
                return $installation;
            }
        }

        return $installations[0] ?? null;
    }

    /**
     * Cache key mapping an OAuth `state` token back to the installation that
     * issued it, so a concurrent callback resolves the RIGHT account.
     */
    private function stateInstallationKey(string $name, string $token): string
    {
        return "connector:install_state:{$this->tenantContext->current()}:{$name}:{$token}";
    }

    /**
     * Resolve the installation id an OAuth `state` token was issued for (v8.20
     * multi-account: more than one account on a connector can be PENDING at
     * once, so "most recent pending" is no longer correct). Returns null when
     * the token was never mapped (e.g. an install issued before this cache
     * existed) — the caller then falls back to most-recent-pending.
     */
    public function installationIdForState(string $name, string $token): ?int
    {
        $id = Cache::get($this->stateInstallationKey($name, $token));

        return $id === null ? null : (int) $id;
    }

    /**
     * OAuth-connector account creation / re-grant.
     *
     * find-or-rearm by (tenant, connector, label): an existing account with the
     * same label is re-armed to PENDING (re-grant after a scope expansion or a
     * stuck row); a new label creates a fresh account. project_key is only
     * (re)written when explicitly supplied, so a re-grant never silently clears
     * an existing binding.
     *
     * Concurrency (R21):
     *   - CREATE path: the read-then-create can lose a same-label race; the
     *     UniqueConstraintViolationException is caught and we fall through to the
     *     re-arm path so the second request is idempotent-by-label.
     *   - RE-ARM path: wrapped in DB::transaction + lockForUpdate so two
     *     concurrent re-grants on the same label cannot race on project_key
     *     (the conditional project_key write is not idempotent across callers
     *     with different values — last-write-wins without the lock).
     *
     * @return array{installation: ConnectorInstallation, redirect_to: string}
     */
    public function startOAuthInstall(
        string $name,
        string $label,
        ?string $projectKey,
        bool $projectKeyProvided,
        int $createdBy,
    ): array {
        $connector = $this->registry->get($name);
        if ($connector === null) {
            throw new NotFoundHttpException("Connector '{$name}' is not registered.");
        }

        $installation = $this->findByLabel($name, $label);

        if ($installation === null) {
            try {
                $installation = ConnectorInstallation::create([
                    'tenant_id' => $this->tenantContext->current(),
                    'connector_name' => $name,
                    'label' => $label,
                    'project_key' => $projectKey,
                    'status' => ConnectorInstallation::STATUS_PENDING,
                    'created_by' => $createdBy,
                ]);
            } catch (UniqueConstraintViolationException) {
                // A concurrent start-install for the same label won the race;
                // degrade to re-arm so this call stays idempotent-by-label.
                $installation = null; // resolved inside the re-arm transaction below
            }
        }

        // R21 — re-arm path: lock the row before reading wasRecentlyCreated and
        // before writing status / project_key, so two concurrent re-grants on the
        // same label cannot clobber each other's project_key update.
        if ($installation === null || $installation->wasRecentlyCreated === false) {
            $installation = DB::transaction(function () use ($name, $label, $projectKey, $projectKeyProvided, $createdBy) {
                $locked = ConnectorInstallation::query()
                    ->where('tenant_id', $this->tenantContext->current())
                    ->where('connector_name', $name)
                    ->where('label', $label)
                    ->lockForUpdate()
                    ->first();

                if ($locked === null) {
                    // Extremely rare: the create path above also missed (e.g. the
                    // concurrent winner deleted the row before we could lock it).
                    // Create fresh inside the transaction.
                    return ConnectorInstallation::create([
                        'tenant_id' => $this->tenantContext->current(),
                        'connector_name' => $name,
                        'label' => $label,
                        'project_key' => $projectKey,
                        'status' => ConnectorInstallation::STATUS_PENDING,
                        'created_by' => $createdBy,
                    ]);
                }

                $attrs = [
                    'status' => ConnectorInstallation::STATUS_PENDING,
                    'error_json' => null,
                ];
                if ($projectKeyProvided) {
                    $attrs['project_key'] = $projectKey;
                }
                $locked->forceFill($attrs)->save();

                return $locked;
            });
        }

        if ($installation === null) {
            // Should be unreachable (created or re-fetched above), but never
            // hand a null installation to the connector.
            throw new NotFoundHttpException("Could not resolve installation for connector '{$name}'.");
        }

        $redirectTo = $connector->initiateOAuth($installation->id);
        $this->mapStateToInstallation($name, $redirectTo, $installation->id);

        return [
            'installation' => $installation,
            'redirect_to' => $redirectTo,
        ];
    }

    private function findByLabel(string $name, string $label): ?ConnectorInstallation
    {
        return ConnectorInstallation::query()
            ->where('tenant_id', $this->tenantContext->current())
            ->where('connector_name', $name)
            ->where('label', $label)
            ->first();
    }

    /**
     * Parse the `state` token out of the provider authorize URL and cache the
     * token → installation id mapping (15-minute TTL, matching the OAuth state
     * lifetime) so the callback can resolve the right account.
     */
    private function mapStateToInstallation(string $name, string $redirectTo, int $installationId): void
    {
        parse_str((string) parse_url($redirectTo, PHP_URL_QUERY), $query);
        $token = isset($query['state']) ? (string) $query['state'] : '';

        if ($token === '') {
            return;
        }

        Cache::put($this->stateInstallationKey($name, $token), $installationId, now()->addMinutes(15));
    }

    /**
     * Edit an existing account's metadata (label / project binding). Credential
     * re-auth is intentionally NOT handled here — that re-runs the connector's
     * own configure/OAuth round-trip. Metadata-only edits never touch the vault.
     *
     * R21 — lockForUpdate + save inside DB::transaction so a concurrent rename on
     * the same installation does not interleave (a label rename can fail the DB
     * unique; the transaction ensures we hold the row from read to write). The
     * config_json edits (folders.include / date_window_days) are a read-modify-
     * write of the same locked row, so they cannot race a concurrent edit either.
     *
     * @param  array<string,mixed>  $attrs  Subset of {label, project_key, folders,
     *                                       date_window_days}; only present keys
     *                                       are updated (PATCH).
     */
    public function updateMetadata(int $installationId, array $attrs): ConnectorInstallation
    {
        return DB::transaction(function () use ($installationId, $attrs) {
            $installation = ConnectorInstallation::query()
                ->where('id', $installationId)
                ->where('tenant_id', $this->tenantContext->current())
                ->lockForUpdate()
                ->first();

            if ($installation === null) {
                throw new NotFoundHttpException("Installation {$installationId} not found.");
            }

            $update = [];
            if (array_key_exists('label', $attrs)) {
                $update['label'] = (string) $attrs['label'];
            }
            if (array_key_exists('project_key', $attrs)) {
                $value = $attrs['project_key'];
                $update['project_key'] = ($value === '' || $value === null) ? null : (string) $value;
            }

            $config = $this->applyConfigJsonEdits($installation, $attrs);
            if ($config !== null) {
                $update['config_json'] = $config;
            }

            if ($update !== []) {
                $installation->forceFill($update)->save();
            }

            return $installation;
        });
    }

    /**
     * Build the next config_json for the connection-settings edits (v8.24),
     * writing ONLY the picker-owned sub-keys so connection/auth_mode/exclude and
     * any other config the operator never sees are preserved. Returns null when
     * neither key is present (so the caller skips the config_json write entirely).
     *
     * @param  array<string,mixed>  $attrs
     * @return array<string,mixed>|null
     */
    private function applyConfigJsonEdits(ConnectorInstallation $installation, array $attrs): ?array
    {
        $hasFolders = array_key_exists('folders', $attrs) && array_key_exists('include', (array) $attrs['folders']);
        $hasWindow = array_key_exists('date_window_days', $attrs);
        $hasSettings = array_key_exists('settings', $attrs) && is_array($attrs['settings']);

        if (! $hasFolders && ! $hasWindow && ! $hasSettings) {
            return null;
        }

        // v8.25 — the generic settings payload (a nested partial of config_json)
        // writes the connector's full editable surface in one shot, overwriting
        // only schema-declared fields and preserving connection/auth/secret config.
        $config = $hasSettings
            ? $this->settings->mergeIntoConfig($installation, (array) $attrs['settings'])
            : (array) ($installation->config_json ?? []);

        // v8.24 back-compat — the narrow picker keys still work, applied on top.
        if ($hasFolders) {
            // Overwrite the include sub-key only — never the whole folders map,
            // so the default/edited `exclude` list survives an include edit.
            $folders = (array) ($config['folders'] ?? []);
            $folders['include'] = array_values((array) $attrs['folders']['include']);
            $config['folders'] = $folders;
        }

        if ($hasWindow) {
            // null = clear the override back to the connector default (remove the
            // key) — NOT coerce to 0, which would be a real "0-day window".
            if ($attrs['date_window_days'] === null) {
                unset($config['date_window_days']);
            } else {
                $config['date_window_days'] = (int) $attrs['date_window_days'];
            }
        }

        return $config;
    }

    /**
     * Disconnect upstream (best-effort) and remove the account row. The
     * companion `connector_credentials` row cascades via the FK (R28).
     */
    public function delete(int $installationId): void
    {
        $installation = $this->findOr404($installationId);

        $connector = $this->registry->get($installation->connector_name);
        if ($connector !== null) {
            try {
                $connector->disconnect($installation->id);
            } catch (\Throwable $e) {
                // Best-effort: never block removal of a stuck account just
                // because the upstream revoke endpoint returned non-2xx.
                report($e);
            }
        }

        $installation->delete();
    }

    public function findOr404(int $installationId): ConnectorInstallation
    {
        $installation = ConnectorInstallation::query()
            ->where('id', $installationId)
            ->where('tenant_id', $this->tenantContext->current())
            ->first();

        if ($installation === null) {
            throw new NotFoundHttpException("Installation {$installationId} not found.");
        }

        return $installation;
    }
}
