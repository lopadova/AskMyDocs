<?php

declare(strict_types=1);

namespace App\Connectors\BuiltIn;

use App\Connectors\BaseConnector;
use App\Connectors\Exceptions\ConnectorAuthException;
use App\Connectors\HealthStatus;
use App\Connectors\SyncResult;
use App\Jobs\IngestDocumentJob;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentDeleter;
use App\Support\KbPath;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * v4.5/W1 — Reference connector: Google Drive (built-in).
 *
 * - OAuth2 via `accounts.google.com/o/oauth2/v2/auth` +
 *   `oauth2.googleapis.com/token` (no Google SDK; pure `Http::`).
 * - Full sync: `files.list` with a MIME filter covering markdown,
 *   plain text, PDF, and Google Docs (the latter exported as
 *   markdown via `files.export`).
 * - Incremental sync: `changes.list` cursor with a `pageToken`
 *   persisted in `connector_credentials.extra_json.changes_page_token`.
 * - Each discovered document is downloaded, optionally PII-redacted
 *   at the boundary, written to the KB disk, and forwarded to
 *   {@see IngestDocumentJob} — the single ingestion execution path.
 *
 * Two W4-scope items intentionally omitted from W1:
 *   - Folder filter (config_json::folder_id) — TODO once the admin
 *     SPA in W3 exposes a folder picker.
 *   - Shared drive support — TODO once a customer asks. Currently
 *     scoped to the authorising user's drive only.
 */
class GoogleDriveConnector extends BaseConnector
{
    public function key(): string
    {
        return 'google-drive';
    }

    public function displayName(): string
    {
        return 'Google Drive';
    }

