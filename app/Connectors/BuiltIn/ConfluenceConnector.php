<?php

declare(strict_types=1);

namespace App\Connectors\BuiltIn;

use App\Connectors\BaseConnector;
use App\Connectors\BuiltIn\Confluence\AtlassianPaginator;
use App\Connectors\BuiltIn\Confluence\ConfluenceStorageToMarkdown;
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
 * v4.5/W5 — Reference connector: Atlassian Confluence Cloud (built-in).
 *
 * Surfaces Confluence Cloud pages via the Atlassian REST API. OAuth2
 * 3LO (3-legged OAuth) anchored at `auth.atlassian.com`; the access
 * token is then used against the per-tenant Confluence REST endpoint
 * (`https://api.atlassian.com/ex/confluence/{cloudId}/wiki/rest/api`).
 *
 * **OAuth surface**: Atlassian OAuth2 3LO. The token-exchange endpoint
 * returns a regular `access_token` + `refresh_token`; the per-tenant
 * `cloudId` is then discovered via
 * `https://api.atlassian.com/oauth/token/accessible-resources` and
 * persisted in `extra_json.cloud_id`.
 *
 * **Sync semantics**:
 *   - Full sync — walk every accessible space (`/wiki/rest/api/space`)
 *     then walk every page per space
 *     (`/wiki/rest/api/content?spaceKey=...&expand=body.storage,version`).
 *     Convert storage-format XHTML to markdown via
 *     {@see ConfluenceStorageToMarkdown}, write to KB disk, dispatch
 *     IngestDocumentJob.
 *   - Incremental sync — CQL query against `/wiki/rest/api/content/search`
 *     filtered by `lastModified > "YYYY-MM-DD HH:mm"`.
 *
 * **Deletion reconciliation**: Confluence archives pages (no hard
 * delete via API); archived pages surface via the incremental search
 * with `status = 'archived'` — the loop routes those through
 * {@see BaseConnector::softDeleteByMetadataKey} keyed by
 * `confluence_page_id`.
 *
 * Required env (config/connectors.php::providers.confluence):
 *   - CONNECTOR_CONFLUENCE_CLIENT_ID
 *   - CONNECTOR_CONFLUENCE_CLIENT_SECRET
 *   - CONNECTOR_CONFLUENCE_REDIRECT_URI
 */
class ConfluenceConnector extends BaseConnector
{
    public function key(): string
    {
        return 'confluence';
    }

    public function displayName(): string
    {
        return 'Confluence';
    }

    public function oauthScopes(): array
    {
        return [
            'read:confluence-content.all',
            'read:confluence-space.summary',
            'read:confluence-user',
            'offline_access',
        ];
    }

    public function initiateOAuth(int $installationId): string
    {
        $provider = $this->providerConfig();
        $state = $this->issueOAuthState($installationId);

        $params = http_build_query([
            'audience' => 'api.atlassian.com',
            'client_id' => $provider['client_id'] ?? '',
            'redirect_uri' => $provider['redirect_uri'] ?? '',
            'response_type' => 'code',
            'scope' => implode(' ', $this->oauthScopes()),
            'state' => $state,
            // `prompt=consent` so refresh tokens are reliably issued.
            'prompt' => 'consent',
        ]);

        return ($provider['oauth_authorize_url'] ?? 'https://auth.atlassian.com/authorize')
            .'?'.$params;
    }

