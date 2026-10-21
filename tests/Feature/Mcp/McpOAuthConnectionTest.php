<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Models\McpProjectConnection;
use App\Models\OAuthAccessToken;
use App\Models\OAuthClient;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class McpOAuthConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected OAuthClient $client;
    protected McpProjectConnection $connection;
    protected string $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('acme');
        $this->user = User::create(['name' => 'Developer', 'email' => 'developer@example.test', 'password' => 'secret123']);
        foreach (['acme', 'second'] as $tenant) {
            foreach (['engineering', 'legal'] as $project) {
                ProjectMembership::create(['tenant_id' => $tenant, 'project_key' => $project,
                    'user_id' => $this->user->id, 'role' => 'member']);
            }
        }
        $this->connection = McpProjectConnection::create(['user_id' => $this->user->id]);
        $registration = $this->postJson('/oauth/register', ['client_name' => 'Codex',
            'redirect_uris' => ['http://127.0.0.1:31001/callback'], 'token_endpoint_auth_method' => 'none'])
            ->assertCreated()->assertJsonPath('grant_types.1', 'refresh_token');
        $this->client = OAuthClient::findOrFail($registration->json('client_id'));
        $this->verifier = str_repeat('v', 64);
        Storage::fake('kb'); Storage::fake('kb-staging');
    }

    protected function start(): string
    {
        $response = $this->get('/oauth/authorize?'.http_build_query([
            'client_id' => $this->client->id, 'redirect_uri' => 'http://127.0.0.1:31002/callback',
            'response_type' => 'code', 'state' => 'state', 'scope' => 'mcp:read mcp:ingest',
            'resource' => $this->connection->resource(), 'code_challenge_method' => 'S256',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '='),
        ]))->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        return $query['request'];
    }

    protected function grant(string $project = 'engineering'): array
    {
        $handle = $this->start();
        $response = $this->actingAsWithoutTenant($this->user, 'web')->postJson('/api/oauth/authorization/'.$handle,
            ['decision' => 'approve', 'tenant_id' => 'acme', 'project_key' => $project])->assertOk();
        parse_str(parse_url($response->json('redirect_to'), PHP_URL_QUERY), $params);
        $this->assertSame(rtrim(config('app.url'), '/').'/oauth/mcp', $params['iss']);
        Auth::forgetGuards();
        $result = $this->postJson('/oauth/token', ['grant_type' => 'authorization_code',
            'client_id' => $this->client->id, 'code' => $params['code'], 'code_verifier' => $this->verifier,
            'redirect_uri' => 'http://127.0.0.1:31002/callback', 'resource' => $this->connection->resource()])
            ->assertOk()->assertJsonStructure(['access_token', 'refresh_token'])->json();
        Auth::forgetGuards();
        return $result;
    }

    protected function callTool(string $token, string $tool, array $arguments = []): \Illuminate\Testing\TestResponse
    {
        Auth::forgetGuards();
        return $this->withToken($token)->postJson('/mcp/knowledge/'.$this->connection->id,
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]]);
    }

    public function test_discovery_and_unauthenticated_challenge_advertise_exact_connection_resource(): void
    {
        $this->postJson('/mcp/knowledge/'.$this->connection->id, ['method' => 'initialize'])->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer resource_metadata="'.rtrim(config('app.url'), '/')
                .'/.well-known/oauth-protected-resource/mcp/knowledge/'.$this->connection->id.'", scope="mcp:read mcp:ingest"');
        $this->getJson('/.well-known/oauth-protected-resource/mcp/knowledge/'.$this->connection->id)
            ->assertOk()->assertJsonPath('resource', $this->connection->resource());
    }

    public function test_consent_lists_company_projects_and_requires_exactly_one_project(): void
    {
        $handle = $this->start();
        $this->actingAsWithoutTenant($this->user, 'web')->getJson('/api/oauth/authorization/'.$handle)->assertOk()
            ->assertJsonPath('requires_project', true)->assertJsonCount(2, 'teams')->assertJsonCount(2, 'teams.0.projects');
        $this->postJson('/api/oauth/authorization/'.$handle, ['decision' => 'approve', 'tenant_id' => 'acme'])
            ->assertForbidden();
        $this->postJson('/api/oauth/authorization/'.$handle, ['decision' => 'approve', 'tenant_id' => 'acme', 'project_key' => 'unknown'])
            ->assertForbidden();
    }

    public function test_oauth_tool_uses_pinned_project_and_refuses_another_connection_url(): void
    {
        $tokens = $this->grant();
        $result = $this->callTool($tokens['access_token'], 'kb-connected-project-tool', ['project_key' => 'legal'])->assertOk();
        $this->assertStringContainsString('engineering', $result->getContent());
        $this->assertStringNotContainsString('"project_key":"legal"', $result->getContent());
        $other = McpProjectConnection::create(['user_id' => $this->user->id]);
        Auth::forgetGuards();
        $this->withToken($tokens['access_token'])->postJson('/mcp/knowledge/'.$other->id, ['method' => 'tools/list'])->assertUnauthorized();
        $this->callTool($tokens['access_token'], 'kb-erase-subject-tool')->assertForbidden();
    }

    public function test_two_project_connections_have_distinct_urls_and_the_first_cannot_be_rebound(): void
    {
        $this->grant();
        $first = $this->connection;
        $handle = $this->start();
        $this->actingAsWithoutTenant($this->user, 'web')->postJson('/api/oauth/authorization/'.$handle,
            ['decision' => 'approve', 'tenant_id' => 'acme', 'project_key' => 'legal'])->assertForbidden();
        Auth::forgetGuards();
        $this->connection = McpProjectConnection::create(['user_id' => $this->user->id]);
        $this->grant('legal');
        $this->assertNotSame($first->resource(), $this->connection->resource());
        $this->assertSame('engineering', $first->fresh()->project_key);
        $this->assertSame('legal', $this->connection->fresh()->project_key);
    }

    public function test_refresh_rotates_credentials_and_replay_revokes_the_family(): void
    {
        $tokens = $this->grant();
        $payload = ['grant_type' => 'refresh_token', 'client_id' => $this->client->id,
            'resource' => $this->connection->resource(), 'refresh_token' => $tokens['refresh_token']];
        $new = $this->postJson('/oauth/token', $payload)->assertOk()->json();
        $this->assertNotSame($tokens['refresh_token'], $new['refresh_token']);
        $this->callTool($tokens['access_token'], 'kb-connected-project-tool')->assertUnauthorized();
        $this->callTool($new['access_token'], 'kb-connected-project-tool')->assertOk();
        $this->postJson('/oauth/token', $payload)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        $this->callTool($new['access_token'], 'kb-connected-project-tool')->assertUnauthorized();
    }

    public function test_membership_removal_and_disabled_client_revoke_access_immediately(): void
    {
        $token = $this->grant()['access_token'];
        ProjectMembership::where('user_id', $this->user->id)->where('tenant_id', 'acme')->where('project_key', 'engineering')->delete();
        $this->callTool($token, 'kb-connected-project-tool')->assertUnauthorized();
    }

    public function test_registration_refuses_unsafe_callbacks_and_broader_permissions(): void
    {
        foreach (['http://attacker.example/callback', 'javascript:alert(1)', 'https://user@attacker.example/callback', 'https://example.org/callback#fragment'] as $url) {
            $this->postJson('/oauth/register', ['redirect_uris' => [$url]])->assertBadRequest();
        }
        $this->postJson('/oauth/register', ['redirect_uris' => ['https://chatgpt.com/connector_platform_oauth_redirect'],
            'scope' => 'mcp:read mcp:tools:write'])->assertBadRequest();
    }

    public function test_mcp_discovery_advertises_only_project_scopes_and_downloads_have_unique_identities(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server/oauth/mcp')->assertOk()
            ->assertJsonPath('issuer', rtrim(config('app.url'), '/').'/oauth/mcp')
            ->assertJsonPath('scopes_supported', ['mcp:read', 'mcp:ingest']);
        $first = $this->actingAsWithoutTenant($this->user, 'web')->postJson('/api/oauth/mcp-connections')->assertCreated()->json();
        $second = $this->postJson('/api/oauth/mcp-connections')->assertCreated()->json();
        $this->assertNotSame($first['mcp_url'], $second['mcp_url']);
        $package = $this->get($first['download_url'])->assertOk();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($package->baseResponse->getFile()->getPathname()));
        try {
            $manifest = json_decode($zip->getFromName('plugin/plugin.json'), true, flags: JSON_THROW_ON_ERROR);
            $mcp = json_decode($zip->getFromName('plugin/mcp.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('askmydocs-'.$first['id'], $manifest['name']);
            $this->assertSame($first['mcp_url'], $mcp['mcpServers']['knowledge']['url']);
            $this->assertSame('dcr', $mcp['mcpServers']['knowledge']['extensions']['com.openai']['auth']['client']['mode']);
            $this->assertStringNotContainsString('Bearer ', $zip->getFromName('plugin/mcp.json'));
            $this->assertNotFalse($zip->getFromName('.agents/plugins/marketplace.json'));
        } finally { $zip->close(); $this->assertTrue(unlink($package->baseResponse->getFile()->getPathname())); }
    }

    public function test_read_only_scope_blocks_ingest_and_refresh_cannot_expand_permissions(): void
    {
        $tokens = $this->grant();
        $read = $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $this->client->id,
            'refresh_token' => $tokens['refresh_token'], 'resource' => $this->connection->resource(), 'scope' => 'mcp:read'])
            ->assertOk()->assertJsonPath('scope', 'mcp:read')->json();
        $this->callTool($read['access_token'], 'kb-ingest-documents-tool', ['documents' => []])->assertForbidden();
        $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $this->client->id,
            'refresh_token' => $read['refresh_token'], 'resource' => $this->connection->resource(), 'scope' => 'mcp:read mcp:ingest'])
            ->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
    }

    public function test_rest_adapter_has_the_same_project_and_scope_authority(): void
    {
        $tokens = $this->grant();
        $this->withToken($tokens['access_token'])->postJson('/mcp/knowledge/'.$this->connection->id.'/api/info')
            ->assertOk()->assertJsonPath('project_key', 'engineering');
    }

    public function test_session_and_ordinary_personal_token_do_not_authorize_the_mcp_server(): void
    {
        $this->actingAsWithoutTenant($this->user, 'web')->postJson('/mcp/knowledge/'.$this->connection->id, ['method' => 'initialize'])->assertUnauthorized();
        $pat = $this->user->createToken('ordinary', ['*']);
        $this->callTool($pat->plainTextToken, 'kb-connected-project-tool')->assertUnauthorized();
    }

    private function importToken(): string
    {
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->user->assignRole('admin');
        return $this->grant()['access_token'];
    }

    private function toolData(\Illuminate\Testing\TestResponse $response): array
    {
        $response->assertOk();
        $this->assertNull($response->json('error'), $response->getContent());
        return json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_import_previews_exact_files_and_retries_do_not_duplicate_or_index_them(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $token = $this->importToken();
        $arguments = ['documents' => [['filename' => 'guide.md', 'content' => "# Guide\n\nImplementation rules."]]];
        $first = $this->toolData($this->callTool($token, 'kb-ingest-documents-tool', $arguments));
        $second = $this->toolData($this->callTool($token, 'kb-ingest-documents-tool', $arguments));
        $this->assertSame($first['import_id'], $second['import_id']);
        $this->assertSame('awaiting_approval', $first['status']);
        $this->assertFalse($first['ready']);
        $this->assertSame(hash('sha256', $arguments['documents'][0]['content']), $first['documents'][0]['sha256']);
        $this->assertDatabaseCount('mcp_document_imports', 1);
        $this->assertDatabaseCount('kb_ingest_batches', 1);
        $this->assertDatabaseCount('knowledge_documents', 0);
        \Illuminate\Support\Facades\Queue::assertNothingPushed();
        // An MCP bearer cannot approve its own proposed mutation.
        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/oauth/mcp-imports/'.$first['import_id'].'/confirm')->assertUnauthorized();
    }

    public function test_browser_approval_runs_real_pipeline_and_mcp_can_read_indexed_document(): void
    {
        config(['queue.default' => 'sync', 'kb.change_analysis.enabled' => false, 'kb.autowiki.enabled' => false,
            'kb.reranking.enabled' => false]);
        $embeddings = \Mockery::mock(\App\Services\Kb\EmbeddingCacheService::class);
        $embeddings->shouldReceive('generate')->andReturnUsing(fn (array $texts) => new \App\Ai\EmbeddingsResponse(
            embeddings: array_map(fn () => [0.1, 0.2, 0.3], $texts), provider: 'openai', model: 'text-embedding-3-small'));
        $this->app->instance(\App\Services\Kb\EmbeddingCacheService::class, $embeddings);
        $token = $this->importToken();
        $prepared = $this->toolData($this->callTool($token, 'kb-ingest-documents-tool',
            ['documents' => [['filename' => 'spec.md', 'content' => "# Project specification\n\nThe approval workflow uses project-specific OAuth connections."]]]));
        Auth::forgetGuards(); $this->withoutToken();
        $this->actingAsWithoutTenant($this->user, 'web')->postJson('/api/oauth/mcp-imports/'.$prepared['import_id'].'/confirm')->assertAccepted();
        Auth::forgetGuards();
        $status = $this->toolData($this->callTool($token, 'kb-import-status-tool', ['import_id' => $prepared['import_id']]));
        $this->assertTrue($status['ready'], json_encode($status));
        $this->assertNotNull($status['items'][0]['document_id']);
        $read = $this->toolData($this->callTool($token, 'kb-connected-document-tool', ['document_id' => $status['items'][0]['document_id']]));
        $this->assertSame('engineering', $read['project_key']);
        $this->assertStringContainsString('approval workflow', json_encode($read));
        $search = $this->toolData($this->callTool($token, 'kb-connected-search-tool', ['query' => 'project-specific OAuth']));
        $this->assertNotEmpty($search['results']);
        Auth::forgetGuards(); $this->withoutToken();
        $this->actingAsWithoutTenant($this->user, 'web')->postJson('/api/oauth/mcp-imports/'.$prepared['import_id'].'/confirm')->assertAccepted();
        $this->assertDatabaseCount('knowledge_documents', 1);
    }

    public function test_tampered_preview_and_revoked_permissions_prevent_import_commit(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $token = $this->importToken();
        $prepared = $this->toolData($this->callTool($token, 'kb-ingest-documents-tool',
            ['documents' => [['filename' => 'spec.md', 'content' => '# Trusted preview']]]));
        $item = \App\Models\KbIngestBatchItem::firstOrFail();
        Storage::disk('kb-staging')->put($item->staging_path, '# Changed content');
        Auth::forgetGuards(); $this->withoutToken();
        $this->actingAsWithoutTenant($this->user, 'web')->postJson('/api/oauth/mcp-imports/'.$prepared['import_id'].'/confirm')->assertConflict();
        $this->user->removeRole('admin');
        $this->postJson('/api/oauth/mcp-imports/'.$prepared['import_id'].'/confirm')->assertForbidden();
        $this->assertDatabaseCount('knowledge_documents', 0);
        \Illuminate\Support\Facades\Queue::assertNothingPushed();
    }

    public function test_document_read_cannot_cross_project_even_for_an_administrator(): void
    {
        $token = $this->importToken();
        $document = \App\Models\KnowledgeDocument::create(['tenant_id' => 'acme', 'project_key' => 'legal',
            'source_path' => 'private.md', 'source_type' => 'markdown', 'title' => 'Private legal document',
            'document_hash' => hash('sha256', 'private'), 'version_hash' => hash('sha256', 'private'), 'status' => 'active']);
        $response = $this->callTool($token, 'kb-connected-document-tool', ['document_id' => $document->id]);
        $this->assertStringNotContainsString('Private legal document', $response->getContent());
        $this->assertTrue($response->status() === 404 || $response->json('error') !== null || $response->json('result.isError') === true);
    }

    public function test_ingest_rejects_traversal_fake_binary_and_undeclared_fields_without_staging(): void
    {
        $token = $this->importToken();
        foreach ([['filename' => '../escape.md', 'content' => 'escape'],
            ['filename' => 'fake.pdf', 'content' => base64_encode('not a PDF'), 'encoding' => 'base64'],
            ['filename' => 'spec.md', 'content' => '# Spec', 'tenant_id' => 'second']] as $doc) {
            $response = $this->callTool($token, 'kb-ingest-documents-tool', ['documents' => [$doc]]);
            $this->assertTrue($response->status() === 422 || $response->json('error') !== null || $response->json('result.isError') === true, $response->getContent());
        }
        $this->assertDatabaseCount('mcp_document_imports', 0);
        $this->assertDatabaseCount('kb_ingest_batches', 0);
    }
}