    public function oauthScopes(): array
    {
        return [
            'https://www.googleapis.com/auth/drive.readonly',
            'https://www.googleapis.com/auth/drive.metadata.readonly',
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
            'scope' => implode(' ', $this->oauthScopes()),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return ($provider['oauth_authorize_url'] ?? 'https://accounts.google.com/o/oauth2/v2/auth')
            .'?'.$params;
    }

    public function handleOAuthCallback(int $installationId, Request $request): void
    {
        $code = $request->input('code');
        $state = $request->input('state');

        if (! is_string($code) || $code === '') {
            throw new ConnectorAuthException('Google OAuth callback missing `code` parameter.');
        }
        if (! is_string($state) || ! $this->consumeOAuthState($installationId, $state)) {
            throw new ConnectorAuthException('Google OAuth callback state token invalid or expired.');
        }

        $provider = $this->providerConfig();

        $response = Http::asForm()
            ->acceptJson()
            ->post($provider['oauth_token_url'] ?? 'https://oauth2.googleapis.com/token', [
                'code' => $code,
                'client_id' => $provider['client_id'] ?? '',
                'client_secret' => $provider['client_secret'] ?? '',
                'redirect_uri' => $provider['redirect_uri'] ?? '',
                'grant_type' => 'authorization_code',
            ]);

        if (! $response->successful()) {
            throw new ConnectorAuthException(sprintf(
                'Google OAuth token exchange failed: HTTP %d %s',
                $response->status(),
                Str::limit((string) $response->body(), 200),
            ));
        }

        $payload = $response->json();
        if (! is_array($payload) || ! isset($payload['access_token'])) {
            throw new ConnectorAuthException('Google OAuth token exchange returned no access_token.');
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
                'scope' => $payload['scope'] ?? null,
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
            ->post($provider['oauth_token_url'] ?? 'https://oauth2.googleapis.com/token', [
                'client_id' => $provider['client_id'] ?? '',
                'client_secret' => $provider['client_secret'] ?? '',
                'refresh_token' => $refresh,
                'grant_type' => 'refresh_token',
            ]);

        if (! $response->successful()) {
            throw new ConnectorAuthException(sprintf(
                'Google OAuth refresh failed: HTTP %d %s',
                $response->status(),
                Str::limit((string) $response->body(), 200),
            ));
        }

        $payload = $response->json();
        if (! is_array($payload) || ! isset($payload['access_token'])) {
            throw new ConnectorAuthException('Google OAuth refresh returned no access_token.');
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
            throw new ConnectorAuthException('No valid Google access token; reinstall the connector.');
        }

        $installation = $this->loadInstallation($installationId);
        $config = (array) ($installation->config_json ?? []);
        $projectKey = (string) ($config['project_key'] ?? ('connector-'.$this->key()));

        $added = 0;
        $errors = [];
        $pageToken = null;
        $provider = $this->providerConfig();
        $apiBase = $provider['api_base'] ?? 'https://www.googleapis.com/drive/v3';

        // Drive MIME types of interest. Google Docs export as markdown
        // via files.export (separate from files.get for binary types).
        $mimeQuery = "mimeType='text/markdown' or mimeType='text/plain' or "
            ."mimeType='application/pdf' or mimeType='application/vnd.google-apps.document'";

        do {
            $params = [
                'q' => "({$mimeQuery}) and trashed=false",
                'fields' => 'nextPageToken,files(id,name,mimeType,modifiedTime,size)',
                'pageSize' => 100,
            ];
            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
            }

            $response = Http::withToken($accessToken)->get($apiBase.'/files', $params);
            if (! $response->successful()) {
                $errors[] = "files.list failed: HTTP {$response->status()}";
                break;
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                $errors[] = 'files.list returned non-JSON body';
                break;
            }

            foreach (($payload['files'] ?? []) as $file) {
                try {
                    $this->ingestFile($installation, $projectKey, $accessToken, $file);
                    $added++;
                } catch (\Throwable $e) {
                    $errors[] = sprintf(
                        'file %s (%s): %s',
                        $file['name'] ?? 'unknown',
                        $file['id'] ?? '?',
                        $e->getMessage(),
                    );
                }
            }

            $pageToken = is_string($payload['nextPageToken'] ?? null) ? $payload['nextPageToken'] : null;
        } while ($pageToken !== null);

        // Initialise the changes cursor for subsequent incremental
        // syncs. `startPageToken` returns the cursor that anchors
        // "any change after this point".
        $this->initialiseChangesCursor($installationId, $accessToken);

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
            throw new ConnectorAuthException('No valid Google access token; reinstall the connector.');
        }

        $extra = $this->vault->getExtra($installationId);
        $pageToken = is_string($extra['changes_page_token'] ?? null)
            ? $extra['changes_page_token']
            : null;

        if ($pageToken === null) {
            // No cursor yet — fall back to full sync on first run.
            return $this->syncFull($installationId);
        }

        $installation = $this->loadInstallation($installationId);
        $config = (array) ($installation->config_json ?? []);
        $projectKey = (string) ($config['project_key'] ?? ('connector-'.$this->key()));

        $provider = $this->providerConfig();
        $apiBase = $provider['api_base'] ?? 'https://www.googleapis.com/drive/v3';

        $added = 0;
        $updated = 0;
        $removed = 0;
        $errors = [];
        $newToken = $pageToken;

        do {
            $params = [
                'pageToken' => $newToken,
                'fields' => 'nextPageToken,newStartPageToken,changes(fileId,removed,file(id,name,mimeType,modifiedTime,size))',
                'pageSize' => 100,
            ];

            $response = Http::withToken($accessToken)->get($apiBase.'/changes', $params);
            if (! $response->successful()) {
                $errors[] = "changes.list failed: HTTP {$response->status()}";
                break;
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                $errors[] = 'changes.list returned non-JSON body';
                break;
            }

            foreach (($payload['changes'] ?? []) as $change) {
                if (($change['removed'] ?? false) === true) {
                    // iter2 finding #7 — deletion events MUST drive
                    // an actual delete on the corresponding
                    // `knowledge_documents` row, otherwise the
                    // documents linger in RAG indefinitely and the
                    // `documentsRemoved` counter is misleading.
                    // Look up by the `drive_file_id` we stashed in
                    // `metadata` at ingest time, then funnel through
                    // `DocumentDeleter::delete()` (soft by default
                    // per `kb.deletion.soft_delete` — operator-
                    // visible in the admin trash UI).
                    $driveFileId = (string) ($change['fileId'] ?? '');
                    if ($driveFileId !== '' && $this->softDeleteByDriveFileId($installation, $driveFileId)) {
                        $removed++;
                    }
                    continue;
                }

                $file = $change['file'] ?? null;
                if (! is_array($file)) {
                    continue;
                }

                try {
                    $this->ingestFile($installation, $projectKey, $accessToken, $file);
                    $updated++;
                } catch (\Throwable $e) {
                    $errors[] = sprintf(
                        'changes file %s: %s',
                        $file['id'] ?? '?',
                        $e->getMessage(),
                    );
                }
            }

            // changes.list returns EITHER `nextPageToken` (more pages
            // for the same cursor walk) OR `newStartPageToken` (we've
            // reached the head — save this as the cursor for next
            // incremental run).
            $next = $payload['nextPageToken'] ?? null;
            if (is_string($next)) {
                $newToken = $next;
                continue;
            }

            $newStart = $payload['newStartPageToken'] ?? null;
            if (is_string($newStart)) {
                $this->persistChangesToken($installationId, $newStart);
            }
            break;
        } while (true);

        $result = new SyncResult(
            documentsAdded: $added,
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
        $token = $this->vault->getRefreshToken($installationId)
            ?? $this->vault->getAccessToken($installationId);

        if ($token !== null) {
            $provider = $this->providerConfig();
            try {
                Http::asForm()->post(
                    $provider['oauth_revoke_url'] ?? 'https://oauth2.googleapis.com/revoke',
                    ['token' => $token],
                );
            } catch (\Throwable $e) {
                // Best-effort revoke. Even if upstream is unreachable,
                // we still clear the local credential row below — the
                // operator MUST be able to disconnect locally without
                // the provider's cooperation.
                Log::warning('GoogleDriveConnector: revoke failed', [
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

        $provider = $this->providerConfig();
        $apiBase = $provider['api_base'] ?? 'https://www.googleapis.com/drive/v3';

        try {
            $response = Http::withToken($accessToken)
                ->timeout(5)
                ->get($apiBase.'/about', ['fields' => 'user']);
        } catch (\Throwable $e) {
            return HealthStatus::errored("Network error: {$e->getMessage()}");
        }

        if ($response->successful()) {
            return HealthStatus::healthy();
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return HealthStatus::errored("Authorization rejected (HTTP {$response->status()}).");
        }

        return HealthStatus::degraded("about endpoint returned HTTP {$response->status()}");
    }

    /**
     * Download a single Drive file + dispatch IngestDocumentJob.
     *
     * Google Docs export as markdown via `files.export`; other types
     * use `files.get?alt=media`. The downloaded payload is optionally
     * PII-redacted (R26 boundary) then written to the KB disk under
     * a deterministic relative path that round-trips through the
     * existing ingest pipeline.
     *
     * @param  array<string,mixed>  $file
     */
    private function ingestFile(
        \App\Models\ConnectorInstallation $installation,
        string $projectKey,
        string $accessToken,
        array $file,
    ): void {
        $fileId = (string) ($file['id'] ?? '');
        $name = (string) ($file['name'] ?? 'untitled');
        $mimeType = (string) ($file['mimeType'] ?? '');

        if ($fileId === '') {
            throw new \RuntimeException('Drive file missing id.');
        }

        $provider = $this->providerConfig();
        $apiBase = $provider['api_base'] ?? 'https://www.googleapis.com/drive/v3';

        if ($mimeType === 'application/vnd.google-apps.document') {
            // Google Doc — export as markdown.
            $download = Http::withToken($accessToken)->get(
                $apiBase.'/files/'.urlencode($fileId).'/export',
                ['mimeType' => 'text/markdown'],
            );
            $outputExtension = '.md';
            $persistedMime = 'text/markdown';
        } else {
            $download = Http::withToken($accessToken)->get(
                $apiBase.'/files/'.urlencode($fileId),
                ['alt' => 'media'],
            );
            $outputExtension = $this->extensionForMime($mimeType, $name);
            $persistedMime = $mimeType !== '' ? $mimeType : 'application/octet-stream';
        }

        if (! $download->successful()) {
            throw new \RuntimeException(
                "Drive download failed: HTTP {$download->status()}"
            );
        }

        $body = (string) $download->body();
        // R26 — PII redaction at the ingest boundary (markdown / text
        // only; binary blobs are handed off to the ingest pipeline
        // which has its own per-format extractors).
        if (str_starts_with($persistedMime, 'text/')) {
            $body = $this->maybeRedactContent($body);
        }

        // iter2 finding #6 — honour the KB storage contract.
        // `IngestDocumentJob` → `ParseMarkdownStep` reads its bytes
        // off `config('kb.sources.disk')` and re-applies
        // `config('kb.sources.path_prefix')` when computing the
        // physical storage key (see ParseMarkdownStep::resolveStoragePath).
        // The job MUST therefore be handed the UN-prefixed
        // `relativePath` (the canonical write-pattern mirrors
        // CanonicalWriter::write() which returns the relative path
        // and writes to the prefixed path internally). Previously
        // we wrote to `config('kb.disk')` (a non-existent key) and
        // dispatched with the un-prefixed path — meaning on any
        // host where KB_PATH_PREFIX is set, ingest read the wrong
        // path and the document silently vanished from RAG.
        $relativePath = sprintf(
            '%s/connectors/%s/installation-%d/%s-%s%s',
            $projectKey,
            $this->key(),
            $installation->id,
            Str::slug($name) !== '' ? Str::slug($name) : 'doc',
            $fileId,
            $outputExtension,
        );

        $disk = (string) config('kb.sources.disk', 'kb');
        $fullPath = $this->applyPathPrefix($relativePath);

        $written = Storage::disk($disk)->put($fullPath, $body);
        if ($written === false) {
            throw new \RuntimeException("Failed to write {$fullPath} to KB disk [{$disk}].");
        }

        IngestDocumentJob::dispatch(
            projectKey: $projectKey,
            relativePath: $relativePath,
            disk: $disk,
            title: $name,
            metadata: [
                'connector' => $this->key(),
                'installation_id' => $installation->id,
                'drive_file_id' => $fileId,
                'drive_mime_type' => $mimeType,
                'drive_modified_time' => $file['modifiedTime'] ?? null,
            ],
            mimeType: $persistedMime,
            tenantId: $installation->tenant_id,
        );
    }

    /**
     * iter2 finding #7 — Drive deletion event handler.
     *
     * Maps a Drive `fileId` → the matching `knowledge_documents` row
     * (looked up by `metadata->>'drive_file_id'`, scoped to the
     * installation's tenant), then funnels through `DocumentDeleter`.
     * Soft delete by default per `kb.deletion.soft_delete`; the
     * operator can hard-delete via the admin trash UI or wait for
     * `kb:prune-deleted` to do it on the retention window.
     *
     * Returns true when at least one row was acted upon, false when
     * no matching document was found (caller does NOT increment the
     * `documentsRemoved` counter in that case — silently dropped
     * changes would otherwise inflate the metric).
     */
    private function softDeleteByDriveFileId(
        \App\Models\ConnectorInstallation $installation,
        string $driveFileId,
    ): bool {
        $deleter = app(DocumentDeleter::class);

        // Eloquent JSON path syntax — `metadata->drive_file_id` works
        // on both pgsql (jsonb ->) and sqlite (json_extract). Scope by
        // tenant_id (R30) so a deletion event on tenant A never
        // affects tenant B's documents that happen to share a
        // drive_file_id (different Google account, same file id).
        $documents = KnowledgeDocument::withTrashed()
            ->forTenant($installation->tenant_id)
            ->where('metadata->drive_file_id', $driveFileId)
            ->get();

        if ($documents->isEmpty()) {
            return false;
        }

        $any = false;
        foreach ($documents as $document) {
            // Skip already-soft-deleted rows so the counter is
            // honest (re-running an incremental sweep on the same
            // cursor shouldn't double-count).
            if ($document->trashed()) {
                continue;
            }

            $deleter->delete($document);
            $any = true;
        }

        return $any;
    }

    /**
     * Apply `config('kb.sources.path_prefix')` to a relative path,
     * mirroring exactly the convention used by
     * {@see \App\Services\Kb\Canonical\CanonicalWriter::applyPathPrefix()}
     * and {@see \App\Flow\Steps\ParseMarkdownStep::resolveStoragePath()}.
     * The result is the physical storage key on the disk;
     * `IngestDocumentJob` callers always receive the UN-prefixed
     * relative path.
     */
    private function applyPathPrefix(string $relativePath): string
    {
        $prefix = (string) config('kb.sources.path_prefix', '');
        if ($prefix === '') {
            return KbPath::normalize($relativePath);
        }

        return KbPath::normalize($prefix.'/'.$relativePath);
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

    private function initialiseChangesCursor(int $installationId, string $accessToken): void
    {
        $provider = $this->providerConfig();
        $apiBase = $provider['api_base'] ?? 'https://www.googleapis.com/drive/v3';

        $response = Http::withToken($accessToken)->get($apiBase.'/changes/startPageToken');
        if (! $response->successful()) {
            return;
        }

        $payload = $response->json();
        $token = $payload['startPageToken'] ?? null;
        if (! is_string($token)) {
            return;
        }

        $this->persistChangesToken($installationId, $token);
    }

    private function persistChangesToken(int $installationId, string $token): void
    {
        $extra = $this->vault->getExtra($installationId);
        $extra['changes_page_token'] = $token;

        $access = $this->vault->getAccessToken($installationId);
        $refresh = $this->vault->getRefreshToken($installationId);
        $row = $this->vault->getCredentialRow($installationId);

        if ($access === null && $refresh === null) {
            // Nothing to persist against — leave the token unset; the
            // next sync will go through the full-sync fallback.
            return;
        }

        $this->vault->setCredentials(
            $installationId,
            accessToken: $access ?? '',
            refreshToken: $refresh,
            expiresAt: $row?->expires_at,
            extra: $extra,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function providerConfig(): array
    {
        return (array) config('connectors.providers.google-drive', []);
    }
}
