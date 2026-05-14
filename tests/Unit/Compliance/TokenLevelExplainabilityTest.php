<?php

declare(strict_types=1);

namespace Tests\Unit\Compliance;

use App\Compliance\TokenLevelExplainability;
use App\Models\ChatLog;
use App\Models\ChatLogProvenance;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TokenLevelExplainabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_capture_persists_rows_and_stamps_the_active_tenant_when_missing(): void
    {
        $user = User::create([
            'name' => 'Compliance User',
            'email' => 'token-'.uniqid().'@example.test',
            'password' => Hash::make('secret-password'),
        ]);

        app(TenantContext::class)->set('tenant-a');

        $conversation = Conversation::query()->create([
            'tenant_id' => 'tenant-a',
            'user_id' => $user->id,
            'title' => 'Traceable conversation',
            'project_key' => 'alpha',
        ]);

        $message = Message::query()->create([
            'tenant_id' => 'tenant-a',
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Traceable answer',
        ]);

        $chatLog = ChatLog::query()->create([
            'tenant_id' => 'tenant-a',
            'session_id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id,
            'question' => 'How?',
            'answer' => 'Like this.',
            'project_key' => 'alpha',
            'ai_provider' => 'mock',
            'ai_model' => 'mock',
            'latency_ms' => 10,
        ]);

        $chunkId = $this->createKnowledgeChunk('tenant-a', 'alpha');

        (new TokenLevelExplainability())->capture([
            [
                'chat_log_id' => $chatLog->id,
                'message_id' => $message->id,
                'answer_token_start' => 0,
                'answer_token_end' => 6,
                'knowledge_chunk_id' => $chunkId,
                'source_path' => 'kb://alpha/doc.md',
                'contribution_score' => 0.5,
            ],
        ]);

        $row = ChatLogProvenance::query()->firstOrFail();

        $this->assertSame('tenant-a', $row->tenant_id);
        $this->assertSame($chatLog->id, $row->chat_log_id);
        $this->assertSame($message->id, $row->message_id);
        $this->assertSame(0.5, $row->contribution_score);
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
