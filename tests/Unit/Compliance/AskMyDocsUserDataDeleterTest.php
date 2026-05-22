<?php

declare(strict_types=1);

namespace Tests\Unit\Compliance;

use App\Compliance\AskMyDocsUserDataDeleter;
use App\Models\ChatLog;
use App\Models\Conversation;
use App\Models\McpServer;
use App\Models\McpToolCallAudit;
use App\Models\Message;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorCredential;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Tests\TestCase;

class AskMyDocsUserDataDeleterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_only_the_current_tenant_rows_for_the_user(): void
    {
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

        $tenantAAudit = McpToolCallAudit::query()->create([
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

        $tenantBAudit = McpToolCallAudit::query()->create([
            'tenant_id' => 'tenant-b',
            'user_id' => $user->id,
            'mcp_server_id' => $serverB->id,
            'conversation_id' => $tenantBConversation->id,
            'message_id' => $tenantBMessage->id,
            'tool_name' => 'search',
            'input_json_redacted' => ['q' => 'beta'],
            'result_hash' => str_repeat('b', 64),
            'duration_ms' => 12,
            'status' => McpToolCallAudit::STATUS_OK,
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

        $tenantACredential = ConnectorCredential::query()->create([
            'tenant_id' => 'tenant-a',
            'connector_installation_id' => $tenantAInstallation->id,
            'encrypted_access_token' => 'token-a',
            'encrypted_refresh_token' => 'refresh-a',
        ]);

        $tenantBCredential = ConnectorCredential::query()->create([
            'tenant_id' => 'tenant-b',
            'connector_installation_id' => $tenantBInstallation->id,
            'encrypted_access_token' => 'token-b',
            'encrypted_refresh_token' => 'refresh-b',
        ]);

        app(AskMyDocsUserDataDeleter::class)->delete($user);

        $this->assertDatabaseMissing('conversations', ['id' => $tenantAConversation->id]);
        $this->assertDatabaseMissing('messages', ['id' => $tenantAMessage->id]);
        $this->assertDatabaseMissing('chat_logs', ['id' => $tenantALog->id]);
        $this->assertDatabaseMissing('mcp_tool_call_audit', ['id' => $tenantAAudit->id]);
        $this->assertDatabaseMissing('connector_installations', ['id' => $tenantAInstallation->id]);
        $this->assertDatabaseMissing('connector_credentials', ['id' => $tenantACredential->id]);

        $this->assertDatabaseHas('conversations', ['id' => $tenantBConversation->id, 'tenant_id' => 'tenant-b']);
        $this->assertDatabaseHas('messages', ['id' => $tenantBMessage->id, 'tenant_id' => 'tenant-b']);
        $this->assertDatabaseHas('chat_logs', ['id' => $tenantBLog->id, 'tenant_id' => 'tenant-b']);
        $this->assertDatabaseHas('mcp_tool_call_audit', ['id' => $tenantBAudit->id, 'tenant_id' => 'tenant-b']);
        $this->assertDatabaseHas('connector_installations', ['id' => $tenantBInstallation->id, 'tenant_id' => 'tenant-b']);
        $this->assertDatabaseHas('connector_credentials', ['id' => $tenantBCredential->id, 'tenant_id' => 'tenant-b']);
    }

    public function test_it_deletes_mcp_audit_rows_written_by_the_package_via_actor(): void
    {
        // v7.0/W6.3 — `padosoft/askmydocs-mcp-pack` writes audit rows
        // with `user_id=NULL` and an opaque `actor` string. DSAR
        // erasure MUST match those rows too, otherwise the package's
        // audit trail survives an Art. 17 request.
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

        $rowOther = McpToolCallAudit::query()->create([
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

        app(AskMyDocsUserDataDeleter::class)->delete($user);

        $this->assertDatabaseMissing('mcp_tool_call_audit', ['id' => $rowBareId->id]);
        $this->assertDatabaseMissing('mcp_tool_call_audit', ['id' => $rowPrefixedId->id]);
        $this->assertDatabaseMissing('mcp_tool_call_audit', ['id' => $rowEmail->id]);
        // `system` actor MUST survive — it's not user-attributable.
        $this->assertDatabaseHas('mcp_tool_call_audit', ['id' => $rowOther->id]);
    }

    public function test_it_rejects_objects_without_a_positive_integer_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(AskMyDocsUserDataDeleter::class)->delete((object) ['id' => null]);
    }

    /**
     * v8.0.2 / deep-review C — DSAR Art. 17 erasure must wipe rows
     * across EVERY tenant the user has membership in, not just the
     * active TenantContext. Prior to v8.0.2, a user with data in
     * tenant-a (active) and tenant-b would have their tenant-b
     * conversations/chat_logs/etc. survive the erasure request —
     * silent breach. The whole delete now runs in a single outer
     * transaction so the multi-tenant erasure is atomic.
     */
    public function test_it_deletes_across_every_tenant_the_user_has_membership_in(): void
    {
        $user = $this->makeUser();
        app(\App\Support\TenantContext::class)->set('tenant-a');

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

        $convA = \App\Models\Conversation::query()->create([
            'tenant_id' => 'tenant-a',
            'user_id' => $user->id,
            'title' => 'Tenant A conv',
            'project_key' => 'alpha',
        ]);
        $convB = \App\Models\Conversation::query()->create([
            'tenant_id' => 'tenant-b',
            'user_id' => $user->id,
            'title' => 'Tenant B conv',
            'project_key' => 'beta',
        ]);

        $logA = \App\Models\ChatLog::query()->create([
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
        $logB = \App\Models\ChatLog::query()->create([
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

        app(AskMyDocsUserDataDeleter::class)->delete($user);

        $this->assertDatabaseMissing('conversations', ['id' => $convA->id]);
        // C: DSAR erasure must wipe rows in every tenant the user has
        // membership in — the tenant-b row would survive without the
        // per-tenant loop.
        $this->assertDatabaseMissing('conversations', ['id' => $convB->id]);
        $this->assertDatabaseMissing('chat_logs', ['id' => $logA->id]);
        $this->assertDatabaseMissing('chat_logs', ['id' => $logB->id]);
    }

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Compliance User',
            'email' => 'compliance-'.uniqid().'@example.test',
            'password' => Hash::make('secret-password'),
        ]);
    }
}
