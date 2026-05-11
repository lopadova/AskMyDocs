<?php

declare(strict_types=1);

namespace App\Connectors\BuiltIn;

use App\Connectors\BaseConnector;
use App\Connectors\BuiltIn\OneDrive\MicrosoftGraphPaginator;
use App\Connectors\Exceptions\ConnectorApiException;
use App\Connectors\Exceptions\ConnectorAuthException;
use App\Connectors\Exceptions\ConnectorPaginationLimitException;
use App\Connectors\HealthStatus;
use App\Connectors\SyncResult;
use App\Jobs\IngestDocumentJob;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * v4.5/W5 — Reference connector: Microsoft OneDrive (built-in).
 *
 * Surfaces OneDrive for Business + personal OneDrive files via the
 * Microsoft Graph API. Mirrors the GoogleDriveConnector pattern —
 * OAuth2 + full sync via collection walk + incremental sync via
 * Graph's delta endpoint.
 *
 * **OAuth surface**: Microsoft identity platform v2.0 (`login.microsoftonline.com`).
 * The `common` tenant lets both work + personal accounts authorise
 * against the same redirect URI.
 *
 * **Sync semantics**:
 *   - Full sync — recursive walk of `/me/drive/root/children`. For each
 *     folder, recurse into its children; for each file with a
 *     supported MIME type, download via `/me/drive/items/{id}/content`,
 *     redact + write to KB disk, dispatch IngestDocumentJob.
 *   - Incremental sync — `/me/drive/root/delta` returns every change
 *     since the cursor encoded in the `@odata.deltaLink` URL we
 *     persisted on the previous run. Items with a `deleted` facet are
 *     soft-deleted via the shared metadata-key helper.
 *
 * **Supported MIME types**: `text/markdown`, `text/plain`,
 * `application/pdf`. MS Office formats (.docx, .xlsx, .pptx) and
 * Outlook attachments (.eml) are deferred — the ingest pipeline does
 * not yet ship dedicated extractors for them and a TODO marker keeps
 * the gap visible.
 *
 * **Deletion reconciliation**: Graph's delta query surfaces deletions
 * as `value[].deleted.state === 'deleted'`. The incremental loop
 * routes those through {@see BaseConnector::softDeleteByMetadataKey}
 * keyed by `onedrive_item_id`.
 *
 * Required env (config/connectors.php::providers.onedrive):
 *   - CONNECTOR_ONEDRIVE_CLIENT_ID
 *   - CONNECTOR_ONEDRIVE_CLIENT_SECRET
 *   - CONNECTOR_ONEDRIVE_REDIRECT_URI
 *   - CONNECTOR_ONEDRIVE_TENANT (default: `common`)
 */
class OneDriveConnector extends BaseConnector
{
    /**
     * Max recursion depth for `/me/drive/items/{id}/children` walks.
     * Microsoft's documented soft limit is 200 levels for a single
     * synced folder tree; in practice a depth >10 indicates a
     * pathological layout the ingest pipeline isn't tuned for.
     */
    private const MAX_FOLDER_RECURSION_DEPTH = 8;

    /**
     * Supported MIME types — Graph reports them on every `driveItem`
     * via `file.mimeType`. Filtering up front (before download) keeps
     * us from spending bandwidth on .docx + .xlsx + binary attachments
     * the ingest pipeline can't yet extract.
     *
     * @var list<string>
     */
    private const SUPPORTED_MIME_TYPES = [
        'text/markdown',
        'text/plain',
        'application/pdf',
        // TODO(v4.6+): MS Office (`application/vnd.openxmlformats-officedocument.*`)
        // once the ingest pipeline ships docx/xlsx/pptx extractors.
        // TODO(v4.6+): Outlook items (`application/vnd.ms-outlook`).
    ];

    public function key(): string
    {
        return 'onedrive';
    }

    public function displayName(): string
    {
        return 'Microsoft OneDrive';
    }

    public function oauthScopes(): array
    {
        return [
            'Files.Read',
            'Files.Read.All',
            'User.Read',
            'offline_access',
        ];
    }

