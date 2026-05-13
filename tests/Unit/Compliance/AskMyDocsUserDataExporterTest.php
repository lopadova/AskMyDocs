<?php

declare(strict_types=1);

namespace Tests\Unit\Compliance;

use App\Compliance\AskMyDocsUserDataExporter;
use App\Models\ChatLog;
use App\Models\ChatLogProvenance;
use App\Models\Conversation;
use App\Models\McpServer;
use App\Models\McpToolCallAudit;
use App\Models\Message;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AskMyDocsUserDataExporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exports_only_the_current_tenant_rows_for_the_user(): void
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

        Message::query()->create([
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

        $this->assertSame([$tenantAConversation->id], array_values(array_column($export['conversations'], 'id')));
        $this->assertSame([$tenantAMessage->id], array_values(array_column($export['messages'], 'id')));
        $this->assertSame([$tenantALog->id], array_values(array_column($export['chat_logs'], 'id')));
        $this->assertCount(1, $export['chat_log_provenance']);
        $this->assertSame([$serverA->id], array_values(array_column($export['mcp_tool_call_audit'], 'mcp_server_id')));
    }

    public function test_it_rejects_objects_without_a_positive_integer_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(AskMyDocsUserDataExporter::class)->export((object) ['id' => '']);
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
