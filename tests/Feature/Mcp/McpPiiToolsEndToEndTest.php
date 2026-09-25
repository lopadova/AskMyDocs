<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\KbDetokenizeTool;
use App\Mcp\Tools\KbEraseSubjectTool;
use App\Models\AdminCommandAudit;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\McpTenantToken;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Padosoft\PiiRedactor\Facades\Pii;
use Padosoft\PiiRedactor\TokenStore\Eloquent\PiiTokenMap;
use Tests\TestCase;

/**
 * SEC-AUDIT-fix (2026-09-25), follow-up flagged by the independent review of
 * the MCP principal-binding fix (`EnforceMcpScope`) — before that fix,
 * `auth()->user()` was ALWAYS null on the real `/mcp/kb` route, so
 * `KbEraseSubjectTool` and `KbDetokenizeTool` (both gate on
 * `auth()->user()?->can($permission)`) were unconditionally "Forbidden" over
 * MCP regardless of the calling token's creator — a previously-dead code
 * path. The principal-binding fix makes it live: a token whose creator
 * genuinely holds `pii.erase`/`pii.detokenize` (super-admin) now actually
 * works end-to-end over MCP, as the tools' own doc comments always
 * described. `KbEraseSubjectToolTest`/`KbDetokenizeToolTest` call
 * `->handle()` directly with `actingAs()`, which never exercises this
 * route/middleware distinction at all. This file drives BOTH tools through
 * the REAL `/mcp/kb` route (mirrors
 * `KbProposeTextCorrectionToolTest::test_a_real_http_request_with_a_tenant_token_reaches_the_tool_end_to_end`)
 * to prove: (a) the allow case genuinely works now, not just "no longer
 * 403s at the transport layer", and (b) a non-privileged creator's token
 * still gets refused by the tool's OWN permission gate.
 */
final class McpPiiToolsEndToEndTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->reset();
        $this->seed(RbacSeeder::class);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function user(string $role): User
    {
        $u = User::create([
            'name' => $role,
            'email' => $role.'-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $u->assignRole($role);

        return $u;
    }

    /**
     * Both tools are NOT #[IsReadOnly], so EnforceMcpScope requires the
     * mcp:tools:write scope to reach them at all -- independent of, and
     * layered underneath, each tool's own permission check.
     */
    private function mintWriteToken(string $plainToken, int $createdBy): void
    {
        McpTenantToken::query()->create([
            'tenant_id' => app(TenantContext::class)->current(),
            'label' => 'e2e test',
            'token_hash' => hash('sha256', $plainToken),
            'token_last4' => substr($plainToken, -4),
            'scopes_json' => ['mcp:read', 'mcp:tools:write'],
            'created_by' => $createdBy,
        ]);
    }

    private function callTool(string $plainToken, string $toolName, array $arguments): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Authorization', "Bearer {$plainToken}")
            ->postJson('/mcp/kb', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $toolName, 'arguments' => $arguments],
            ]);
    }

    // ── KbEraseSubjectTool ──────────────────────────────────────────────

    public function test_a_super_admin_creators_token_can_erase_over_mcp_end_to_end(): void
    {
        $email = 'mario.rossi@example.com';
        PiiTokenMap::create([
            'tenant_id' => app(TenantContext::class)->current(),
            'token' => '[tok:email:'.substr(md5('test'.$email), 0, 12).']',
            'original' => $email,
            'detector' => 'email',
        ]);

        $superAdmin = $this->user('super-admin');
        $plainToken = 'askmd_erase-'.bin2hex(random_bytes(8));
        $this->mintWriteToken($plainToken, $superAdmin->id);

        $response = $this->callTool($plainToken, (new KbEraseSubjectTool())->name(), ['values' => [$email]]);

        $response->assertOk();
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('error', $body, 'the real route must reach the tool, not be rejected by routing/auth before it');
        $this->assertFalse(
            (bool) ($body['result']['isError'] ?? true),
            'a super-admin creator must be able to erase over MCP now the principal is bound: '.json_encode($body['result']['content'] ?? $body, JSON_UNESCAPED_SLASHES),
        );
        $this->assertDatabaseMissing('pii_token_maps', ['original' => $email]);
        $this->assertDatabaseHas('admin_command_audit', [
            'command' => 'pii.erase',
            'status' => AdminCommandAudit::STATUS_COMPLETED,
        ]);
    }

    public function test_a_non_privileged_creators_token_is_still_refused_by_the_tools_own_gate(): void
    {
        $email = 'mario.rossi@example.com';
        PiiTokenMap::create([
            'tenant_id' => app(TenantContext::class)->current(),
            'token' => '[tok:email:'.substr(md5('test'.$email), 0, 12).']',
            'original' => $email,
            'detector' => 'email',
        ]);

        $admin = $this->user('admin'); // admin lacks pii.erase
        $plainToken = 'askmd_erase-denied-'.bin2hex(random_bytes(8));
        $this->mintWriteToken($plainToken, $admin->id);

        $response = $this->callTool($plainToken, (new KbEraseSubjectTool())->name(), ['values' => [$email]]);

        $response->assertOk(); // the transport reaches the tool -- the tool itself refuses
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('error', $body);
        $this->assertTrue((bool) ($body['result']['isError'] ?? false));
        $content = json_encode($body['result']['content'] ?? $body, JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('Forbidden', (string) $content);
        $this->assertDatabaseHas('pii_token_maps', ['original' => $email]);
        $this->assertDatabaseHas('admin_command_audit', [
            'command' => 'pii.erase',
            'status' => AdminCommandAudit::STATUS_REJECTED,
        ]);
    }

    // ── KbDetokenizeTool ────────────────────────────────────────────────

    private function tokenisedDoc(string $email): KnowledgeDocument
    {
        config()->set('pii-redactor.strategy', 'tokenise');
        config()->set('pii-redactor.salt', 'mcp-e2e-salt');
        $this->app->forgetInstance(\Padosoft\PiiRedactor\Strategies\RedactionStrategy::class);

        $tokenised = Pii::redact("Contact {$email}.");
        $doc = KnowledgeDocument::create([
            'project_key' => 'support',
            'source_type' => 'markdown',
            'title' => 'Doc',
            'source_path' => 'docs/support-'.uniqid().'.md',
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', uniqid()),
            'version_hash' => hash('sha256', uniqid()),
        ]);
        KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id,
            'project_key' => 'support',
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', $tokenised),
            'heading_path' => '',
            'chunk_text' => $tokenised,
            'metadata' => [],
            'embedding' => [0.1, 0.2, 0.3],
        ]);

        return $doc;
    }

    public function test_a_super_admin_creators_token_can_detokenize_over_mcp_end_to_end(): void
    {
        $email = 'mario.rossi@example.com';
        $doc = $this->tokenisedDoc($email);

        $superAdmin = $this->user('super-admin');
        $plainToken = 'askmd_detok-'.bin2hex(random_bytes(8));
        $this->mintWriteToken($plainToken, $superAdmin->id);

        $response = $this->callTool($plainToken, (new KbDetokenizeTool())->name(), ['document_id' => $doc->id]);

        $response->assertOk();
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('error', $body);
        $this->assertFalse(
            (bool) ($body['result']['isError'] ?? true),
            'a super-admin creator must be able to detokenize over MCP now the principal is bound: '.json_encode($body['result']['content'] ?? $body, JSON_UNESCAPED_SLASHES),
        );
        $content = json_encode($body['result']['content'] ?? $body, JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString($email, (string) $content);
        $this->assertDatabaseHas('admin_command_audit', [
            'command' => 'pii.detokenize',
            'status' => AdminCommandAudit::STATUS_COMPLETED,
        ]);
    }

    public function test_a_non_privileged_creators_token_is_still_refused_for_detokenize(): void
    {
        $doc = $this->tokenisedDoc('mario.rossi@example.com');

        $admin = $this->user('admin'); // admin lacks pii.detokenize
        $plainToken = 'askmd_detok-denied-'.bin2hex(random_bytes(8));
        $this->mintWriteToken($plainToken, $admin->id);

        $response = $this->callTool($plainToken, (new KbDetokenizeTool())->name(), ['document_id' => $doc->id]);

        $response->assertOk();
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('error', $body);
        $this->assertTrue((bool) ($body['result']['isError'] ?? false));
        $content = json_encode($body['result']['content'] ?? $body, JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('Forbidden', (string) $content);
        $this->assertDatabaseHas('admin_command_audit', [
            'command' => 'pii.detokenize',
            'status' => AdminCommandAudit::STATUS_REJECTED,
        ]);
    }
}