    public function initiateOAuth(int $installationId): string
    {
        $provider = $this->providerConfig();
        $state = $this->issueOAuthState($installationId);

        $params = http_build_query([
            'client_id' => $provider['client_id'] ?? '',
            'redirect_uri' => $provider['redirect_uri'] ?? '',
            'response_type' => 'code',
            'response_mode' => 'query',
            'scope' => implode(' ', $this->oauthScopes()),
            'state' => $state,
            // `prompt=consent` forces re-consent on every install so
            // refresh tokens are reliably issued; without it Microsoft
            // may skip the consent screen and skip emitting a new
            // refresh token, which then leaves us unable to refresh
            // when the access token expires (typical ~1h lifetime).
            'prompt' => 'consent',
        ]);

        return $this->authorizeUrl($provider).'?'.$params;
    }

    public function handleOAuthCallback(int $installationId, Request $request): void
    {
        $code = $request->input('code');
        $state = $request->input('state');

        if (! is_string($code) || $code === '') {
            throw new ConnectorAuthException('OneDrive OAuth callback missing `code` parameter.');
        }
        if (! is_string($state) || ! $this->consumeOAuthState($installationId, $state)) {
            throw new ConnectorAuthException('OneDrive OAuth callback state token invalid or expired.');
        }

        $provider = $this->providerConfig();

        $response = Http::asForm()
            ->acceptJson()
            ->post($this->tokenUrl($provider), [
                'client_id' => $provider['client_id'] ?? '',
                'client_secret' => $provider['client_secret'] ?? '',
                'code' => $code,
                'redirect_uri' => $provider['redirect_uri'] ?? '',
                'grant_type' => 'authorization_code',
                'scope' => implode(' ', $this->oauthScopes()),
            ]);

        if (! $response->successful()) {
            throw new ConnectorAuthException(sprintf(
                'OneDrive OAuth token exchange failed: HTTP %d %s',
                $response->status(),
                Str::limit((string) $response->body(), 200),
            ));
        }

        $payload = $response->json();
        if (! is_array($payload) || ! isset($payload['access_token'])) {
            throw new ConnectorAuthException('OneDrive OAuth token exchange returned no access_token.');
        }

        $expiresAt = isset($payload['expires_in']) && is_numeric($payload['expires_in'])
            ? Carbon::now()->addSeconds((int) $payload['expires_in'])
            : null;

        $this->vault->setCredentials(
            $installationId,
            accessToken: (string) $payload['access_token'],
            refreshToken: isset($payload['refresh_token']) && is_string($payload['refresh_token'])
                ? $payload['refresh_token']
                : null,
            expiresAt: $expiresAt,
            extra: [
                'token_type' => $payload['token_type'] ?? 'Bearer',
                'scope' => $payload['scope'] ?? implode(' ', $this->oauthScopes()),
            ],
        );

        $this->emitAudit('installed', installationId: $installationId, metadata: [
            'expires_at' => $expiresAt?->toIso8601String(),
        ]);
    }

    public function refreshTokenIfExpired(int $installationId): ?string
    {
        $access = $this->vault->getAccessToken($installationId);
        if ($access !== null) {
            return $access;
        }

        $refresh = $this->vault->getRefreshToken($installationId);
        if ($refresh === null) {
            return null;
        }

        $provider = $this->providerConfig();
        $response = Http::asForm()
            ->acceptJson()
            ->post($this->tokenUrl($provider), [
                'client_id' => $provider['client_id'] ?? '',
                'client_secret' => $provider['client_secret'] ?? '',
                'refresh_token' => $refresh,
                'grant_type' => 'refresh_token',
                'scope' => implode(' ', $this->oauthScopes()),
            ]);

        if (! $response->successful()) {
            throw new ConnectorAuthException(sprintf(
                'OneDrive OAuth refresh failed: HTTP %d %s',
                $response->status(),
                Str::limit((string) $response->body(), 200),
            ));
        }

        $payload = $response->json();
        if (! is_array($payload) || ! isset($payload['access_token'])) {
            throw new ConnectorAuthException('OneDrive OAuth refresh returned no access_token.');
        }

        $expiresAt = isset($payload['expires_in']) && is_numeric($payload['expires_in'])
            ? Carbon::now()->addSeconds((int) $payload['expires_in'])
            : null;

        $newRefresh = isset($payload['refresh_token']) && is_string($payload['refresh_token'])
            ? $payload['refresh_token']
            : $refresh;

        $this->vault->setCredentials(
            $installationId,
            accessToken: (string) $payload['access_token'],
            refreshToken: $newRefresh,
            expiresAt: $expiresAt,
            extra: $this->vault->getExtra($installationId),
        );

        $this->emitAudit('token_refreshed', installationId: $installationId);

        return (string) $payload['access_token'];
    }

