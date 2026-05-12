<?php

declare(strict_types=1);

namespace App\Connectors\BuiltIn;

use App\Connectors\BaseConnector;
use App\Connectors\BuiltIn\Jira\JiraAdfToMarkdown;
use App\Connectors\BuiltIn\Jira\JiraPaginator;
use App\Connectors\BuiltIn\Jira\JqlBuilder;
use App\Connectors\Exceptions\ConnectorApiException;
use App\Connectors\Exceptions\ConnectorAuthException;
use App\Connectors\Exceptions\ConnectorPaginationLimitException;
use App\Connectors\HealthStatus;
use App\Connectors\Support\SourceAwareMetadataBuilder;
use App\Connectors\Support\VendorMimeSelector;
use App\Jobs\IngestDocumentJob;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * v4.5/W6 — Jira Cloud connector (built-in).
 *
 * Surfaces Jira Cloud issues — description body, comments, structured
 * fields — via the Atlassian REST API v3. Shares the Atlassian OAuth
 * 2.0 3LO surface with {@see ConfluenceConnector}, so a single
 * Atlassian workspace can install both connectors against the same
 * `cloud_id`.
 *
 * **OAuth surface**: Atlassian OAuth 2.0 3LO at `auth.atlassian.com`.
 * Scopes: `read:jira-work`, `read:jira-user`, `offline_access`. The
 * `accessible-resources` endpoint resolves the per-tenant `cloud_id`
 * (picking the first Jira-capable resource, distinguished by a
 * `read:jira-*` scope), persisted in `extra_json.cloud_id`. Same
 * cloud_id can legitimately appear in both a ConfluenceConnector and
 * a JiraConnector installation for the same workspace — there's no
 * UNIQUE constraint on `extra_json.cloud_id` and we don't add one.
 *
 * **Revocation**: unlike Confluence (no programmatic revoke),
 * Atlassian DOES expose `auth.atlassian.com/oauth/token/revoke` for
 * OAuth 2.0 3LO tokens. {@see disconnect()} calls it best-effort then
 * clears local credentials regardless of the revoke response — a
 * failed remote revoke must not leave the local row stuck (the
 * operator can finish revocation from id.atlassian.com).
 *
 * **Sync semantics**:
 *   - Full sync — walk every accessible project via
 *     `/rest/api/3/project/search`, then walk every issue per project
 *     via JQL `project = "{key}" ORDER BY updated DESC`. Each issue is
 *     fetched with `expand=renderedFields,changelog,comments` and
 *     `fields=*all,-attachment`.
 *   - Incremental sync — workspace-wide JQL
 *     `updated >= "YYYY-MM-DD HH:mm" ORDER BY updated DESC`, paginated.
 *     JQL's date format is Jira-specific (NOT ISO-8601); the connector
 *     formats Carbon instances into the wire shape via
 *     {@see JqlBuilder::updatedSince()}.
 *
 * **Deletion reconciliation**: Jira hard-deletes are NOT visible via
 * the standard search endpoints — they vanish silently. We rely on
 * the periodic full sync to detect orphaned `knowledge_documents`
 * (rows whose `metadata.jira_issue_key` no longer round-trips through
 * the Jira API) and soft-delete them. Real-time deletion via webhook
 * is out of scope for v4.5/W6.
 *
 * Required env (config/connectors.php::providers.jira):
 *   - CONNECTOR_JIRA_CLIENT_ID
 *   - CONNECTOR_JIRA_CLIENT_SECRET
 *   - CONNECTOR_JIRA_REDIRECT_URI
 */
class JiraConnector extends BaseConnector
{
    /**
     * Statuses that we treat as "inactive" for the
     * `_derived.status_active` reranker signal. The set mirrors the
     * common Jira workflow's terminal column names; comparison is
     * case-insensitive so tenants with custom workflows ("Cancelled",
     * "Won't Do") still benefit.
     */
    private const INACTIVE_STATUSES = ['Done', 'Closed', 'Resolved', 'Cancelled', "Won't Do"];

    public function key(): string
    {
        return 'jira';
    }

    public function displayName(): string
    {
        return 'Jira';
    }

