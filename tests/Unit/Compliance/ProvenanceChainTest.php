<?php

declare(strict_types=1);

namespace Tests\Unit\Compliance;

use App\Compliance\ProvenanceChain;
use App\Models\ChatLog;
use App\Models\ChatLogProvenance;
use App\Models\Conversation;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Message;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProvenanceChainTest extends TestCase
{
    use RefreshDatabase;

    public function test_trace_preserves_pre_computed_shape(): void
    {
        $chain = new ProvenanceChain();
        $result = $chain->trace([
            'eval_trace_id' => 'trace-1',
            'retrieval' => ['vec_score' => 0.81],
            'chunk' => ['id' => 42],
            'document' => ['title' => 'Doc A'],
            'frontmatter_author' => 'Lorenzo',
        ]);

        $this->assertSame('trace-1', $result['eval_trace_id']);
        $this->assertSame(['vec_score' => 0.81], $result['retrieval']);
        $this->assertSame(['id' => 42], $result['chunk']);
        $this->assertSame(['title' => 'Doc A'], $result['document']);
        $this->assertSame('Lorenzo', $result['frontmatter_author']);
    }

    public function test_for_chat_log_joins_chunks_and_documents(): void
    {
        TenantContext::instance()->set('default');

        $user = User::create([
            'name' => 'Auditor',
            'email' => 'prov-'.uniqid().'@example.test',
            'password' => Hash::make('secret-pass'),
        ]);

        $document = KnowledgeDocument::create([
            'tenant_id' => 'default',
            'project_key' => 'docs',
            'source_type' => 'markdown',
            'title' => 'Cache policy',
            'source_path' => 'kb/dec-cache-v2.md',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => str_repeat('b', 64),
            'status' => 'indexed',
            'frontmatter_json' => ['author' => 'Lorenzo'],
        ]);

        $chunk = KnowledgeChunk::create([
            'tenant_id' => 'default',
            'knowledge_document_id' => $document->id,
            'project_key' => 'docs',
            'chunk_order' => 0,
            'chunk_hash' => str_repeat('c', 64),
            'heading_path' => '# Cache policy / Why',
            'chunk_text' => 'Cache TTL is 10 minutes by default; flushing is manual.',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => 'default',
            'user_id' => $user->id,
            'title' => 'Cache convo',
        ]);

        $message = Message::create([
            'tenant_id' => 'default',
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Cache TTL defaults to 10 minutes.',
        ]);

        $chatLog = ChatLog::create([
            'tenant_id' => 'default',
            'session_id' => 'sess',
            'user_id' => $user->id,
            'question' => 'Cache TTL?',
            'answer' => 'Cache TTL defaults to 10 minutes.',
            'project_key' => 'docs',
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-4o',
            'chunks_count' => 1,
        ]);

        ChatLogProvenance::create([
            'tenant_id' => 'default',
            'chat_log_id' => $chatLog->id,
            'message_id' => $message->id,
            'answer_token_start' => 0,
            'answer_token_end' => 32,
            'knowledge_chunk_id' => $chunk->id,
            'source_path' => $document->source_path,
            'contribution_score' => 0.84,
        ]);

        $result = (new ProvenanceChain())->forChatLog($chatLog->id);

        $this->assertSame($chatLog->id, $result['chat_log_id']);
        $this->assertSame('default', $result['tenant_id']);
        $this->assertCount(1, $result['spans']);
        $span = $result['spans'][0];
        $this->assertSame(0, $span['answer_token_start']);
        $this->assertSame(32, $span['answer_token_end']);
        $this->assertSame(0.84, $span['contribution_score']);
        $this->assertSame('kb/dec-cache-v2.md', $span['source_path']);
        $this->assertSame($chunk->id, $span['chunk']['id']);
        $this->assertSame('Cache policy', $span['document']['title']);
        $this->assertSame('Lorenzo', $span['frontmatter_author']);
        $this->assertFalse($span['document']['soft_deleted']);
    }

    public function test_for_chat_log_returns_empty_spans_when_no_provenance_recorded(): void
    {
        TenantContext::instance()->set('default');

        $user = User::create([
            'name' => 'Auditor',
            'email' => 'prov-'.uniqid().'@example.test',
            'password' => Hash::make('secret-pass'),
        ]);

        $chatLog = ChatLog::create([
            'tenant_id' => 'default',
            'session_id' => 'sess',
            'user_id' => $user->id,
            'question' => 'Q',
            'answer' => 'A',
            'project_key' => 'docs',
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-4o',
            'chunks_count' => 0,
        ]);

        $result = (new ProvenanceChain())->forChatLog($chatLog->id);
        $this->assertSame([], $result['spans']);
    }
}
