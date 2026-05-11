<?php

declare(strict_types=1);

namespace App\Connectors\BuiltIn;

use App\Connectors\BaseConnector;
use App\Connectors\BuiltIn\Evernote\EnmlToMarkdown;
use App\Connectors\Exceptions\ConnectorApiException;
use App\Connectors\Exceptions\ConnectorAuthException;
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
 * v4.5/W4 — Reference connector: Evernote (built-in, DUAL-MODE).
 *
 * **Mode A — OAuth2 API sync.** Standard OAuth2 dance to the Evernote
 * developer endpoint, then walk `/v1/notes/search` (Evernote Cloud API
 * v1) + `/v1/notes/{guid}` to pull every note's ENML content, convert
 * to markdown via {@see EnmlToMarkdown}, write to the KB disk, dispatch
 * {@see IngestDocumentJob}.
 *
 * **Mode B — `.enex` bulk fallback.** The admin posts an `.enex`
 * export file to `POST /api/admin/connectors/evernote/import-enex`.
 * {@see \App\Connectors\BuiltIn\Evernote\EnexImporter} streams the XML,
 * ingests each `<note>` element directly, bypasses OAuth. Use this when
 * the operator doesn't want to (or can't) wire an Evernote developer
 * key — e.g. consuming a personal export from `evernote.com/Settings →
 * Export`. The two modes coexist: nothing stops an operator using OAuth
 * for ongoing sync AND a one-shot `.enex` for the backfill.
 *
 * Sync semantics (Mode A):
 *   - Full sync — POST /v1/notes/search with empty filter → walk every
 *     hit, fetch ENML, ingest.
 *   - Incremental sync — same search filtered by `updated > $since`
 *     (Evernote's `notesMetadata` filter supports `updated` range).
 *     `notesMetadata.spec` requests minimal metadata so we don't waste
 *     ENML bytes on stale notes.
 *
 * Deletion reconciliation:
 *   - Evernote's API surfaces deleted notes via `deleted != null` on the
 *     metadata object. The incremental loop routes those through
 *     {@see BaseConnector::softDeleteByMetadataKey} keyed by
 *     `evernote_note_guid`.
 *
 * Required env (config/connectors.php::providers.evernote):
 *   - CONNECTOR_EVERNOTE_CLIENT_ID
 *   - CONNECTOR_EVERNOTE_CLIENT_SECRET
 *   - CONNECTOR_EVERNOTE_REDIRECT_URI
 *   - CONNECTOR_EVERNOTE_API_BASE      (e.g. https://api.evernote.com — yes
 *     Evernote uses a versioned REST API at /v1; sandbox at
 *     sandbox.evernote.com for local dev)
 */
class EvernoteConnector extends BaseConnector
{
    public function key(): string
    {
        return 'evernote';
    }

    public function displayName(): string
    {
        return 'Evernote';
    }

    public function iconUrl(): string
    {
        return asset('connectors/evernote.svg');
    }

    /**
     * Evernote OAuth2 scopes — read-only access to the user's notes +
     * notebooks. We never ask for write because AskMyDocs only ingests.
     */
    public function oauthScopes(): array
    {
        return [
            'basic',
            'notes.read',
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
            'state' => $state,
        ]);

        return ($provider['oauth_authorize_url'] ?? 'https://www.evernote.com/oauth2/authorize')
            .'?'.$params;
    }

    public function handleOAuthCallback(int $installationId, Request $request): void
    {
        $code = $request->input('code');
        $state = $request->input('state');

        if (! is_string($code) || $code === '') {
            throw new ConnectorAuthException('Evernote OAuth callback missing `code` parameter.');
        }
        if (! is_string($state) || ! $this->consumeOAuthState($installationId, $state)) {
            throw new ConnectorAuthException('Evernote OAuth callback state token invalid or expired.');
        }

        $provider = $this->providerConfig();

        $response = Http::asForm()
            ->acceptJson()
            ->post($provider['oauth_token_url'] ?? 'https://www.evernote.com/oauth2/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => $provider['client_id'] ?? '',
                'client_secret' => $provider['client_secret'] ?? '',
                'redirect_uri' => $provider['redirect_uri'] ?? '',
            ]);

        if (! $response->successful()) {
            throw new ConnectorAuthException(sprintf(
                'Evernote OAuth token exchange failed: HTTP %d %s',
                $response->status(),
                Str::limit((string) $response->body(), 200),
            ));
        }

        $payload = $response->json();
        if (! is_array($payload) || ! isset($payload['access_token'])) {
            throw new ConnectorAuthException('Evernote OAuth token exchange returned no access_token.');
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
                'evernote_user_id' => $payload['user_id'] ?? null,
                'evernote_shard' => $payload['shard'] ?? null,
            ],
        );

        $this->emitAudit('installed', installationId: $installationId, metadata: [
            'expires_at' => $expiresAt?->toIso8601String(),
            'evernote_user_id' => $payload['user_id'] ?? null,
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
            ->post($provider['oauth_token_url'] ?? 'https://www.evernote.com/oauth2/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refresh,
                'client_id' => $provider['client_id'] ?? '',
                'client_secret' => $provider['client_secret'] ?? '',
            ]);

        if (! $response->successful()) {
            throw new ConnectorAuthException(sprintf(
                'Evernote OAuth refresh failed: HTTP %d %s',
                $response->status(),
                Str::limit((string) $response->body(), 200),
            ));
        }

        $payload = $response->json();
        if (! is_array($payload) || ! isset($payload['access_token'])) {
            throw new ConnectorAuthException('Evernote OAuth refresh returned no access_token.');
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
            throw new ConnectorAuthException('No valid Evernote access token; reinstall the connector.');
        }

        $installation = $this->loadInstallation($installationId);
        $config = (array) ($installation->config_json ?? []);
        $projectKey = (string) ($config['project_key'] ?? ('connector-'.$this->key()));

        $added = 0;
        $errors = [];

        try {
            foreach ($this->iterateNotesMetadata($accessToken, since: null) as $metadata) {
                $guid = (string) ($metadata['guid'] ?? '');
                if ($guid === '') {
                    continue;
                }
                try {
                    $this->ingestNote($installation, $projectKey, $accessToken, $guid, $metadata);
                    $added++;
                } catch (\Throwable $e) {
                    $errors[] = sprintf('note %s: %s', $guid, $e->getMessage());
                    Log::error('EvernoteConnector::syncFull failed for note', [
                        'installation_id' => $installationId,
                        'note_guid' => $guid,
                        'exception' => $e->getMessage(),
                    ]);
                }
            }
        } catch (ConnectorApiException $e) {
            $errors[] = $e->getMessage();
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

        $this->vault->setExtraKey(
            $installationId,
            'last_full_sync_at',
            Carbon::now()->toIso8601String(),
        );

        return $result;
    }

    public function syncIncremental(int $installationId, ?Carbon $since): SyncResult
    {
        $accessToken = $this->refreshTokenIfExpired($installationId);
        if ($accessToken === null) {
            throw new ConnectorAuthException('No valid Evernote access token; reinstall the connector.');
        }

        if ($since === null) {
            return $this->syncFull($installationId);
        }

        $installation = $this->loadInstallation($installationId);
        $config = (array) ($installation->config_json ?? []);
        $projectKey = (string) ($config['project_key'] ?? ('connector-'.$this->key()));

        $updated = 0;
        $removed = 0;
        $errors = [];

        try {
            foreach ($this->iterateNotesMetadata($accessToken, since: $since) as $metadata) {
                $guid = (string) ($metadata['guid'] ?? '');
                if ($guid === '') {
                    continue;
                }

                // Deleted notes carry a non-null `deleted` timestamp.
                $deletedAt = $metadata['deleted'] ?? null;
                if ($deletedAt !== null) {
                    if ($this->softDeleteByMetadataKey($installation, 'evernote_note_guid', $guid)) {
                        $removed++;
                    }
                    continue;
                }

                try {
                    $this->ingestNote($installation, $projectKey, $accessToken, $guid, $metadata);
                    $updated++;
                } catch (\Throwable $e) {
                    $errors[] = sprintf('note %s: %s', $guid, $e->getMessage());
                }
            }
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
            ['mode' => 'incremental', 'since' => $since->toIso8601String()],
        ));

        return $result;
    }

    public function disconnect(int $installationId): void
    {
        $accessToken = $this->vault->getAccessToken($installationId);
        if ($accessToken !== null) {
            $provider = $this->providerConfig();
            $revokeUrl = $provider['oauth_revoke_url'] ?? 'https://www.evernote.com/oauth2/revoke';
            try {
                Http::asForm()->post($revokeUrl, [
                    'token' => $accessToken,
                    'client_id' => $provider['client_id'] ?? '',
                    'client_secret' => $provider['client_secret'] ?? '',
                ]);
            } catch (\Throwable $e) {
                Log::warning('EvernoteConnector: revoke failed', [
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
                ->get($this->apiBase().'/users/me');
        } catch (\Throwable $e) {
            return HealthStatus::errored("Network error: {$e->getMessage()}");
        }

        if ($response->successful()) {
            return HealthStatus::healthy();
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return HealthStatus::errored("Authorization rejected (HTTP {$response->status()}).");
        }

        return HealthStatus::degraded("users.me returned HTTP {$response->status()}");
    }

    /**
     * Walk `/v1/notes/search` with offset-based pagination. Yields one
     * metadata record at a time so the caller (full/incremental sync)
     * can stream-process without holding everything in memory.
     *
     * Evernote's `notesMetadata` endpoint returns:
     *   {
     *     notes: [
     *       { guid, title, updated, created, deleted?, contentHash, ... },
     *       ...
     *     ],
     *     totalNotes: number,
     *     startIndex: number,
     *     updateCount: number
     *   }
     *
     * Pagination is `offset` + `maxNotes` (no cursor). We cap at 250
     * (Evernote's documented maximum per call) and walk by incrementing
     * offset until we've consumed totalNotes.
     *
     * @return \Generator<int, array<string,mixed>>
     */
    private function iterateNotesMetadata(string $accessToken, ?Carbon $since): \Generator
    {
        $maxNotes = 250;
        $offset = 0;
        $maxIterations = 200; // safety cap — Evernote workspaces top out around 100k notes per user

        for ($i = 0; $i < $maxIterations; $i++) {
            $body = [
                'offset' => $offset,
                'maxNotes' => $maxNotes,
                'spec' => [
                    'includeTitle' => true,
                    'includeContentLength' => false,
                    'includeCreated' => true,
                    'includeUpdated' => true,
                    'includeDeleted' => true,
                    'includeNotebookGuid' => true,
                    'includeTagGuids' => true,
                ],
            ];
            if ($since !== null) {
                // Evernote search-grammar `updated:<datetime>` expects
                // a UTC Zulu timestamp formatted `YYYYMMDDTHHMMSSZ`
                // (NOT ms-since-epoch, despite earlier docblock wording;
                // NOT the locale-dependent `p` token either — `p` would
                // render `a.m.`/`p.m.` and break the filter). See
                // https://dev.evernote.com/doc/articles/search_grammar.php
                $body['filter'] = [
                    'ascending' => false,
                    'words' => sprintf(
                        'updated:%s',
                        $since->copy()->utc()->format('Ymd\\THis\\Z'),
                    ),
                ];
            }

            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->asJson()
                ->post($this->apiBase().'/notes/search', $body);

            if (! $response->successful()) {
                $this->throwAuthOrApi($response, 'notes.search');
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                throw new ConnectorApiException('Evernote notes.search returned non-JSON body.');
            }

            $notes = $payload['notes'] ?? [];
            if (! is_array($notes) || $notes === []) {
                return;
            }

            foreach ($notes as $note) {
                if (is_array($note)) {
                    yield $note;
                }
            }

            $totalNotes = (int) ($payload['totalNotes'] ?? 0);
            $offset += count($notes);
            if ($offset >= $totalNotes) {
                return;
            }
        }
    }

    /**
     * Fetch one note's full ENML body + ingest it.
     *
     * @param  array<string,mixed>  $metadata
     */
    private function ingestNote(
        \App\Models\ConnectorInstallation $installation,
        string $projectKey,
        string $accessToken,
        string $guid,
        array $metadata,
    ): void {
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->get($this->apiBase().'/notes/'.urlencode($guid), [
                'withContent' => 'true',
            ]);

        if (! $response->successful()) {
            $this->throwAuthOrApi($response, 'notes.get');
        }

        $note = $response->json();
        if (! is_array($note)) {
            throw new ConnectorApiException('Evernote notes.get returned non-JSON body.');
        }

        $enml = (string) ($note['content'] ?? '');
        $title = (string) ($note['title'] ?? ($metadata['title'] ?? 'Evernote note'));

        $converter = new EnmlToMarkdown;
        $markdown = $converter->convert($enml);
        if ($markdown === '') {
            // Empty note — skip rather than write 0-byte ingest file.
            return;
        }

        $markdown = $this->maybeRedactContent($markdown);

        if ($title !== '') {
            $markdown = "# {$title}\n\n{$markdown}";
        }

        $cleanGuid = preg_replace('/[^a-z0-9\-]/i', '', $guid) ?? $guid;
        $relativePath = sprintf(
            '%s/connectors/evernote/installation-%d/%s.md',
            $projectKey,
            $installation->id,
            $cleanGuid,
        );

        $paths = $this->resolveKbSourcePath($relativePath);

        $written = Storage::disk($paths['disk'])->put($paths['absolute'], $markdown);
        if ($written === false) {
            throw new \RuntimeException("Failed to write {$paths['absolute']} to KB disk [{$paths['disk']}].");
        }

        IngestDocumentJob::dispatch(
            projectKey: $projectKey,
            relativePath: $paths['relative'],
            disk: $paths['disk'],
            title: $title !== '' ? $title : 'Evernote note',
            metadata: [
                'connector' => $this->key(),
                'installation_id' => $installation->id,
                'evernote_note_guid' => $guid,
                'evernote_notebook_guid' => $metadata['notebookGuid'] ?? ($note['notebookGuid'] ?? null),
                'evernote_created' => $note['created'] ?? ($metadata['created'] ?? null),
                'evernote_updated' => $note['updated'] ?? ($metadata['updated'] ?? null),
                'evernote_tag_guids' => $note['tagGuids'] ?? ($metadata['tagGuids'] ?? null),
                'evernote_source' => 'oauth',
            ],
            mimeType: 'text/markdown',
            tenantId: $installation->tenant_id,
        );
    }

    private function throwAuthOrApi(Response $response, string $context): never
    {
        $message = sprintf(
            'Evernote %s failed: HTTP %d %s',
            $context,
            $response->status(),
            Str::limit((string) $response->body(), 200),
        );

        if ($response->status() === 401 || $response->status() === 403) {
            throw new ConnectorAuthException($message);
        }

        throw new ConnectorApiException($message);
    }

    private function apiBase(): string
    {
        $config = (string) ($this->providerConfig()['api_base'] ?? '');
        $base = $config !== '' ? rtrim($config, '/') : 'https://api.evernote.com';

        if (str_ends_with($base, '/v1')) {
            return $base;
        }

        return $base.'/v1';
    }

    /**
     * @return array<string,mixed>
     */
    private function providerConfig(): array
    {
        return (array) config('connectors.providers.evernote', []);
    }
}