    public function oauthScopes(): array
    {
        return [
            'read:jira-work',
            'read:jira-user',
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
            throw new ConnectorAuthException('Jira OAuth callback missing `code` parameter.');
        }
        if (! is_string($state) || ! $this->consumeOAuthState($installationId, $state)) {
            throw new ConnectorAuthException('Jira OAuth callback state token invalid or expired.');
        }

        $provider = $this->providerConfig();

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
                'Jira OAuth token exchange failed: HTTP %d %s',
                $response->status(),
                Str::limit((string) $response->body(), 200),
            ));
        }

        $payload = $response->json();
        if (! is_array($payload) || ! isset($payload['access_token'])) {
            throw new ConnectorAuthException('Jira OAuth token exchange returned no access_token.');
        }

        $accessToken = (string) $payload['access_token'];
        $expiresAt = isset($payload['expires_in']) && is_numeric($payload['expires_in'])
            ? Carbon::now()->addSeconds((int) $payload['expires_in'])
            : null;

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
                'Jira OAuth refresh failed: HTTP %d %s',
                $response->status(),
                Str::limit((string) $response->body(), 200),
            ));
        }

        $payload = $response->json();
        if (! is_array($payload) || ! isset($payload['access_token'])) {
            throw new ConnectorAuthException('Jira OAuth refresh returned no access_token.');
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

    public function syncFull(int $installationId): \App\Connectors\SyncResult
    {
        $accessToken = $this->refreshTokenIfExpired($installationId);
        if ($accessToken === null) {
            throw new ConnectorAuthException('No valid Jira access token; reinstall the connector.');
        }

        $cloudId = (string) ($this->vault->getExtraKey($installationId, 'cloud_id') ?? '');
        if ($cloudId === '') {
            throw new ConnectorAuthException('Jira cloud_id missing; reinstall the connector.');
        }

        $installation = $this->loadInstallation($installationId);
        $config = (array) ($installation->config_json ?? []);
        $projectKey = (string) ($config['project_key'] ?? ('connector-'.$this->key()));

        $added = 0;
        $errors = [];

        try {
            foreach ($this->iterateProjects($accessToken, $cloudId) as $project) {
                $jiraProjectKey = (string) ($project['key'] ?? '');
                if ($jiraProjectKey === '') {
                    continue;
                }

                try {
                    foreach ($this->iterateIssuesForProject($accessToken, $cloudId, $jiraProjectKey) as $issue) {
                        try {
                            $this->ingestIssue($installation, $projectKey, $accessToken, $cloudId, $issue, $project);
                            $added++;
                        } catch (\Throwable $e) {
                            $errors[] = sprintf(
                                'issue %s in project %s: %s',
                                $issue['key'] ?? '?',
                                $jiraProjectKey,
                                $e->getMessage(),
                            );
                        }
                    }
                } catch (ConnectorPaginationLimitException $e) {
                    $errors[] = sprintf(
                        'project %s: issues truncated at maxPages=%d',
                        $jiraProjectKey,
                        $e->maxPages,
                    );
                } catch (ConnectorApiException $e) {
                    $errors[] = sprintf('project %s: %s', $jiraProjectKey, $e->getMessage());
                }
            }
        } catch (ConnectorPaginationLimitException $e) {
            $errors[] = sprintf('projects truncated at maxPages=%d', $e->maxPages);
        } catch (ConnectorApiException $e) {
            $errors[] = $e->getMessage();
        }

        $result = new \App\Connectors\SyncResult(
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
        $this->vault->setExtraKey(
            $installationId,
            'last_synced_at',
            Carbon::now()->toIso8601String(),
        );

        return $result;
    }

    public function syncIncremental(int $installationId, ?Carbon $since): \App\Connectors\SyncResult
    {
        $accessToken = $this->refreshTokenIfExpired($installationId);
        if ($accessToken === null) {
            throw new ConnectorAuthException('No valid Jira access token; reinstall the connector.');
        }

        if ($since === null) {
            return $this->syncFull($installationId);
        }

        $cloudId = (string) ($this->vault->getExtraKey($installationId, 'cloud_id') ?? '');
        if ($cloudId === '') {
            throw new ConnectorAuthException('Jira cloud_id missing; reinstall the connector.');
        }

        $installation = $this->loadInstallation($installationId);
        $config = (array) ($installation->config_json ?? []);
        $projectKey = (string) ($config['project_key'] ?? ('connector-'.$this->key()));

        $updated = 0;
        $errors = [];

        $jql = JqlBuilder::any()
            ->updatedSince($since)
            ->orderBy('updated', 'DESC')
            ->build();

        try {
            foreach ($this->iterateIssuesByJql($accessToken, $cloudId, $jql) as $issue) {
                try {
                    $this->ingestIssue($installation, $projectKey, $accessToken, $cloudId, $issue, null);
                    $updated++;
                } catch (\Throwable $e) {
                    $errors[] = sprintf('issue %s: %s', $issue['key'] ?? '?', $e->getMessage());
                }
            }
        } catch (ConnectorPaginationLimitException $e) {
            $errors[] = sprintf(
                'incremental sync truncated at maxPages=%d; raise the cap or trigger another sync.',
                $e->maxPages,
            );
            Log::warning('JiraConnector::syncIncremental truncated by pagination cap', [
                'installation_id' => $installationId,
                'max_pages' => $e->maxPages,
                'documents_processed_before_cap' => $updated,
            ]);
        } catch (ConnectorApiException $e) {
            $errors[] = $e->getMessage();
        }

        $result = new \App\Connectors\SyncResult(
            documentsAdded: 0,
            documentsUpdated: $updated,
            documentsRemoved: 0,
            errors: $errors,
            completedAt: Carbon::now(),
        );

        $this->emitAudit('sync_completed', installationId: $installationId, metadata: array_merge(
            $result->toArray(),
            ['mode' => 'incremental', 'since' => $since->toIso8601String()],
        ));

        $this->vault->setExtraKey(
            $installationId,
            'last_synced_at',
            Carbon::now()->toIso8601String(),
        );

        return $result;
    }

    public function disconnect(int $installationId): void
    {
        // Atlassian DOES expose a revoke endpoint for OAuth 2.0 3LO.
        // Revoke BOTH access AND refresh tokens (Copilot iter1
        // finding #1) — revoking only the short-lived access token
        // would leave the refresh token valid upstream so a
        // mis-restored credential row could re-authenticate against
        // the user's workspace. Best-effort: any failure must not
        // block local cleanup (operators must always be able to
        // disconnect regardless of upstream availability).
        $access = $this->vault->getAccessToken($installationId);
        $refresh = $this->vault->getRefreshToken($installationId);
        $provider = $this->providerConfig();
        $revokeUrl = (string) ($provider['oauth_revoke_url'] ?? 'https://auth.atlassian.com/oauth/token/revoke');

        foreach (
            [
                ['token' => $access, 'hint' => 'access_token'],
                ['token' => $refresh, 'hint' => 'refresh_token'],
            ] as $candidate
        ) {
            if ($candidate['token'] === null) {
                continue;
            }
            try {
                Http::asJson()
                    ->acceptJson()
                    ->timeout(5)
                    ->post($revokeUrl, [
                        'client_id' => $provider['client_id'] ?? '',
                        'client_secret' => $provider['client_secret'] ?? '',
                        'token' => $candidate['token'],
                        'token_type_hint' => $candidate['hint'],
                    ]);
            } catch (\Throwable $e) {
                Log::warning('JiraConnector::disconnect token revoke failed (continuing with local cleanup)', [
                    'installation_id' => $installationId,
                    'token_type' => $candidate['hint'],
                    'error' => $e->getMessage(),
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

        $cloudId = (string) ($this->vault->getExtraKey($installationId, 'cloud_id') ?? '');
        if ($cloudId === '') {
            return HealthStatus::errored('cloud_id missing — reinstall the connector.');
        }

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->timeout(5)
                ->get($this->apiBase($cloudId).'/myself');
        } catch (\Throwable $e) {
            return HealthStatus::errored("Network error: {$e->getMessage()}");
        }

        if ($response->successful()) {
            return HealthStatus::healthy();
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return HealthStatus::errored("Authorization rejected (HTTP {$response->status()}).");
        }

        return HealthStatus::degraded("myself returned HTTP {$response->status()}");
    }

    /**
     * Walk `/rest/api/3/project/search` for every accessible project.
     *
     * @return \Generator<int, array<string,mixed>>
     */
    private function iterateProjects(string $accessToken, string $cloudId): \Generator
    {
        $paginator = new JiraPaginator(resultsKey: 'values', mode: JiraPaginator::MODE_OFFSET);
        $base = $this->apiBase($cloudId).'/project/search';
        $maxResults = 50;

        $fetch = function (?string $cursor) use ($accessToken, $base, $maxResults): Response {
            $startAt = is_string($cursor) && $cursor !== '' ? (int) $cursor : 0;

            return Http::withToken($accessToken)
                ->acceptJson()
                ->get($base, [
                    'startAt' => $startAt,
                    'maxResults' => $maxResults,
                ]);
        };

        foreach ($paginator->walkLazy($fetch) as $batch) {
            foreach ($batch as $project) {
                yield $project;
            }
        }
    }

    /**
     * Walk all issues for a project via JQL.
     *
     * @return \Generator<int, array<string,mixed>>
     */
    private function iterateIssuesForProject(
        string $accessToken,
        string $cloudId,
        string $jiraProjectKey,
    ): \Generator {
        $jql = JqlBuilder::for($jiraProjectKey)
            ->orderBy('updated', 'DESC')
            ->build();

        foreach ($this->iterateIssuesByJql($accessToken, $cloudId, $jql) as $issue) {
            yield $issue;
        }
    }

    /**
     * Walk all issues matching the given JQL. Uses the classic
     * `/rest/api/3/search` endpoint (offset pagination) which remains
     * supported on Jira Cloud.
     *
     * @return \Generator<int, array<string,mixed>>
     */
    private function iterateIssuesByJql(string $accessToken, string $cloudId, string $jql): \Generator
    {
        $paginator = new JiraPaginator(resultsKey: 'issues', mode: JiraPaginator::MODE_OFFSET);
        $base = $this->apiBase($cloudId).'/search';
        $maxResults = 50;

        $fetch = function (?string $cursor) use ($accessToken, $base, $jql, $maxResults): Response {
            $startAt = is_string($cursor) && $cursor !== '' ? (int) $cursor : 0;

            // Copilot iter1 finding #2: drop `changelog` from
            // `expand`. The connector doesn't consume changelog
            // data; pulling it inflates response payloads
            // significantly (one entry per status transition in
            // every issue history) and slows syncs against high-
            // traffic projects. `renderedFields` stays — comments
            // and description carry the ADF body the chunker
            // needs.
            return Http::withToken($accessToken)
                ->acceptJson()
                ->get($base, [
                    'jql' => $jql,
                    'startAt' => $startAt,
                    'maxResults' => $maxResults,
                    'expand' => 'renderedFields',
                    'fields' => '*all,-attachment',
                ]);
        };

        foreach ($paginator->walkLazy($fetch) as $batch) {
            foreach ($batch as $issue) {
                yield $issue;
            }
        }
    }

    /**
     * Ingest one issue — render ADF body + comments to markdown, build
     * the rich frontmatter envelope, dispatch IngestDocumentJob.
     *
     * @param  array<string,mixed>       $issue
     * @param  array<string,mixed>|null  $project  Pre-fetched project
     *                                             row from `iterateProjects`
     *                                             — null when called via
     *                                             incremental sync (we
     *                                             read project info from
     *                                             the issue's `fields.project`
     *                                             instead).
     */
    private function ingestIssue(
        \App\Models\ConnectorInstallation $installation,
        string $projectKey,
        string $accessToken,
        string $cloudId,
        array $issue,
        ?array $project,
    ): void {
        $issueKey = (string) ($issue['key'] ?? '');
        if ($issueKey === '') {
            throw new \RuntimeException('Jira issue missing key.');
        }

        $fields = (array) ($issue['fields'] ?? []);
        $issueProject = is_array($fields['project'] ?? null) ? $fields['project'] : ($project ?? []);
        $jiraProjectKey = (string) ($issueProject['key'] ?? '');
        $jiraProjectName = (string) ($issueProject['name'] ?? '');

        $summary = (string) ($fields['summary'] ?? '');
        $title = $summary !== '' ? "[{$issueKey}] {$summary}" : $issueKey;

        // ADF -> markdown for description + comments.
        $converter = new JiraAdfToMarkdown();
        $descriptionMd = $converter->convert($fields['description'] ?? null);

        // Comments live under `fields.comment.comments` when the issue
        // was fetched with `expand=renderedFields,changelog` (Jira
        // returns the comment field by default for `*all`).
        $commentMd = $this->renderCommentsSection($converter, $fields['comment']['comments'] ?? []);

        $markdown = $descriptionMd;
        if ($commentMd !== '') {
            $markdown = ($markdown === '' ? '' : $markdown."\n\n").$commentMd;
        }

        if ($markdown === '') {
            // No description AND no comments — emit a tiny stub so the
            // structured fields still surface in retrieval. The
            // preamble chunk built by JiraIssueChunker carries the
            // status / priority / assignee for property-based queries.
            $markdown = '_No description._';
        }

        $markdown = $this->maybeRedactContent($markdown);
        $markdown = "# {$title}\n\n".$markdown;

        $cleanProjectKey = $jiraProjectKey !== ''
            ? Str::slug($jiraProjectKey)
            : 'project';
        $cleanIssueKey = preg_replace('/[^A-Za-z0-9\-]/', '-', $issueKey) ?? $issueKey;
        $relativePath = sprintf(
            '%s/connectors/jira/%s/%s.md',
            $projectKey,
            $cleanProjectKey,
            strtolower($cleanIssueKey),
        );

        $paths = $this->resolveKbSourcePath($relativePath);

        $written = Storage::disk($paths['disk'])->put($paths['absolute'], $markdown);
        if ($written === false) {
            throw new \RuntimeException("Failed to write {$paths['absolute']} to KB disk [{$paths['disk']}].");
        }

        $issueType = (string) ($fields['issuetype']['name'] ?? '');
        $status = (string) ($fields['status']['name'] ?? '');
        $priority = (string) ($fields['priority']['name'] ?? '');
        $assignee = $this->emailFromUserField($fields['assignee'] ?? null);
        $reporter = $this->emailFromUserField($fields['reporter'] ?? null);
        $labels = $this->normalizeStringList($fields['labels'] ?? []);
        $components = $this->extractNamedList($fields['components'] ?? []);
        $fixVersions = $this->extractNamedList($fields['fixVersions'] ?? []);
        $created = $fields['created'] ?? null;
        $updated = $fields['updated'] ?? null;
        $sprint = $this->extractActiveSprint($fields);
        $statusActive = $this->isStatusActive($status);

        $issueUrl = $this->browseUrl($issueProject, $issueKey, $cloudId);

        $jiraFields = [
            'project_key'    => $jiraProjectKey,
            'project_name'   => $jiraProjectName,
            'issue_key'      => $issueKey,
            'issue_id'       => (string) ($issue['id'] ?? ''),
            'issue_type'     => $issueType,
            'status'         => $status,
            'priority'       => $priority,
            'assignee'       => $assignee,
            'reporter'       => $reporter,
            'labels'         => $labels,
            'components'     => $components,
            'fix_versions'   => $fixVersions,
            'created'        => $created,
            'updated'        => $updated,
            'sprint'         => $sprint,
            'cloud_id'       => $cloudId,
            'source_url'     => $issueUrl,
        ];

        $searchTags = array_values(array_unique(array_merge(
            $labels,
            $components,
            array_filter([
                $priority !== '' ? $priority : null,
                $issueType !== '' ? $issueType : null,
            ]),
        )));

        $sourceMeta = (new SourceAwareMetadataBuilder())->build(
            base: [
                'connector' => $this->key(),
                'installation_id' => $installation->id,
                'jira_issue_key' => $issueKey,
                'jira_project_key' => $jiraProjectKey,
                'jira_cloud_id' => $cloudId,
                'jira_status' => $status,
                'jira_updated' => $updated,
                'source' => 'jira',
                'source_id' => $issueKey,
                'source_url' => $issueUrl,
            ],
            sourceKey: 'jira',
            sourceFields: $jiraFields,
            tags: $searchTags,
            statusActive: $statusActive,
            lastModified: $updated,
            owner: $assignee,
        );

        IngestDocumentJob::dispatch(
            projectKey: $projectKey,
            relativePath: $paths['relative'],
            disk: $paths['disk'],
            title: $title,
            metadata: $sourceMeta,
            mimeType: VendorMimeSelector::MIME_JIRA_ISSUE,
            tenantId: $installation->tenant_id,
        );
    }

    /**
     * Render the optional `## Comments` appendix. Comments arrive in
     * the order Jira stores them (created ascending, oldest first).
     * The body of each comment is ADF; the author + date appear in the
     * sub-heading so the chunker can keep them attached.
     *
     * @param  mixed  $comments
     */
    private function renderCommentsSection(JiraAdfToMarkdown $converter, $comments): string
    {
        if (! is_array($comments) || $comments === []) {
            return '';
        }

        $blocks = [];
        foreach ($comments as $comment) {
            if (! is_array($comment)) {
                continue;
            }
            $author = $this->displayNameFromUserField($comment['author'] ?? null);
            $created = (string) ($comment['created'] ?? '');
            $body = $converter->convert($comment['body'] ?? null);
            if (trim($body) === '') {
                continue;
            }

            $heading = sprintf(
                '### %s — %s',
                $author === '' ? 'Unknown author' : $author,
                $created,
            );
            $blocks[] = $heading."\n\n".$body;
        }

        if ($blocks === []) {
            return '';
        }

        return "## Comments\n\n".implode("\n\n", $blocks);
    }

    /**
     * @param  mixed  $userField
     */
    private function emailFromUserField($userField): ?string
    {
        if (! is_array($userField)) {
            return null;
        }
        $email = $userField['emailAddress'] ?? null;
        if (is_string($email) && $email !== '') {
            return $email;
        }

        // Fall back to displayName + accountId when email is not
        // exposed (Jira hides email by default in GDPR strict mode).
        $name = $userField['displayName'] ?? null;
        if (is_string($name) && $name !== '') {
            return $name;
        }

        return null;
    }

    /**
     * @param  mixed  $userField
     */
    private function displayNameFromUserField($userField): string
    {
        if (! is_array($userField)) {
            return '';
        }
        $name = $userField['displayName'] ?? $userField['emailAddress'] ?? '';

        return is_string($name) ? $name : '';
    }

    /**
     * @param  mixed  $list
     * @return list<string>
     */
    private function normalizeStringList($list): array
    {
        if (! is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Jira returns components / fixVersions as
     * `[{id, name, ...}, ...]`. We keep only the names.
     *
     * @param  mixed  $list
     * @return list<string>
     */
    private function extractNamedList($list): array
    {
        if (! is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $row) {
            if (is_array($row) && isset($row['name']) && is_string($row['name']) && $row['name'] !== '') {
                $out[] = $row['name'];
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Sprint lives on a custom field whose id varies per workspace
     * (typically `customfield_10020`). We don't have a stable id so
     * we sweep every `customfield_*` value looking for a row that
     * carries `state`/`name` keys (the Sprint object shape). Returns
     * the FIRST active sprint name; null when none.
     *
     * @param  array<string,mixed>  $fields
     */
    private function extractActiveSprint(array $fields): ?string
    {
        foreach ($fields as $key => $value) {
            if (! str_starts_with((string) $key, 'customfield_')) {
                continue;
            }
            if (! is_array($value)) {
                continue;
            }
            foreach ($value as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $state = $entry['state'] ?? null;
                $name = $entry['name'] ?? null;
                if (! is_string($name) || $name === '') {
                    continue;
                }
                // Active sprint preferred; fall back to first named
                // sprint when no active one exists.
                if ($state === 'active') {
                    return $name;
                }
            }
        }

        // Second pass: return the first named sprint regardless of
        // state. Some workspaces close sprints without flipping state
        // to "closed" promptly.
        foreach ($fields as $key => $value) {
            if (! str_starts_with((string) $key, 'customfield_') || ! is_array($value)) {
                continue;
            }
            foreach ($value as $entry) {
                if (is_array($entry) && isset($entry['name']) && isset($entry['state'])
                    && is_string($entry['name']) && $entry['name'] !== ''
                ) {
                    return $entry['name'];
                }
            }
        }

        return null;
    }

    private function isStatusActive(string $status): bool
    {
        $needle = strtolower($status);
        foreach (self::INACTIVE_STATUSES as $inactive) {
            if (strtolower($inactive) === $needle) {
                return false;
            }
        }

        return $status !== '';
    }

    /**
     * Compose the browse URL for the issue. Jira returns the
     * project's `self` URL but no canonical browse URL — we
     * reconstruct it from the cloud's `<site>.atlassian.net` host.
     *
     * Falls back to the API path
     * `https://api.atlassian.com/ex/jira/{cloud_id}/browse/{issue_key}`
     * when the cloud-id-to-host lookup fails (project carries no
     * `self` URL or parse_url fails). Atlassian's API host redirects
     * `/browse/{key}` to the canonical workspace URL, so the link
     * resolves end-to-end even from the fallback path — Copilot iter2
     * finding #3, option (a). A working-but-pretty URL beats an empty
     * `source_url` in citation rendering.
     *
     * @param  array<string,mixed>  $project
     */
    private function browseUrl(array $project, string $issueKey, string $cloudId = ''): string
    {
        // Preferred path: `project.self` is
        // `https://<site>.atlassian.net/rest/api/3/project/<id>`.
        // Strip the path to reach the host root.
        $self = $project['self'] ?? null;
        if (is_string($self) && $self !== '') {
            $parts = parse_url($self);
            if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
                return sprintf('%s://%s/browse/%s', $parts['scheme'], $parts['host'], $issueKey);
            }
        }

        // Fallback: API path. Atlassian redirects this to the
        // canonical workspace URL when followed in a browser.
        if ($cloudId !== '' && $issueKey !== '') {
            return sprintf(
                'https://api.atlassian.com/ex/jira/%s/browse/%s',
                $cloudId,
                $issueKey,
            );
        }

        return '';
    }

    /**
     * Resolve the per-tenant cloud id after a successful OAuth
     * exchange.  Picks the first Jira-capable resource (scopes
     * include a `read:jira-*` entry).
     *
     * @param  array<string,mixed>  $provider
     */
    private function resolveCloudId(array $provider, string $accessToken): string
    {
        $url = $provider['accessible_resources_url']
            ?? 'https://api.atlassian.com/oauth/token/accessible-resources';

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->get((string) $url);
        } catch (\Throwable $e) {
            throw new ConnectorAuthException(
                'Jira accessible-resources lookup failed: '.$e->getMessage(),
            );
        }

        if (! $response->successful()) {
            throw new ConnectorAuthException(sprintf(
                'Jira accessible-resources lookup failed: HTTP %d %s',
                $response->status(),
                Str::limit((string) $response->body(), 200),
            ));
        }

        $resources = $response->json();
        if (! is_array($resources) || $resources === []) {
            throw new ConnectorAuthException(
                'Jira accessible-resources returned no resources — user has not granted access to any Atlassian site.',
            );
        }

        foreach ($resources as $resource) {
            if (! is_array($resource)) {
                continue;
            }
            $scopes = $resource['scopes'] ?? [];
            if (! is_array($scopes)) {
                continue;
            }
            foreach ($scopes as $scope) {
                if (is_string($scope) && str_starts_with($scope, 'read:jira')) {
                    $cloudId = $resource['id'] ?? null;
                    if (! is_string($cloudId) || trim($cloudId) === '') {
                        throw new ConnectorAuthException(
                            'Jira accessible-resources returned a Jira-capable site with a missing id.',
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
                    'Jira accessible-resources returned a site with a missing id.',
                );
            }

            return $cloudId;
        }

        throw new ConnectorAuthException(
            'Jira accessible-resources returned no Jira-capable site.',
        );
    }

    private function apiBase(string $cloudId): string
    {
        $template = (string) ($this->providerConfig()['api_base_template']
            ?? 'https://api.atlassian.com/ex/jira/{cloud_id}/rest/api/3');

        return str_replace('{cloud_id}', $cloudId, $template);
    }

    /**
     * @return array<string,mixed>
     */
    private function providerConfig(): array
    {
        return (array) config('connectors.providers.jira', []);
    }
}
