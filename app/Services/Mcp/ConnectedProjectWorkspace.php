<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Models\AdminCommandAudit;
use App\Models\KnowledgeDocument;
use App\Models\McpProjectConnection;
use App\Models\OAuthAccessToken;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Auth\OAuthAccessPolicy;
use App\Services\Kb\KbSearchService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

final class ConnectedProjectWorkspace
{
    public const TOOL_SCOPES = [
        'kb-connected-project-tool' => 'mcp:read',
        'kb-connected-search-tool' => 'mcp:read',
        'kb-connected-document-tool' => 'mcp:read',
        'kb-ingest-documents-tool' => 'mcp:ingest',
        'kb-import-status-tool' => 'mcp:read',
    ];

    public function connection(): McpProjectConnection
    {
        $connection = request()->attributes->get('mcp.connection');
        $grant = request()->attributes->get('mcp.grant');
        abort_unless($connection instanceof McpProjectConnection && $grant instanceof OAuthAccessToken, 403);
        $this->authorize($connection, $grant);
        return $connection;
    }

    public function authorize(McpProjectConnection $connection, OAuthAccessToken $grant, bool $write = false): User
    {
        $user = User::find($grant->user_id);
        abort_unless($user && Auth::id() === $user->id && $connection->id === $grant->mcp_connection_id
            && (int) $connection->user_id === (int) $user->id && $connection->tenant_id === $grant->tenant_id
            && app(OAuthAccessPolicy::class)->canUseTenant($user, $grant->tenant_id)
            && $grant->token && $grant->client?->enabled
            && ($grant->token->expires_at === null || $grant->token->expires_at->gt(now()))
            && array_diff($grant->scopes, $grant->client->scopes) === [], 403);
        $membership = ProjectMembership::forTenant($connection->tenant_id)->where('user_id', $user->id)
            ->where('project_key', $connection->project_key)->first();
        abort_unless($membership !== null, 403);
        if ($write) {
            abort_unless(in_array('mcp:ingest', $grant->scopes, true)
                && $user->hasAnyRole(['admin', 'super-admin']) && empty($membership->scope_allowlist), 403);
        }
        return $user;
    }

    public function info(): array
    {
        $connection = $this->connection();
        return ['tenant_id' => $connection->tenant_id, 'project_key' => $connection->project_key,
            'mcp_url' => $connection->resource(), 'scopes' => request()->attributes->get('mcp.grant')->scopes,
            'workflow' => 'Search this project, read cited documents. To import, submit text or base64 files, open the returned approval URL, then poll until ready.'];
    }

    public function search(array $arguments): array
    {
        $connection = $this->connection();
        $data = Validator::make($arguments, ['query' => ['required', 'string', 'max:2000'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:10']])->validate();
        $results = app(KbSearchService::class)->search(query: $data['query'],
            projectKey: $connection->project_key, limit: $data['limit'] ?? 6);
        return ['project_key' => $connection->project_key, 'results' => $results->values()->map(fn (array $hit) => [
            'chunk_id' => $hit['chunk_id'], 'chunk_text' => mb_substr($hit['chunk_text'] ?? '', 0, 12000),
            'truncated' => mb_strlen($hit['chunk_text'] ?? '') > 12000,
            'heading_path' => $hit['heading_path'] ?? null, 'document' => array_intersect_key($hit['document'] ?? [],
                array_flip(['id', 'title', 'source_path', 'source_type'])),
        ])->all()];
    }

    public function document(array $arguments): array
    {
        $connection = $this->connection();
        $data = Validator::make($arguments, ['document_id' => ['required', 'integer', 'min:1'],
            'offset' => ['sometimes', 'integer', 'min:0'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:10']])->validate();
        // AccessScopeScope remains active: project/folder/tag/source ACLs apply.
        $doc = KnowledgeDocument::forTenant($connection->tenant_id)->where('project_key', $connection->project_key)
            ->findOrFail($data['document_id']);
        $chunks = $doc->chunks()->orderBy('chunk_order')->offset($data['offset'] ?? 0)->limit($data['limit'] ?? 6)
            ->get(['id', 'chunk_order', 'heading_path', 'chunk_text']);
        return ['document_id' => $doc->id, 'title' => $doc->title, 'source_path' => $doc->source_path,
            'project_key' => $doc->project_key, 'chunks' => $chunks->map(fn ($chunk) => [
                'id' => $chunk->id, 'chunk_order' => $chunk->chunk_order, 'heading_path' => $chunk->heading_path,
                'text' => mb_substr($chunk->chunk_text, 0, 12000), 'truncated' => mb_strlen($chunk->chunk_text) > 12000,
            ])->all(), 'total_chunks' => $doc->chunks()->count()];
    }

    public function audit(string $action, string $status, array $metadata = []): AdminCommandAudit
    {
        return AdminCommandAudit::create(['tenant_id' => app(TenantContext::class)->current(),
            'user_id' => Auth::id(), 'command' => $action, 'args_json' => $metadata,
            'status' => $status, 'started_at' => now(),
            'completed_at' => $status !== 'started' ? now() : null]);
    }
}