    public function handleOAuthCallback(int $installationId, Request $request): void
    {
        $code = $request->input('code');
        $state = $request->input('state');

        if (! is_string($code) || $code === '') {
            throw new ConnectorAuthException('Confluence OAuth callback missing `code` parameter.');
        }
        if (! is_string($state) || ! $this->consumeOAuthState($installationId, $state)) {
            throw new ConnectorAuthException('Confluence OAuth callback state token invalid or expired.');
        }

        $provider = $this->providerConfig();

        // Step 1 — token exchange.
        $response = Http::asJson()
            ->acceptJson()
            ->post($provider['oauth_token_url'] ?? 'https://auth.atlassian.com/oauth/token', [
                'grant_type' => 'authorization_code',
                'client_id' => $provider['client_id'] ?? '',
                'client_secret' => $provider['client_secret'] ?? '',
                'code' => $code,
                'redirect_uri' => $provider['redirect_uri'] ?? '',
            ]);

        if (! $response->successful()) {
            throw new ConnectorAuthException(sprintf(
                'Confluence OAuth token exchange failed: HTTP %d %s',
                $response->status(),
                Str::limit((string) $response->body(), 200),
            ));
        }

        $payload = $response->json();
        if (! is_array($payload) || ! isset($payload['access_token'])) {
            throw new ConnectorAuthException('Confluence OAuth token exchange returned no access_token.');
        }

        $accessToken = (string) $payload['access_token'];
        $expiresAt = isset($payload['expires_in']) && is_numeric($payload['expires_in'])
            ? Carbon::now()->addSeconds((int) $payload['expires_in'])
            : null;

        // Step 2 — resolve the cloud id. Atlassian's accessible-resources
        // endpoint returns every Atlassian product instance the user
        // authorised; we pick the first Confluence-capable resource
        // (one with `read:confluence-*` in its scopes list).
        $cloudId = $this->resolveCloudId($provider, $accessToken);

        $this->vault->setCredentials(
            $installationId,
            accessToken: $accessToken,
            refreshToken: isset($payload['refresh_token']) && is_string($payload['refresh_token'])
                ? $payload['refresh_token']
                : null,
            expiresAt: $expiresAt,
            extra: [
                'token_type' => $payload['token_type'] ?? 'Bearer',
                'scope' => $payload['scope'] ?? implode(' ', $this->oauthScopes()),
                'cloud_id' => $cloudId,
            ],
        );

        $this->emitAudit('installed', installationId: $installationId, metadata: [
            'expires_at' => $expiresAt?->toIso8601String(),
            'cloud_id' => $cloudId,
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
        $response = Http::asJson()
            ->acceptJson()
            ->post($provider['oauth_token_url'] ?? 'https://auth.atlassian.com/oauth/token', [
                'grant_type' => 'refresh_token',
                'client_id' => $provider['client_id'] ?? '',
                'client_secret' => $provider['client_secret'] ?? '',
                'refresh_token' => $refresh,
            ]);

        if (! $response->successful()) {
            throw new ConnectorAuthException(sprintf(
                'Confluence OAuth refresh failed: HTTP %d %s',
                $response->status(),
                Str::limit((string) $response->body(), 200),
            ));
        }

        $payload = $response->json();
        if (! is_array($payload) || ! isset($payload['access_token'])) {
            throw new ConnectorAuthException('Confluence OAuth refresh returned no access_token.');
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
            throw new ConnectorAuthException('No valid Confluence access token; reinstall the connector.');
        }

        $cloudId = (string) ($this->vault->getExtraKey($installationId, 'cloud_id') ?? '');
        if ($cloudId === '') {
            throw new ConnectorAuthException('Confluence cloud_id missing; reinstall the connector.');
        }

        $installation = $this->loadInstallation($installationId);
        $config = (array) ($installation->config_json ?? []);
        $projectKey = (string) ($config['project_key'] ?? ('connector-'.$this->key()));

        $added = 0;
        $errors = [];

        try {
            // Step 1 — walk every space.
            foreach ($this->iterateSpaces($accessToken, $cloudId) as $space) {
                $spaceKey = (string) ($space['key'] ?? '');
                if ($spaceKey === '') {
                    continue;
                }

                // Step 2 — walk every page in the space.
                try {
                    foreach ($this->iteratePagesInSpace($accessToken, $cloudId, $spaceKey) as $page) {
                        try {
                            $this->ingestPage($installation, $projectKey, $accessToken, $cloudId, $page, $spaceKey);
                            $added++;
                        } catch (\Throwable $e) {
                            $errors[] = sprintf(
                                'page %s in space %s: %s',
                                $page['id'] ?? '?',
                                $spaceKey,
                                $e->getMessage(),
                            );
                        }
                    }
                } catch (ConnectorPaginationLimitException $e) {
                    $errors[] = sprintf(
                        'space %s: pages truncated at maxPages=%d',
                        $spaceKey,
                        $e->maxPages,
                    );
                } catch (ConnectorApiException $e) {
                    $errors[] = sprintf('space %s: %s', $spaceKey, $e->getMessage());
                }
            }
        } catch (ConnectorPaginationLimitException $e) {
            $errors[] = sprintf(
                'spaces truncated at maxPages=%d',
                $e->maxPages,
            );
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
            throw new ConnectorAuthException('No valid Confluence access token; reinstall the connector.');
        }

        if ($since === null) {
            return $this->syncFull($installationId);
        }

        $cloudId = (string) ($this->vault->getExtraKey($installationId, 'cloud_id') ?? '');
        if ($cloudId === '') {
            throw new ConnectorAuthException('Confluence cloud_id missing; reinstall the connector.');
        }

        $installation = $this->loadInstallation($installationId);
        $config = (array) ($installation->config_json ?? []);
        $projectKey = (string) ($config['project_key'] ?? ('connector-'.$this->key()));

        $updated = 0;
        $removed = 0;
        $errors = [];

        // CQL `lastModified` accepts a `"YYYY-MM-DD HH:mm"` timestamp
        // in the search query's reserved tokens; quote it so commas in
        // the timestamp don't break the parser.
        $cql = sprintf(
            'type = "page" AND lastModified > "%s"',
            $since->copy()->utc()->format('Y-m-d H:i'),
        );

        $paginator = new AtlassianPaginator;
        $initialUrl = $this->wikiBase($cloudId).'/content/search?'.http_build_query([
            'cql' => $cql,
            'limit' => 25,
            'expand' => 'body.storage,version,space,status',
        ]);
        $fetch = function (?string $nextLink) use ($accessToken, $initialUrl, $cloudId) {
            // Atlassian `_links.next` is relative to the /wiki/rest/api
            // tree; absolutise it against the cloud base.
            $target = $nextLink !== null
                ? $this->absolutiseNextLink($cloudId, $nextLink)
                : $initialUrl;

            return Http::withToken($accessToken)
                ->acceptJson()
                ->get($target);
        };

        try {
            foreach ($paginator->walkLazy($fetch) as $batch) {
                foreach ($batch as $page) {
                    $pageId = (string) ($page['id'] ?? '');
                    if ($pageId === '') {
                        continue;
                    }

                    // Archived = deleted from RAG's perspective.
                    $status = strtolower((string) ($page['status'] ?? 'current'));
                    if ($status === 'archived' || $status === 'trashed') {
                        if ($this->softDeleteByMetadataKey($installation, 'confluence_page_id', $pageId)) {
                            $removed++;
                        }
                        continue;
                    }

                    $spaceKey = (string) ($page['space']['key'] ?? '');

                    try {
                        $this->ingestPage($installation, $projectKey, $accessToken, $cloudId, $page, $spaceKey);
                        $updated++;
                    } catch (\Throwable $e) {
                        $errors[] = sprintf('page %s: %s', $pageId, $e->getMessage());
                    }
                }
            }
        } catch (ConnectorPaginationLimitException $e) {
            $errors[] = sprintf(
                'incremental sync truncated at maxPages=%d (Confluence still reports _links.next); raise the cap or trigger another sync.',
                $e->maxPages,
            );
            Log::warning('ConfluenceConnector::syncIncremental truncated by pagination cap', [
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
            ['mode' => 'incremental', 'since' => $since->toIso8601String()],
        ));

        return $result;
    }

    public function disconnect(int $installationId): void
    {
        // Atlassian doesn't expose a programmatic revoke endpoint for
        // OAuth 3LO grants — operators wanting full revocation must
        // delete the connection from id.atlassian.com → Privacy →
        // Connected apps. Disconnect therefore just clears local
        // credentials; the access token will expire naturally.
        $this->vault->clearCredentials($installationId);
        $this->emitAudit('disconnected', installationId: $installationId);
    }

    public function health(int $installationId): HealthStatus
    {
        $accessToken = $this->vault->getAccessToken($installationId);
        if ($accessToken === null) {
            return HealthStatus::errored('No valid access token (credentials missing or expired).');
        }

        $cloudId = (string) ($this->vault->getExtraKey($installationId, 'cloud_id') ?? '');
        if ($cloudId === '') {
            return HealthStatus::errored('cloud_id missing — reinstall the connector.');
        }

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->timeout(5)
                ->get($this->wikiBase($cloudId).'/user/current');
        } catch (\Throwable $e) {
            return HealthStatus::errored("Network error: {$e->getMessage()}");
        }

        if ($response->successful()) {
            return HealthStatus::healthy();
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return HealthStatus::errored("Authorization rejected (HTTP {$response->status()}).");
        }

        return HealthStatus::degraded("user.current returned HTTP {$response->status()}");
    }

    /**
     * Walk `/wiki/rest/api/space` for every accessible space. Each
     * batch is yielded lazily so memory stays bounded.
     *
     * @return \Generator<int, array<string,mixed>>
     */
    private function iterateSpaces(string $accessToken, string $cloudId): \Generator
    {
        $paginator = new AtlassianPaginator;
        $initialUrl = $this->wikiBase($cloudId).'/space?'.http_build_query([
            'limit' => 50,
            'type' => 'global',
        ]);

        foreach ($paginator->walkLazy(fn (?string $nextLink) => Http::withToken($accessToken)
            ->acceptJson()
            ->get($nextLink !== null ? $this->absolutiseNextLink($cloudId, $nextLink) : $initialUrl)) as $batch) {
            foreach ($batch as $space) {
                yield $space;
            }
        }
    }

    /**
     * Walk every page in the given space. Each batch is yielded lazily.
     *
     * @return \Generator<int, array<string,mixed>>
     */
    private function iteratePagesInSpace(string $accessToken, string $cloudId, string $spaceKey): \Generator
    {
        $paginator = new AtlassianPaginator;
        $initialUrl = $this->wikiBase($cloudId).'/content?'.http_build_query([
            'spaceKey' => $spaceKey,
            'type' => 'page',
            'expand' => 'body.storage,version,space,status',
            'limit' => 25,
        ]);

        foreach ($paginator->walkLazy(fn (?string $nextLink) => Http::withToken($accessToken)
            ->acceptJson()
            ->get($nextLink !== null ? $this->absolutiseNextLink($cloudId, $nextLink) : $initialUrl)) as $batch) {
            foreach ($batch as $page) {
                yield $page;
            }
        }
    }

    /**
     * Ingest one Confluence page — convert storage-format to markdown,
     * write to KB disk, dispatch IngestDocumentJob.
     *
     * @param  array<string,mixed>  $page
     */
    private function ingestPage(
        \App\Models\ConnectorInstallation $installation,
        string $projectKey,
        string $accessToken,
        string $cloudId,
        array $page,
        string $spaceKey,
    ): void {
        $pageId = (string) ($page['id'] ?? '');
        if ($pageId === '') {
            throw new \RuntimeException('Confluence page missing id.');
        }

        $title = (string) ($page['title'] ?? 'Confluence page');

        // The page payload from search/content endpoints already
        // includes `body.storage.value` when we expand it; fall back
        // to a dedicated fetch otherwise.
        $storage = (string) ($page['body']['storage']['value'] ?? '');
        if ($storage === '') {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->get($this->wikiBase($cloudId).'/content/'.urlencode($pageId), [
                    'expand' => 'body.storage,version,space',
                ]);
            if (! $response->successful()) {
                $this->throwAuthOrApi($response, 'content.get');
            }
            $fullPage = $response->json();
            if (is_array($fullPage)) {
                $storage = (string) ($fullPage['body']['storage']['value'] ?? '');
                if ($spaceKey === '') {
                    $spaceKey = (string) ($fullPage['space']['key'] ?? '');
                }
            }
        }

        $converter = new ConfluenceStorageToMarkdown;
        $markdown = $converter->convert($storage);
        if ($markdown === '') {
            // Empty page body — skip rather than write 0-byte ingest
            // file. Operator-visible via the per-page error counter.
            return;
        }

        $markdown = $this->maybeRedactContent($markdown);

        if ($title !== '') {
            $markdown = "# {$title}\n\n{$markdown}";
        }

        $cleanSpaceKey = $spaceKey !== '' ? Str::slug($spaceKey) : 'space';
        $cleanPageId = preg_replace('/[^a-z0-9\-]/i', '', $pageId) ?? $pageId;
        $relativePath = sprintf(
            '%s/connectors/confluence/%s/%s.md',
            $projectKey,
            $cleanSpaceKey,
            $cleanPageId,
        );

        $paths = $this->resolveKbSourcePath($relativePath);

        $written = Storage::disk($paths['disk'])->put($paths['absolute'], $markdown);
        if ($written === false) {
            throw new \RuntimeException("Failed to write {$paths['absolute']} to KB disk [{$paths['disk']}].");
        }

        $labels = $this->extractLabels($page);
        $ancestorTitles = $this->extractAncestorTitles($page);
        $version = $page['version']['number'] ?? null;
        $lastModified = $page['version']['when'] ?? null;
        $status = (string) ($page['status'] ?? 'current');
        $hasRestrictions = ! empty($page['restrictions']['read']['restrictions']['user']['results'] ?? []);

        $confluenceFields = [
            'space_key'           => $spaceKey,
            'space_name'          => $page['space']['name'] ?? null,
            'cloud_id'            => $cloudId,
            'page_id'             => $pageId,
            'version'             => $version,
            'labels'              => $labels,
            'ancestor_titles'     => $ancestorTitles,
            'restrictions_present' => $hasRestrictions,
            'status'              => $status,
        ];

        $sourceMeta = (new \App\Connectors\Support\SourceAwareMetadataBuilder())->build(
            base: [
                'connector' => $this->key(),
                'installation_id' => $installation->id,
                'confluence_page_id' => $pageId,
                'confluence_space_key' => $spaceKey,
                'confluence_cloud_id' => $cloudId,
                'confluence_version' => $version,
                'confluence_last_modified' => $lastModified,
                'confluence_status' => $status,
            ],
            sourceKey: 'confluence',
            sourceFields: $confluenceFields,
            tags: $labels,
            statusActive: $status === 'current',
            lastModified: $lastModified,
            owner: null,
        );

        IngestDocumentJob::dispatch(
            projectKey: $projectKey,
            relativePath: $paths['relative'],
            disk: $paths['disk'],
            title: $title !== '' ? $title : 'Confluence page',
            metadata: $sourceMeta,
            mimeType: \App\Connectors\Support\VendorMimeSelector::MIME_CONFLUENCE_PAGE,
            tenantId: $installation->tenant_id,
        );
    }

    /**
     * Confluence Cloud nests labels under `metadata.labels.results[*].name`
     * when the page was fetched with `expand=metadata.labels`. Missing /
     * malformed expansions degrade to an empty list — the reranker's
     * tag-overlap signal is additive, not gating.
     *
     * @param  array<string,mixed>  $page
     * @return list<string>
     */
    private function extractLabels(array $page): array
    {
        $results = $page['metadata']['labels']['results'] ?? [];
        if (! is_array($results)) {
            return [];
        }
        $out = [];
        foreach ($results as $row) {
            if (is_array($row) && isset($row['name']) && is_string($row['name'])) {
                $out[] = $row['name'];
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Ancestor pages — Confluence returns them root → leaf. We keep that
     * order so the chunker can render "Engineering > Architecture > …"
     * breadcrumbs without re-sorting.
     *
     * @param  array<string,mixed>  $page
     * @return list<string>
     */
    private function extractAncestorTitles(array $page): array
    {
        $ancestors = $page['ancestors'] ?? [];
        if (! is_array($ancestors)) {
            return [];
        }
        $out = [];
        foreach ($ancestors as $a) {
            if (is_array($a) && isset($a['title']) && is_string($a['title']) && $a['title'] !== '') {
                $out[] = $a['title'];
            }
        }
        return $out;
    }

    /**
     * Resolve the per-tenant cloud id after a successful OAuth
     * exchange.  Atlassian's `accessible-resources` endpoint returns
     * every product instance the user authorised; we pick the first
     * Confluence-capable resource (scopes contain a
     * `read:confluence-*` entry).
     *
     * @param  array<string,mixed>  $provider
     */
    private function resolveCloudId(array $provider, string $accessToken): string
    {
        $url = $provider['accessible_resources_url'] ?? 'https://api.atlassian.com/oauth/token/accessible-resources';

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->get((string) $url);
        } catch (\Throwable $e) {
            throw new ConnectorAuthException(
                'Confluence accessible-resources lookup failed: '.$e->getMessage(),
            );
        }

        if (! $response->successful()) {
            throw new ConnectorAuthException(sprintf(
                'Confluence accessible-resources lookup failed: HTTP %d %s',
                $response->status(),
                Str::limit((string) $response->body(), 200),
            ));
        }

        $resources = $response->json();
        if (! is_array($resources) || $resources === []) {
            throw new ConnectorAuthException(
                'Confluence accessible-resources returned no resources — user has not granted access to any Atlassian site.',
            );
        }

        // Pick the first resource whose scopes include any
        // `read:confluence-*` permission; that's the Confluence-capable
        // site. Falls back to the first resource if no scope match (a
        // misconfigured app might still discover its only resource).
        foreach ($resources as $resource) {
            if (! is_array($resource)) {
                continue;
            }
            $scopes = $resource['scopes'] ?? [];
            if (! is_array($scopes)) {
                continue;
            }
            foreach ($scopes as $scope) {
                if (is_string($scope) && str_starts_with($scope, 'read:confluence')) {
                    $cloudId = $resource['id'] ?? null;
                    if (! is_string($cloudId) || trim($cloudId) === '') {
                        throw new ConnectorAuthException(
                            'Confluence accessible-resources returned a Confluence-capable site with a missing id.',
                        );
                    }

                    return $cloudId;
                }
            }
        }

        $first = $resources[0] ?? null;
        if (is_array($first)) {
            $cloudId = $first['id'] ?? null;
            if (! is_string($cloudId) || trim($cloudId) === '') {
                throw new ConnectorAuthException(
                    'Confluence accessible-resources returned a site with a missing id.',
                );
            }

            return $cloudId;
        }

        throw new ConnectorAuthException(
            'Confluence accessible-resources returned no Confluence-capable site.',
        );
    }

    /**
     * Atlassian's `_links.next` URL is a path relative to the wiki
     * REST tree. Resolve it against the cloud base.
     */
    private function absolutiseNextLink(string $cloudId, string $nextLink): string
    {
        if (str_starts_with($nextLink, 'http://') || str_starts_with($nextLink, 'https://')) {
            return $nextLink;
        }

        // Confluence returns the next link either as a full path
        // including `/wiki/rest/api/...` OR as a relative URI like
        // `/rest/api/content?...`. Build a stable absolute URL by
        // joining against the wiki base when the prefix is missing.
        $base = $this->wikiBase($cloudId);

        if (str_starts_with($nextLink, '/wiki/')) {
            // Strip the duplicated `/wiki/rest/api` prefix and stitch
            // it back via the canonical base.
            $relative = substr($nextLink, strlen('/wiki/rest/api'));

            return $base.$relative;
        }

        if (str_starts_with($nextLink, '/rest/api/')) {
            return $base.substr($nextLink, strlen('/rest/api'));
        }

        if (! str_starts_with($nextLink, '/')) {
            return $base.'/'.$nextLink;
        }

        return $base.$nextLink;
    }

    private function throwAuthOrApi(Response $response, string $context): never
    {
        $message = sprintf(
            'Confluence %s failed: HTTP %d %s',
            $context,
            $response->status(),
            Str::limit((string) $response->body(), 200),
        );

        if ($response->status() === 401 || $response->status() === 403) {
            throw new ConnectorAuthException($message);
        }

        throw new ConnectorApiException($message);
    }

    private function wikiBase(string $cloudId): string
    {
        $config = (string) ($this->providerConfig()['api_base'] ?? '');
        $base = $config !== '' ? rtrim($config, '/') : 'https://api.atlassian.com';

        return $base.'/ex/confluence/'.$cloudId.'/wiki/rest/api';
    }

    /**
     * @return array<string,mixed>
     */
    private function providerConfig(): array
    {
        return (array) config('connectors.providers.confluence', []);
    }
}