    public function syncFull(int $installationId): SyncResult
    {
        $accessToken = $this->refreshTokenIfExpired($installationId);
        if ($accessToken === null) {
            throw new ConnectorAuthException('No valid OneDrive access token; reinstall the connector.');
        }

        $installation = $this->loadInstallation($installationId);
        $config = (array) ($installation->config_json ?? []);
        $projectKey = (string) ($config['project_key'] ?? ('connector-'.$this->key()));

        $added = 0;
        $errors = [];

        try {
            // Recursive folder walk starting at the drive root.
            $this->walkFolder(
                $installation,
                $projectKey,
                $accessToken,
                folderId: 'root',
                depth: 0,
                added: $added,
                errors: $errors,
            );
        } catch (ConnectorPaginationLimitException $e) {
            $errors[] = sprintf(
                'sync truncated at maxPages=%d (Microsoft Graph still reports @odata.nextLink); raise the cap or trigger another sync.',
                $e->maxPages,
            );
            Log::warning('OneDriveConnector::syncFull truncated by pagination cap', [
                'installation_id' => $installationId,
                'max_pages' => $e->maxPages,
                'documents_ingested_before_cap' => $added,
            ]);
        } catch (ConnectorApiException $e) {
            $errors[] = $e->getMessage();
        }

        // Initialise the delta cursor for subsequent incremental runs.
        // A failure here is non-fatal — the next incremental run will
        // fall back to a full sync if no cursor is present.
        try {
            $this->initialiseDeltaCursor($installationId, $accessToken);
        } catch (\Throwable $e) {
            Log::warning('OneDriveConnector::initialiseDeltaCursor failed', [
                'installation_id' => $installationId,
                'exception' => $e->getMessage(),
            ]);
        }

        $result = new SyncResult(
            documentsAdded: $added,
            documentsUpdated: 0,
            documentsRemoved: 0,
            errors: $errors,
            completedAt: Carbon::now(),
        );

        $this->emitAudit('sync_completed', installationId: $installationId, metadata: array_merge(
            $result->toArray(),
            ['mode' => 'full'],
        ));

        return $result;
    }

