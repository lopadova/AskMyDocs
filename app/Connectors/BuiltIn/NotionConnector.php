<?php

declare(strict_types=1);

namespace App\Connectors\BuiltIn;

use App\Connectors\BaseConnector;
use App\Connectors\BuiltIn\Notion\NotionBlockToMarkdown;
use App\Connectors\BuiltIn\Notion\NotionPaginator;
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
 * v4.5/W2 — Reference connector: Notion (built-in).
 *
 * Notion's OAuth2 flow is workspace-scoped (Notion calls it "Public
 * Integration"). Successful auth returns:
 *   - access_token       (long-lived; Notion tokens DO NOT expire)
 *   - bot_id             (the integration's identity in the workspace)
 *   - workspace_id       (uuid of the connected workspace)
 *   - workspace_name     (human label rendered in the admin UI)
 *
 * Sync semantics:
 *   - Full sync — POST /v1/search with object=page filter; for every
 *     hit, GET /v1/blocks/{page_id}/children, recursively hydrate
 *     `has_children` nodes, then convert to markdown via
 *     {@see NotionBlockToMarkdown}. The markdown is written to the
 *     KB disk via the shared `resolveKbSourcePath()` helper.
 *   - Incremental sync — same search but sorted by `last_edited_time`
 *     desc; client-side filter on `$since`. Notion does NOT have a
 *     delta endpoint or a deletion-event stream, so archived pages
 *     are reconciled by re-checking the `archived: true` flag on
 *     pages previously ingested (looked up via metadata).
 *
 * Lifecycle:
 *   - disconnect() clears local credentials only — Notion has no
 *     revoke endpoint. Operators wanting full revocation must delete
 *     the integration from their Notion workspace settings.
 *   - health() pings GET /v1/users/me to verify the token is still
 *     trusted by the workspace.
 *
 * Required env (config/connectors.php::providers.notion):
 *   - CONNECTOR_NOTION_CLIENT_ID
 *   - CONNECTOR_NOTION_CLIENT_SECRET
 *   - CONNECTOR_NOTION_REDIRECT_URI
 */
class NotionConnector extends BaseConnector
{
    private const API_BASE = 'https://api.notion.com';

    /** Notion-Version header pinned to a known-good revision. */
    private const NOTION_API_VERSION = '2022-06-28';

    public function key(): string
    {
        return 'notion';
    }

    public function displayName(): string
    {
        return 'Notion';
    }

    public function iconUrl(): string
    {
        return asset('connectors/notion.svg');
    }

    /**
     * Notion OAuth2 uses workspace-level consent without explicit
     * scope strings — the operator chooses which pages / databases
     * the integration may access INSIDE the Notion UI during install.
     * Returning an empty list keeps the framework's
     * "permissions: ..." dialog honest (no fictitious scopes).
     */
    public function oauthScopes(): array
    {
        return [];
    }

    public function initiateOAuth(int $installationId): string
    {
        $provider = $this->providerConfig();
        $state = $this->issueOAuthState($installationId);

        $params = http_build_query([
            'client_id' => $provider['client_id'] ?? '',
            'redirect_uri' => $provider['redirect_uri'] ?? '',
            'response_type' => 'code',
            'owner' => 'user',
            'state' => $state,
        ]);

        return ($provider['oauth_authorize_url'] ?? 'https://api.notion.com/v1/oauth/authorize')
            .'?'.$params;
    }

    public function handleOAuthCallback(int $installationId, Request $request): void
    {
        $code = $request->input('code');
        $state = $request->input('state');

        if (! is_string($code) || $code === '') {
            throw new ConnectorAuthException('Notion OAuth callback missing `code` parameter.');
        }
        if (! is_string($state) || ! $this->consumeOAuthState($installationId, $state)) {
            throw new ConnectorAuthException('Notion OAuth callback state token invalid or expired.');
        }

        $provider = $this->providerConfig();

        // Notion's token endpoint authenticates with HTTP Basic Auth
        // using client_id:client_secret — see
        // https://developers.notion.com/docs/authorization
        $response = Http::withBasicAuth(
            (string) ($provider['client_id'] ?? ''),
            (string) ($provider['client_secret'] ?? ''),
        )
            ->acceptJson()
            ->asJson()
            ->post($provider['oauth_token_url'] ?? 'https://api.notion.com/v1/oauth/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $provider['redirect_uri'] ?? '',
            ]);

        if (! $response->successful()) {
            throw new ConnectorAuthException(sprintf(
                'Notion OAuth token exchange failed: HTTP %d %s',
                $response->status(),
                Str::limit((string) $response->body(), 200),
            ));
        }

        $payload = $response->json();
        if (! is_array($payload) || ! isset($payload['access_token'])) {
            throw new ConnectorAuthException('Notion OAuth token exchange returned no access_token.');
        }

        // Notion access tokens DO NOT expire — leave expires_at NULL
        // so `getAccessToken()` never considers them stale. The
        // operator can still revoke at the Notion workspace level
        // (no programmatic revoke endpoint exists).
        $this->vault->setCredentials(
            $installationId,
            accessToken: (string) $payload['access_token'],
            refreshToken: null,
            expiresAt: null,
            extra: [
                'bot_id' => $payload['bot_id'] ?? null,
                'workspace_id' => $payload['workspace_id'] ?? null,
                'workspace_name' => $payload['workspace_name'] ?? null,
                'workspace_icon' => $payload['workspace_icon'] ?? null,
                'token_type' => $payload['token_type'] ?? 'bearer',
            ],
        );

        $this->emitAudit('installed', installationId: $installationId, metadata: [
            'workspace_id' => $payload['workspace_id'] ?? null,
            'workspace_name' => $payload['workspace_name'] ?? null,
        ]);
    }

    public function syncFull(int $installationId): SyncResult
    {
        $accessToken = $this->vault->getAccessToken($installationId);
        if ($accessToken === null) {
            throw new ConnectorAuthException('No valid Notion access token; reinstall the connector.');
        }

        $installation = $this->loadInstallation($installationId);
        $config = (array) ($installation->config_json ?? []);
        $projectKey = (string) ($config['project_key'] ?? ('connector-'.$this->key()));

        $workspaceId = (string) ($this->vault->getExtraKey($installationId, 'workspace_id') ?? 'workspace');

        $added = 0;
        $errors = [];

        // Walk every page in the workspace via the search endpoint.
        // Notion's `next_cursor` pagination is abstracted by the
        // dedicated NotionPaginator helper.
        $paginator = new NotionPaginator;
        $pages = $paginator->walk(function (?string $cursor) use ($accessToken) {
            $body = [
                'filter' => ['property' => 'object', 'value' => 'page'],
                'page_size' => 100,
            ];
            if ($cursor !== null) {
                $body['start_cursor'] = $cursor;
            }

            return $this->notionPost('/v1/search', $accessToken, $body);
        });

        foreach ($pages as $page) {
            try {
                $this->ingestPage($installation, $projectKey, $accessToken, $page, $workspaceId);
                $added++;
            } catch (\Throwable $e) {
                $pageId = (string) ($page['id'] ?? '?');
                $errors[] = sprintf('page %s: %s', $pageId, $e->getMessage());
                Log::error('NotionConnector::syncFull failed for page', [
                    'installation_id' => $installationId,
                    'page_id' => $pageId,
                    'exception' => $e->getMessage(),
                ]);
            }
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

        // Persist the high-water mark so the next incremental run
        // can skip already-seen pages.
        $this->vault->setExtraKey(
            $installationId,
            'last_full_sync_at',
            Carbon::now()->toIso8601String(),
        );

        return $result;
    }

    public function syncIncremental(int $installationId, ?Carbon $since): SyncResult
    {
        $accessToken = $this->vault->getAccessToken($installationId);
        if ($accessToken === null) {
            throw new ConnectorAuthException('No valid Notion access token; reinstall the connector.');
        }

        if ($since === null) {
            // Notion has no delta cursor; first incremental run falls
            // back to a full sync just like Google Drive does on its
            // first incremental run.
            return $this->syncFull($installationId);
        }

        $installation = $this->loadInstallation($installationId);
        $config = (array) ($installation->config_json ?? []);
        $projectKey = (string) ($config['project_key'] ?? ('connector-'.$this->key()));

        $workspaceId = (string) ($this->vault->getExtraKey($installationId, 'workspace_id') ?? 'workspace');

        $updated = 0;
        $removed = 0;
        $errors = [];

        // Search results are sorted desc by last_edited_time so we
        // can break out of pagination as soon as we hit a page older
        // than `$since`. Notion doesn't expose a server-side time
        // filter on search; client-side filter is the supported path.
        $paginator = new NotionPaginator;
        $shouldStop = false;

        $pages = $paginator->walk(function (?string $cursor) use ($accessToken, &$shouldStop) {
            if ($shouldStop) {
                // The previous page already had entries older than
                // $since; force the paginator to return an empty page
                // by passing an unsatisfiable cursor — but the
                // paginator's `has_more=false` short-circuit is the
                // cleaner exit. Achieve that by stopping inside this
                // closure: throw a sentinel and catch outside.
            }

            $body = [
                'filter' => ['property' => 'object', 'value' => 'page'],
                'sort' => ['timestamp' => 'last_edited_time', 'direction' => 'descending'],
                'page_size' => 100,
            ];
            if ($cursor !== null) {
                $body['start_cursor'] = $cursor;
            }

            return $this->notionPost('/v1/search', $accessToken, $body);
        });

        foreach ($pages as $page) {
            $lastEdited = $page['last_edited_time'] ?? null;
            if (is_string($lastEdited)) {
                try {
                    $editedAt = Carbon::parse($lastEdited);
                } catch (\Throwable) {
                    $editedAt = null;
                }
                if ($editedAt !== null && $editedAt->lessThanOrEqualTo($since)) {
                    // Older than the watermark — skip (sort:desc means
                    // every following page is also older, but the
                    // paginator already loaded the batch so we just
                    // skip remaining entries here for simplicity).
                    continue;
                }
            }

            // Archive reconciliation — Notion deletions arrive as a
            // page-with-`archived: true` flag in the search results.
            // Route through the shared softDeleteByMetadataKey helper
            // so the `knowledge_documents` row is actually deleted
            // (W1 iter2 finding #7 lesson).
            if (($page['archived'] ?? false) === true) {
                $pageId = (string) ($page['id'] ?? '');
                if ($pageId !== '' && $this->softDeleteByMetadataKey($installation, 'notion_page_id', $pageId)) {
                    $removed++;
                }
                continue;
            }

            try {
                $this->ingestPage($installation, $projectKey, $accessToken, $page, $workspaceId);
                $updated++;
            } catch (\Throwable $e) {
                $pageId = (string) ($page['id'] ?? '?');
                $errors[] = sprintf('page %s: %s', $pageId, $e->getMessage());
            }
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

    /**
     * Notion has no programmatic revoke endpoint as of API v2022-06-28
     * — disconnect therefore just clears local credentials. Operators
     * wanting full revocation must delete the integration from their
     * Notion workspace settings (Settings → Connections → Disconnect).
     */
    public function disconnect(int $installationId): void
    {
        $this->vault->clearCredentials($installationId);
        $this->emitAudit('disconnected', installationId: $installationId);
    }

    public function health(int $installationId): HealthStatus
    {
        $accessToken = $this->vault->getAccessToken($installationId);
        if ($accessToken === null) {
            return HealthStatus::errored('No valid access token (credentials missing).');
        }

        try {
            $response = Http::withToken($accessToken)
                ->withHeaders(['Notion-Version' => self::NOTION_API_VERSION])
                ->timeout(5)
                ->get(self::API_BASE.'/v1/users/me');
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
     * Fetch a Notion page's block tree (recursively hydrating
     * `has_children`), convert to markdown via
     * {@see NotionBlockToMarkdown}, write to the KB disk via the
     * shared `resolveKbSourcePath()` helper, and dispatch the
     * canonical ingest job.
     *
     * @param  array<string,mixed>  $page
     */
    private function ingestPage(
        \App\Models\ConnectorInstallation $installation,
        string $projectKey,
        string $accessToken,
        array $page,
        string $workspaceId,
    ): void {
        $pageId = (string) ($page['id'] ?? '');
        if ($pageId === '') {
            throw new \RuntimeException('Notion page missing id.');
        }

        $title = $this->extractPageTitle($page);
        $blocks = $this->fetchBlockTree($accessToken, $pageId);

        $converter = new NotionBlockToMarkdown;
        $markdown = $converter->render($blocks);

        // R26 — PII redaction at the ingest boundary BEFORE the
        // bytes hit the KB disk.
        $markdown = $this->maybeRedactContent($markdown);

        // Prepend a top-level title heading so the ingest pipeline
        // always indexes the Notion page title — Notion's "page name"
        // is metadata on the page object, NOT a heading block, so
        // without this prepend the markdown body would never carry it.
        if ($title !== '') {
            $markdown = "# {$title}\n\n{$markdown}";
        }

        $cleanWorkspaceId = $workspaceId !== '' ? Str::slug($workspaceId) : 'workspace';
        $cleanPageId = preg_replace('/[^a-z0-9\-]/i', '', $pageId) ?? $pageId;
        $relativePath = sprintf(
            '%s/connectors/notion/%s/%s.md',
            $projectKey,
            $cleanWorkspaceId,
            $cleanPageId,
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
            title: $title !== '' ? $title : 'Notion page',
            metadata: [
                'connector' => $this->key(),
                'installation_id' => $installation->id,
                'notion_page_id' => $pageId,
                'notion_workspace_id' => $workspaceId,
                'last_edited_time' => $page['last_edited_time'] ?? null,
                'created_time' => $page['created_time'] ?? null,
                'archived' => (bool) ($page['archived'] ?? false),
            ],
            mimeType: 'text/markdown',
            tenantId: $installation->tenant_id,
        );
    }

    /**
     * Pull `/v1/blocks/{id}/children` recursively. Notion limits each
     * call to 100 children, paginated via `next_cursor` — the shared
     * paginator handles the loop.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchBlockTree(string $accessToken, string $blockId, int $depth = 0): array
    {
        // Cap recursion at 5 levels — deeper trees indicate a
        // pathological Notion page; the renderer flattens them but
        // we don't pay for them.
        if ($depth >= 5) {
            return [];
        }

        $paginator = new NotionPaginator;
        $blocks = $paginator->walk(function (?string $cursor) use ($accessToken, $blockId) {
            $params = ['page_size' => 100];
            if ($cursor !== null) {
                $params['start_cursor'] = $cursor;
            }

            return $this->notionGet('/v1/blocks/'.urlencode($blockId).'/children', $accessToken, $params);
        });

        foreach ($blocks as &$block) {
            if (! is_array($block)) {
                continue;
            }
            $hasChildren = (bool) ($block['has_children'] ?? false);
            $childId = (string) ($block['id'] ?? '');
            if ($hasChildren && $childId !== '') {
                $block['children'] = $this->fetchBlockTree($accessToken, $childId, $depth + 1);
            }
        }
        unset($block);

        return $blocks;
    }

    /**
     * @param  array<string,mixed>  $page
     */
    private function extractPageTitle(array $page): string
    {
        $properties = $page['properties'] ?? [];
        if (! is_array($properties)) {
            return '';
        }

        foreach ($properties as $property) {
            if (! is_array($property)) {
                continue;
            }
            if (($property['type'] ?? '') !== 'title') {
                continue;
            }
            $segments = $property['title'] ?? [];
            if (! is_array($segments)) {
                continue;
            }
            $title = '';
            foreach ($segments as $segment) {
                if (is_array($segment)) {
                    $title .= (string) ($segment['plain_text'] ?? '');
                }
            }

            return trim($title);
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $body
     */
    private function notionPost(string $path, string $accessToken, array $body): Response
    {
        return Http::withToken($accessToken)
            ->withHeaders([
                'Notion-Version' => self::NOTION_API_VERSION,
                'Content-Type' => 'application/json',
            ])
            ->post(self::API_BASE.$path, $body);
    }

    /**
     * @param  array<string,mixed>  $params
     */
    private function notionGet(string $path, string $accessToken, array $params = []): Response
    {
        return Http::withToken($accessToken)
            ->withHeaders(['Notion-Version' => self::NOTION_API_VERSION])
            ->get(self::API_BASE.$path, $params);
    }

    /**
     * @return array<string,mixed>
     */
    private function providerConfig(): array
    {
        return (array) config('connectors.providers.notion', []);
    }
}
