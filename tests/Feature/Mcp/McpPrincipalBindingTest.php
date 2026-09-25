<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Http\Middleware\EnforceMcpScope;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\McpTenantToken;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * SEC-AUDIT-fix (2026-09-25) — `AccessScopeScope::apply()` (R33) applies NO
 * restriction whatsoever when `auth()->user()` is null, and nothing on the
 * `/mcp/kb` route ever authenticated a Laravel user for the McpTenantToken
 * bearer: `auth:sanctum` was deliberately removed from routes/ai.php
 * (v8.37/W3b round 7) because it rejected that bearer type, and no
 * replacement principal binding was ever added.
 *
 * The practical effect, live on `main` since that removal: EVERY MCP
 * retrieval tool that queries `KnowledgeDocument`/`KnowledgeChunk`
 * (`KbSearchTool`, `KbReadChunkTool`, `KbSearchByProjectTool`, ...) ran with
 * the project/ACL layers of `AccessScopeScope` entirely disabled — only
 * tenant isolation (R30, via `TenantContext`) held. A token minted for a
 * user restricted to `hr/policies/**` could retrieve `hr/salaries/**`
 * chunks over MCP: the same shape as H8/the v8.31 scope-allowlist fix, this
 * time for the whole transport instead of one query arm.
 *
 * This file locks the fix: `EnforceMcpScope` now binds the token's
 * `created_by` user as the request principal before `$next($request)`, and
 * clears it in `finally` (same discipline as `ExecuteAgentRunJob`'s
 * principal restoration — a long-running worker must never leak one
 * caller's principal into the next request it handles).
 */
final class McpPrincipalBindingTest extends TestCase
{
    use RefreshDatabase;

    private const IN_SCOPE = 'hr/policies/remote-work.md';

    private const OUT_OF_SCOPE = 'hr/salaries/exec-comp.md';

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = app(TenantContext::class)->current();
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        parent::tearDown();
    }

    public function test_the_tokens_creator_is_bound_as_the_request_principal(): void
    {
        $principal = $this->makeUser();
        $this->mintToken('plain-token', $principal->id);

        $seenUserId = null;
        app(EnforceMcpScope::class)->handle($this->request('plain-token'), function () use (&$seenUserId) {
            $seenUserId = auth()->user()?->id;

            return response('ok', 200);
        });

        $this->assertSame($principal->id, $seenUserId, 'the MCP request must run as the token creator, not unauthenticated');
    }

    public function test_the_principal_is_cleared_after_a_successful_request(): void
    {
        $principal = $this->makeUser();
        $this->mintToken('plain-token', $principal->id);

        app(EnforceMcpScope::class)->handle($this->request('plain-token'), fn () => response('ok', 200));

        $this->assertNull(auth()->user(), 'a long-lived worker must never leak the MCP principal past this request');
    }

    public function test_the_principal_is_cleared_after_an_early_return_denial(): void
    {
        $principal = $this->makeUser();
        // Scope-missing (no `mcp:read`) triggers a 403 BEFORE `$next()` --
        // proves the finally-block restore covers the rejection path too,
        // not only the happy one (mirrors the existing tenant-restore test).
        $this->mintToken('plain-token', $principal->id, scopes: ['mcp:tools:propose']);

        $response = app(EnforceMcpScope::class)->handle($this->request('plain-token'), fn () => response('unreached', 500));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull(auth()->user());
    }

    public function test_a_token_without_a_created_by_user_is_refused(): void
    {
        McpTenantToken::query()->create([
            'tenant_id' => $this->tenantId,
            'label' => 'orphaned',
            'token_hash' => hash('sha256', 'orphan-token'),
            'token_last4' => 'oken',
            'scopes_json' => ['mcp:read'],
            'created_by' => null,
        ]);

        $response = app(EnforceMcpScope::class)->handle($this->request('orphan-token'), fn () => response('unreached', 500));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('mcp_principal_missing', (string) $response->getContent());
    }

    public function test_a_token_whose_creator_was_deleted_is_refused(): void
    {
        $principal = $this->makeUser();
        $this->mintToken('deleted-creator-token', $principal->id);
        // SoftDeletes on User: `nullOnDelete()` only fires on a HARD delete,
        // but a soft delete must be refused too — the creator is no longer
        // a valid principal to retrieve as either way, and offboarding
        // (SEC-OFFBOARD-001) must revoke the token's retrieval power
        // without a separate step.
        $principal->delete();

        $response = app(EnforceMcpScope::class)->handle($this->request('deleted-creator-token'), fn () => response('unreached', 500));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('mcp_principal_missing', (string) $response->getContent());
    }

    public function test_mcp_retrieval_is_restricted_to_the_creators_project_scope(): void
    {
        $this->makeDocumentWithChunk(self::IN_SCOPE, 'Remote work is allowed on Fridays.');
        $this->makeDocumentWithChunk(self::OUT_OF_SCOPE, 'The CFO base salary is 250000 EUR.');

        $scopedUser = $this->makeUser();
        ProjectMembership::create([
            'tenant_id' => $this->tenantId,
            'user_id' => $scopedUser->id,
            'project_key' => 'default',
            'role' => 'member',
            'scope_allowlist' => ['folder_globs' => ['hr/policies/**']],
        ]);
        $this->mintToken('scoped-token', $scopedUser->id);

        $paths = null;
        app(EnforceMcpScope::class)->handle($this->request('scoped-token'), function () use (&$paths) {
            // The exact builder shape KbSearchService/KbSearchTool use for
            // retrieval -- if the fix did not actually bind the principal,
            // AccessScopeScope's early-return-on-null-user would let both
            // documents through here.
            $paths = KnowledgeChunk::query()
                ->whereHas('document', fn ($q) => $q->where('status', '!=', 'archived'))
                ->with('document')
                ->get()
                ->map(fn (KnowledgeChunk $chunk) => $chunk->document->source_path)
                ->all();

            return response('ok', 200);
        });

        $this->assertSame(
            [self::IN_SCOPE],
            $paths,
            'MCP retrieval must be scoped by the token creator\'s project membership, not unrestricted.',
        );
    }

    // ── fixtures ────────────────────────────────────────────────────────

    private function makeUser(): User
    {
        return User::query()->create([
            'name' => 'MCP principal',
            'email' => 'mcp-principal-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
    }

    /**
     * @param  array<int, string>  $scopes
     */
    private function mintToken(string $plainToken, ?int $createdBy, array $scopes = ['mcp:read']): void
    {
        McpTenantToken::query()->create([
            'tenant_id' => $this->tenantId,
            'label' => 'test',
            'token_hash' => hash('sha256', $plainToken),
            'token_last4' => substr($plainToken, -4),
            'scopes_json' => $scopes,
            'created_by' => $createdBy,
        ]);
    }

    private function request(string $plainToken): Request
    {
        return Request::create('/mcp/kb', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainToken,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['method' => 'initialize', 'params' => []]));
    }

    private function makeDocumentWithChunk(string $sourcePath, string $text): KnowledgeDocument
    {
        $doc = KnowledgeDocument::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantId,
            'project_key' => 'default',
            'source_type' => 'upload',
            'title' => basename($sourcePath),
            'source_path' => $sourcePath,
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'status' => 'indexed',
            'document_hash' => hash('sha256', $sourcePath),
            'version_hash' => hash('sha256', $text),
        ]);

        KnowledgeChunk::create([
            'tenant_id' => $this->tenantId,
            'knowledge_document_id' => $doc->id,
            'project_key' => 'default',
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', $text),
            'heading_path' => '',
            'chunk_text' => $text,
        ]);

        return $doc;
    }
}