    public function syncIncremental(int $installationId, ?Carbon $since): SyncResult
    {
        $accessToken = $this->refreshTokenIfExpired($installationId);
        if ($accessToken === null) {
            throw new ConnectorAuthException('No valid OneDrive access token; reinstall the connector.');
        }

        $deltaLink = $this->vault->getExtraKey($installationId, 'delta_link');
        if (! is_string($deltaLink) || $deltaLink === '') {
            // No cursor yet — fall back to full sync on first run.
            return $this->syncFull($installationId);
        }

        $installation = $this->loadInstallation($installationId);
        $config = (array) ($installation->config_json ?? []);
        $projectKey = (string) ($config['project_key'] ?? ('connector-'.$this->key()));

        $updated = 0;
        $removed = 0;
        $errors = [];

        $paginator = new MicrosoftGraphPaginator;
        $startLink = $deltaLink;
        $fetch = function (?string $nextLink) use ($accessToken, $startLink) {
            $target = $nextLink ?? $startLink;

            return Http::withToken($accessToken)->get($target);
        };

        try {
            foreach ($paginator->walkLazy($fetch) as $batch) {
                foreach ($batch as $item) {
                    try {
                        // Deletion event — Graph delta surfaces these
                        // as items carrying a `deleted` facet.
                        $deletedFacet = $item['deleted'] ?? null;
                        if (is_array($deletedFacet)) {
                            $itemId = (string) ($item['id'] ?? '');
                            if ($itemId !== '' && $this->softDeleteByMetadataKey($installation, 'onedrive_item_id', $itemId)) {
                                $removed++;
                            }
                            continue;
                        }

                        // Folders surface in the delta stream too but
                        // carry no `file` facet — skip them; the
                        // delta walk naturally surfaces every file
                        // under them as its own item.
                        if (! isset($item['file']) || ! is_array($item['file'])) {
                            continue;
                        }

                        $mimeType = (string) ($item['file']['mimeType'] ?? '');
                        if (! $this->isSupportedMimeType($mimeType)) {
                            continue;
                        }

                        $this->ingestDriveItem($installation, $projectKey, $accessToken, $item);
                        $updated++;
                    } catch (\Throwable $e) {
                        $errors[] = sprintf(
                            'item %s: %s',
                            $item['id'] ?? '?',
                            $e->getMessage(),
                        );
                    }
                }
            }

            $newDeltaLink = $paginator->deltaLink();
            if ($newDeltaLink !== null) {
                $this->vault->setExtraKey($installationId, 'delta_link', $newDeltaLink);
            }
        } catch (ConnectorPaginationLimitException $e) {
            $errors[] = sprintf(
                'incremental sync truncated at maxPages=%d (Microsoft Graph still reports @odata.nextLink); raise the cap or trigger another sync.',
                $e->maxPages,
            );
            Log::warning('OneDriveConnector::syncIncremental truncated by pagination cap', [
                'installation_id' => $installationId,
                'max_pages' => $e->maxPages,
                'documents_processed_before_cap' => $updated,
            ]);
        } catch (ConnectorApiException $e) {
            $errors[] = $e->getMessage();
        }

        $result = new SyncResult(
            documentsAdded: 0,
            documentsUpdated: $updated,
            documentsRemoved: $removed,
            errors: $errors,
            completedAt: Carbon::now(),
        );

        $this->emitAudit('sync_completed', installationId: $installationId, metadata: array_merge(
            $result->toArray(),
            ['mode' => 'incremental', 'since' => $since?->toIso8601String()],
        ));

        return $result;
    }

