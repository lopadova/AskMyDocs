<?php

declare(strict_types=1);

namespace Tests\Unit\Compliance;

use App\Compliance\AskMyDocsUserDataExporter;
use App\Models\ChatLog;
use App\Models\ChatLogProvenance;
use App\Models\Conversation;
use App\Models\KbCanonicalAudit;
use App\Models\McpServer;
use App\Models\McpToolCallAudit;
use App\Models\Message;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Tests\TestCase;

class AskMyDocsUserDataExporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exports_all_user_owned_rows_across_tenants_even_without_explicit_memberships(): void
    {
        // v8.0.2 / Copilot iter-3 on PR #224 — UserTenantResolver
        // now data-derives the tenant set from user-owned tables
        // (conversations, chat_logs, connector_installations) IN
        // ADDITION to project_memberships, so a DSAR export pick
        // up tenant-b rows even when the user never had (or had
        // revoked) a membership there. This was a real Art. 15
        // gap pre-v8.0.2.
        //
        // The test name + assertions were originally written to
        // ENCODE the buggy "active-tenant-only" behaviour. Updated
        // here to assert the corrected "every tenant the user has
        // data in" behaviour, which is the actual GDPR contract.

        $user = $this->makeUser();

        app(TenantContext::class)->set('tenant-a');

        $tenantAConversation = Conversation::query()->create([
            'tenant_id' => 'tenant-a',
            'user_id' => $user->id,
            'title' => 'Tenant A conversation',
            'project_key' => 'alpha',
        ]);

        $tenantBConversation = Conversation::query()->create([
            'tenant_id' => 'tenant-b',
            'user_id' => $user->id,
            'title' => 'Tenant B conversation',
            'project_key' => 'beta',
        ]);

        $tenantAMessage = Message::query()->create([
            'tenant_id' => 'tenant-a',
            'conversation_id' => $tenantAConversation->id,
            'role' => 'user',
            'content' => 'hello from tenant A',
        ]);

        $tenantBMessage = Message::query()->create([
            'tenant_id' => 'tenant-b',
            'conversation_id' => $tenantBConversation->id,
            'role' => 'user',
            'content' => 'hello from tenant B',
        ]);

        $tenantALog = ChatLog::query()->create([
            'tenant_id' => 'tenant-a',
            'session_id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id,
            'question' => 'Q A',
            'answer' => 'A A',
            'project_key' => 'alpha',
            'ai_provider' => 'mock',
            'ai_model' => 'mock',
            'latency_ms' => 10,
        ]);

        $tenantBLog = ChatLog::query()->create([
            'tenant_id' => 'tenant-b',
            'session_id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id,
            'question' => 'Q B',
            'answer' => 'A B',
            'project_key' => 'beta',
            'ai_provider' => 'mock',
            'ai_model' => 'mock',
            'latency_ms' => 10,
        ]);

        $chunkId = $this->createKnowledgeChunk('tenant-a', 'alpha');

        ChatLogProvenance::query()->create([
            'tenant_id' => 'tenant-a',
            'chat_log_id' => $tenantALog->id,
            'message_id' => $tenantAMessage->id,
            'answer_token_start' => 0,
            'answer_token_end' => 4,
            'knowledge_chunk_id' => $chunkId,
            'source_path' => 'kb://alpha/doc.md',
            'contribution_score' => 0.75,
        ]);

        $tenantAInstallation = ConnectorInstallation::query()->create([
            'tenant_id' => 'tenant-a',
            'connector_name' => 'google-drive',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => $user->id,
        ]);

        $tenantBInstallation = ConnectorInstallation::query()->create([
            'tenant_id' => 'tenant-b',
            'connector_name' => 'notion',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => $user->id,
        ]);

        KbCanonicalAudit::query()->create([
            'tenant_id' => 'tenant-a',
            'project_key' => 'alpha',
            'doc_id' => 'doc-alpha',
            'slug' => 'doc-alpha',
            'event_type' => 'updated',
            'actor' => $user->email,
            'created_at' => now(),
        ]);

        KbCanonicalAudit::query()->create([
            'tenant_id' => 'tenant-a',
            'project_key' => 'alpha',
            'doc_id' => 'doc-alpha-2',
            'slug' => 'doc-alpha-2',
            'event_type' => 'updated',
            'actor' => (string) $user->id,
            'created_at' => now(),
        ]);

        KbCanonicalAudit::query()->create([
            'tenant_id' => 'tenant-a',
            'project_key' => 'alpha',
            'doc_id' => 'doc-system',
            'slug' => 'doc-system',
            'event_type' => 'updated',
            'actor' => 'system',
            'created_at' => now(),
        ]);

        KbCanonicalAudit::query()->create([
            'tenant_id' => 'tenant-b',
            'project_key' => 'beta',
            'doc_id' => 'doc-beta',
            'slug' => 'doc-beta',
            'event_type' => 'updated',
            'actor' => $user->email,
            'created_at' => now(),
        ]);

        $serverA = McpServer::query()->create([
            'tenant_id' => 'tenant-a',
            'name' => 'tenant-a-server',
            'transport' => 'http',
            'endpoint' => 'https://example.test/mcp-a',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $serverB = McpServer::query()->create([
            'tenant_id' => 'tenant-b',
            'name' => 'tenant-b-server',
            'transport' => 'http',
            'endpoint' => 'https://example.test/mcp-b',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        McpToolCallAudit::query()->create([
            'tenant_id' => 'tenant-a',
            'user_id' => $user->id,
            'mcp_server_id' => $serverA->id,
            'conversation_id' => $tenantAConversation->id,
            'message_id' => $tenantAMessage->id,
            'tool_name' => 'search',
            'input_json_redacted' => ['q' => 'alpha'],
            'result_hash' => str_repeat('a', 64),
            'duration_ms' => 12,
            'status' => McpToolCallAudit::STATUS_OK,
        ]);

        McpToolCallAudit::query()->create([
            'tenant_id' => 'tenant-b',
            'user_id' => $user->id,
            'mcp_server_id' => $serverB->id,
            'conversation_id' => $tenantBConversation->id,
            'message_id' => null,
            'tool_name' => 'search',
            'input_json_redacted' => ['q' => 'beta'],
            'result_hash' => str_repeat('b', 64),
            'duration_ms' => 12,
            'status' => McpToolCallAudit::STATUS_OK,
        ]);

        $export = app(AskMyDocsUserDataExporter::class)->export($user);

        // Conversations / messages / chat_logs / connector_installations
        // / mcp_tool_call_audit: BOTH tenants present (Art. 15 full
        // surface). Use canonicalised comparison since the per-tenant
        // loop order is not contractual.
        $this->assertEqualsCanonicalizing(
            [$tenantAConversation->id, $tenantBConversation->id],
            array_values(array_column($export['conversations'], 'id')),
        );
        $this->assertEqualsCanonicalizing(
            [$tenantAMessage->id, $tenantBMessage->id],
            array_values(array_column($export['messages'], 'id')),
        );
        $this->assertEqualsCanonicalizing(
            [$tenantALog->id, $tenantBLog->id],
            array_values(array_column($export['chat_logs'], 'id')),
        );
        // chat_log_provenance: only seeded in tenant-a.
        $this->assertCount(1, $export['chat_log_provenance']);
        $this->assertEqualsCanonicalizing(
            [$tenantAInstallation->id, $tenantBInstallation->id],
            array_values(array_column($export['connector_installations'], 'id')),
        );
        // kb_canonical_audit: tenant-a (email + id) + tenant-b (email).
        // `system` actor is NOT user-attributable → must NOT appear.
        $this->assertEqualsCanonicalizing(
            [$user->email, (string) $user->id, $user->email],
            array_values(array_column($export['kb_canonical_audit'], 'actor')),
        );
        $this->assertEqualsCanonicalizing(
            [$serverA->id, $serverB->id],
            array_values(array_column($export['mcp_tool_call_audit'], 'mcp_server_id')),
        );

        // _dsar_meta envelope must surface the tenant set actually
        // scanned. The user has NO project_memberships in either
        // tenant, so the set comes from the data-derived sweep + the
        // active tenant fallback. Both tenants must appear.
        $this->assertArrayHasKey('_dsar_meta', $export);
        $this->assertEqualsCanonicalizing(
            ['tenant-a', 'tenant-b'],
            $export['_dsar_meta']['tenants_scanned'],
        );
        $this->assertSame('tenant-a', $export['_dsar_meta']['active_tenant']);
    }

    public function test_it_exports_mcp_audit_rows_written_by_the_package_via_actor(): void
    {
        // v7.0/W6.3 — mirror of the deleter regression. The package
        // writes `user_id=NULL` + opaque `actor`; the exporter MUST
        // surface those rows so the DSAR Art. 15 dossier is complete.
        $user = $this->makeUser();
        app(TenantContext::class)->set('tenant-a');

        $server = McpServer::query()->create([
            'tenant_id' => 'tenant-a',
            'name' => 'pkg-server',
            'transport' => 'http',
            'endpoint' => 'https://example.test/mcp',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $rowBareId = McpToolCallAudit::query()->create([
            'tenant_id' => 'tenant-a',
            'user_id' => null,
            'actor' => (string) $user->id,
            'mcp_server_id' => $server->id,
            'tool_name' => 'search',
            'input_json_redacted' => ['q' => 'alpha'],
            'result_hash' => str_repeat('a', 64),
            'duration_ms' => 1,
            'status' => McpToolCallAudit::STATUS_OK,
        ]);

        $rowPrefixedId = McpToolCallAudit::query()->create([
            'tenant_id' => 'tenant-a',
            'user_id' => null,
            'actor' => 'user:'.$user->id,
            'mcp_server_id' => $server->id,
            'tool_name' => 'search',
            'input_json_redacted' => ['q' => 'beta'],
            'result_hash' => str_repeat('b', 64),
            'duration_ms' => 1,
            'status' => McpToolCallAudit::STATUS_OK,
        ]);

        $rowEmail = McpToolCallAudit::query()->create([
            'tenant_id' => 'tenant-a',
            'user_id' => null,
            'actor' => $user->email,
            'mcp_server_id' => $server->id,
            'tool_name' => 'search',
            'input_json_redacted' => ['q' => 'gamma'],
            'result_hash' => str_repeat('c', 64),
            'duration_ms' => 1,
            'status' => McpToolCallAudit::STATUS_OK,
        ]);

        McpToolCallAudit::query()->create([
            'tenant_id' => 'tenant-a',
            'user_id' => null,
            'actor' => 'system',
            'mcp_server_id' => $server->id,
            'tool_name' => 'search',
            'input_json_redacted' => ['q' => 'delta'],
            'result_hash' => str_repeat('d', 64),
            'duration_ms' => 1,
            'status' => McpToolCallAudit::STATUS_OK,
        ]);

        $export = app(AskMyDocsUserDataExporter::class)->export($user);

        $exportedIds = array_values(array_column($export['mcp_tool_call_audit'], 'id'));
        $this->assertContains($rowBareId->id, $exportedIds);
        $this->assertContains($rowPrefixedId->id, $exportedIds);
        $this->assertContains($rowEmail->id, $exportedIds);
        // `system` actor is NOT the user — must NOT appear in the user's
        // export dossier.
        $this->assertCount(3, $exportedIds);
    }

    public function test_it_rejects_objects_without_a_positive_integer_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(AskMyDocsUserDataExporter::class)->export((object) ['id' => '']);
    }

    /**
     * v8.0.2 / deep-review C — User is host-wide. A DSAR Art. 15
     * request from a user who has membership AND data in BOTH
     * tenant-a (active context) and tenant-b must surface BOTH
     * tenants' rows. Prior to v8.0.2, only the active tenant's data
     * was returned, breaching the right-of-access for the other
     * tenant.
     */
    public function test_it_aggregates_data_across_every_tenant_the_user_has_membership_in(): void
    {
        $user = $this->makeUser();
        app(TenantContext::class)->set('tenant-a');

        \App\Models\ProjectMembership::query()->create([
            'tenant_id' => 'tenant-a',
            'user_id' => $user->id,
            'project_key' => 'alpha',
            'role' => 'member',
            'scope_allowlist' => [],
        ]);
        \App\Models\ProjectMembership::query()->create([
            'tenant_id' => 'tenant-b',
            'user_id' => $user->id,
            'project_key' => 'beta',
            'role' => 'member',
            'scope_allowlist' => [],
        ]);

        $convA = Conversation::query()->create([
            'tenant_id' => 'tenant-a',
            'user_id' => $user->id,
            'title' => 'Tenant A conv',
            'project_key' => 'alpha',
        ]);
        $convB = Conversation::query()->create([
            'tenant_id' => 'tenant-b',
            'user_id' => $user->id,
            'title' => 'Tenant B conv',
            'project_key' => 'beta',
        ]);

        ChatLog::query()->create([
            'tenant_id' => 'tenant-a',
            'session_id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id,
            'question' => 'Q A',
            'answer' => 'A A',
            'project_key' => 'alpha',
            'ai_provider' => 'mock',
            'ai_model' => 'mock',
            'latency_ms' => 10,
        ]);
        ChatLog::query()->create([
            'tenant_id' => 'tenant-b',
            'session_id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id,
            'question' => 'Q B',
            'answer' => 'A B',
            'project_key' => 'beta',
            'ai_provider' => 'mock',
            'ai_model' => 'mock',
            'latency_ms' => 10,
        ]);

        $export = app(AskMyDocsUserDataExporter::class)->export($user);

        $conversationIds = array_values(array_column($export['conversations'], 'id'));
        $this->assertContains($convA->id, $conversationIds);
        $this->assertContains(
            $convB->id,
            $conversationIds,
            'C: DSAR export must aggregate rows across every tenant the user has membership in.',
        );
        $this->assertCount(2, $export['chat_logs']);

        $this->assertArrayHasKey('_dsar_meta', $export);
        $this->assertEqualsCanonicalizing(
            ['tenant-a', 'tenant-b'],
            $export['_dsar_meta']['tenants_scanned'],
        );
        $this->assertSame('tenant-a', $export['_dsar_meta']['active_tenant']);
    }

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Compliance User',
            'email' => 'compliance-'.uniqid().'@example.test',
            'password' => Hash::make('secret-password'),
        ]);
    }

    private function createKnowledgeChunk(string $tenantId, string $projectKey): int
    {
        $documentId = DB::table('knowledge_documents')->insertGetId([
            'tenant_id' => $tenantId,
            'project_key' => $projectKey,
            'source_type' => 'markdown',
            'title' => 'Compliance Doc',
            'source_path' => $projectKey.'/doc.md',
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => str_repeat('c', 64),
            'version_hash' => str_repeat('d', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('knowledge_chunks')->insertGetId([
            'tenant_id' => $tenantId,
            'knowledge_document_id' => $documentId,
            'project_key' => $projectKey,
            'chunk_order' => 0,
            'chunk_hash' => str_repeat('e', 64),
            'chunk_text' => 'Compliance chunk text',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