    public function disconnect(int $installationId): void
    {
        $accessToken = $this->vault->getAccessToken($installationId);
        if ($accessToken !== null) {
            // Microsoft Graph's documented "sign out from all sessions"
            // endpoint. Best-effort — operator-driven disconnect must
            // succeed locally even if Graph is unreachable.
            try {
                Http::withToken($accessToken)
                    ->timeout(5)
                    ->post($this->apiBase().'/me/revokeSignInSessions');
            } catch (\Throwable $e) {
                Log::warning('OneDriveConnector: revokeSignInSessions failed', [
                    'installation_id' => $installationId,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $this->vault->clearCredentials($installationId);
        $this->emitAudit('disconnected', installationId: $installationId);
    }

    public function health(int $installationId): HealthStatus
    {
        $accessToken = $this->vault->getAccessToken($installationId);
        if ($accessToken === null) {
            return HealthStatus::errored('No valid access token (credentials missing or expired).');
        }

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->timeout(5)
                ->get($this->apiBase().'/me');
        } catch (\Throwable $e) {
            return HealthStatus::errored("Network error: {$e->getMessage()}");
        }

        if ($response->successful()) {
            return HealthStatus::healthy();
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return HealthStatus::errored("Authorization rejected (HTTP {$response->status()}).");
        }

        return HealthStatus::degraded("/me returned HTTP {$response->status()}");
    }

    /**
     * Recursive folder walk — for the given folder, paginate over
     * `/me/drive/items/{id}/children` and either ingest the file or
     * recurse into the sub-folder.
     *
     * @param  array<string>  $errors  Mutated by reference; per-item
     *                                 fetch failures accumulate here.
     */
    private function walkFolder(
        \App\Models\ConnectorInstallation $installation,
        string $projectKey,
        string $accessToken,
        string $folderId,
        int $depth,
        int &$added,
        array &$errors,
    ): void {
        if ($depth > self::MAX_FOLDER_RECURSION_DEPTH) {
            $errors[] = sprintf(
                'folder %s: max recursion depth %d reached; subtree skipped',
                $folderId,
                self::MAX_FOLDER_RECURSION_DEPTH,
            );

            return;
        }

        $paginator = new MicrosoftGraphPaginator;
        $childrenUrl = $folderId === 'root'
            ? $this->apiBase().'/me/drive/root/children'
            : $this->apiBase().'/me/drive/items/'.urlencode($folderId).'/children';
        $fetch = function (?string $nextLink) use ($accessToken, $childrenUrl) {
            $target = $nextLink ?? $childrenUrl;

            return Http::withToken($accessToken)->get($target);
        };

        foreach ($paginator->walkLazy($fetch) as $batch) {
            foreach ($batch as $item) {
                $itemId = (string) ($item['id'] ?? '');
                if ($itemId === '') {
                    continue;
                }

                // Folder → recurse.
                if (isset($item['folder']) && is_array($item['folder'])) {
                    $this->walkFolder(
                        $installation,
                        $projectKey,
                        $accessToken,
                        folderId: $itemId,
                        depth: $depth + 1,
                        added: $added,
                        errors: $errors,
                    );
                    continue;
                }

                // File → MIME-filter then ingest.
                if (! isset($item['file']) || ! is_array($item['file'])) {
                    continue;
                }
                $mimeType = (string) ($item['file']['mimeType'] ?? '');
                if (! $this->isSupportedMimeType($mimeType)) {
                    continue;
                }

                try {
                    $this->ingestDriveItem($installation, $projectKey, $accessToken, $item);
                    $added++;
                } catch (\Throwable $e) {
                    $errors[] = sprintf(
                        'item %s (%s): %s',
                        $item['name'] ?? 'unknown',
                        $itemId,
                        $e->getMessage(),
                    );
                }
            }
        }
    }

    /**
     * Download one Drive item + dispatch IngestDocumentJob. Mirrors
     * GoogleDriveConnector::ingestFile() but tuned for Graph's response
     * shape.
     *
     * @param  array<string,mixed>  $item
     */
    private function ingestDriveItem(
        \App\Models\ConnectorInstallation $installation,
        string $projectKey,
        string $accessToken,
        array $item,
    ): void {
        $itemId = (string) ($item['id'] ?? '');
        $name = (string) ($item['name'] ?? 'untitled');
        $mimeType = (string) ($item['file']['mimeType'] ?? '');

        if ($itemId === '') {
            throw new \RuntimeException('Drive item missing id.');
        }

        // Graph's documented file-download endpoint —
        // `/me/drive/items/{id}/content` returns the binary stream.
        // Graph 302-redirects to a CDN URL for the actual blob; the
        // `Http::` client follows redirects by default.
        $download = Http::withToken($accessToken)->get(
            $this->apiBase().'/me/drive/items/'.urlencode($itemId).'/content',
        );

        if (! $download->successful()) {
            throw new \RuntimeException(
                "OneDrive download failed: HTTP {$download->status()}"
            );
        }

        $body = (string) $download->body();
        // R26 — PII redaction at the ingest boundary for textual
        // payloads only; binary blobs are handed off to the ingest
        // pipeline which has its own per-format extractors.
        if (str_starts_with($mimeType, 'text/')) {
            $body = $this->maybeRedactContent($body);
        }

        $outputExtension = $this->extensionForMime($mimeType, $name);
        $persistedMime = $mimeType !== '' ? $mimeType : 'application/octet-stream';
        $sanitisedItemId = preg_replace('/[^A-Za-z0-9!\-]/', '', $itemId) ?? '';
        $safeItemId = $sanitisedItemId !== '' ? $sanitisedItemId : 'item';

        $relativePath = sprintf(
            '%s/connectors/%s/installation-%d/%s-%s%s',
            $projectKey,
            $this->key(),
            $installation->id,
            Str::slug($name) !== '' ? Str::slug($name) : 'doc',
            $safeItemId,
            $outputExtension,
        );

        $paths = $this->resolveKbSourcePath($relativePath);

        $written = Storage::disk($paths['disk'])->put($paths['absolute'], $body);
        if ($written === false) {
            throw new \RuntimeException("Failed to write {$paths['absolute']} to KB disk [{$paths['disk']}].");
        }

        IngestDocumentJob::dispatch(
            projectKey: $projectKey,
            relativePath: $paths['relative'],
            disk: $paths['disk'],
            title: $name,
            metadata: [
                'connector' => $this->key(),
                'installation_id' => $installation->id,
                'onedrive_item_id' => $itemId,
                'onedrive_mime_type' => $mimeType,
                'onedrive_last_modified' => $item['lastModifiedDateTime'] ?? null,
                'onedrive_web_url' => $item['webUrl'] ?? null,
                'onedrive_size' => $item['size'] ?? null,
            ],
            mimeType: $persistedMime,
            tenantId: $installation->tenant_id,
        );
    }

    /**
     * Issue the very first delta request — `/me/drive/root/delta`
     * without any cursor returns `@odata.deltaLink` carrying the
     * anchor point. We persist that link as the cursor for the next
     * incremental run.
     */
    private function initialiseDeltaCursor(int $installationId, string $accessToken): void
    {
        // Use latest=token to short-circuit — Graph supports this
        // optimisation, returning ONLY the deltaLink (no items) so we
        // don't re-walk the entire drive a second time after the full
        // sync just to capture the cursor.
        $response = Http::withToken($accessToken)->get(
            $this->apiBase().'/me/drive/root/delta',
            ['token' => 'latest'],
        );

        if (! $response->successful()) {
            return;
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return;
        }

        $deltaLink = $payload['@odata.deltaLink'] ?? null;
        if (is_string($deltaLink) && $deltaLink !== '') {
            $this->vault->setExtraKey($installationId, 'delta_link', $deltaLink);
        }
    }

    private function isSupportedMimeType(string $mimeType): bool
    {
        return in_array($mimeType, self::SUPPORTED_MIME_TYPES, true);
    }

    private function extensionForMime(string $mime, string $fallbackName): string
    {
        $map = [
            'text/markdown' => '.md',
            'text/plain' => '.txt',
            'application/pdf' => '.pdf',
        ];
        if (isset($map[$mime])) {
            return $map[$mime];
        }

        $ext = pathinfo($fallbackName, PATHINFO_EXTENSION);

        return $ext !== '' ? '.'.strtolower($ext) : '';
    }

    /**
     * Microsoft's OAuth2 authorize endpoint — tenant-scoped. The
     * `common` tenant lets both work + personal accounts authorise
     * against the same redirect URI.
     *
     * @param  array<string,mixed>  $provider
     */
    private function authorizeUrl(array $provider): string
    {
        $configured = $provider['oauth_authorize_url'] ?? null;
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $tenant = (string) ($provider['tenant'] ?? 'common');

        return 'https://login.microsoftonline.com/'.$tenant.'/oauth2/v2.0/authorize';
    }

    /**
     * @param  array<string,mixed>  $provider
     */
    private function tokenUrl(array $provider): string
    {
        $configured = $provider['oauth_token_url'] ?? null;
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $tenant = (string) ($provider['tenant'] ?? 'common');

        return 'https://login.microsoftonline.com/'.$tenant.'/oauth2/v2.0/token';
    }

    private function apiBase(): string
    {
        $config = (string) ($this->providerConfig()['api_base'] ?? '');

        return $config !== '' ? rtrim($config, '/') : 'https://graph.microsoft.com/v1.0';
    }

    /**
     * @return array<string,mixed>
     */
    private function providerConfig(): array
    {
        return (array) config('connectors.providers.onedrive', []);
    }
}
