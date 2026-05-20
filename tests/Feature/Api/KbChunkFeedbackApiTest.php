<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\KbChunkFeedback;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class KbChunkFeedbackApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_users_can_store_opposite_feedback_on_same_chunk(): void
    {
        $doc = KnowledgeDocument::create([
            'project_key' => 'default',
            'source_type' => 'markdown',
            'title' => 'Doc',
            'source_path' => 'docs/demo.md',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', 'doc'),
            'version_hash' => hash('sha256', 'doc-v1'),
            'metadata' => [],
            'indexed_at' => now(),
        ]);

        $chunk = KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id,
            'project_key' => 'default',
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', 'chunk-1'),
            'chunk_text' => 'Sample chunk',
            'metadata' => [],
            'embedding' => [0.1],
        ]);

        $alice = User::create([
            'name' => 'alice',
            'email' => 'alice+'.uniqid().'@demo.local',
            'password' => 'hash',
        ]);
        $bob = User::create([
            'name' => 'bob',
            'email' => 'bob+'.uniqid().'@demo.local',
            'password' => 'hash',
        ]);

        $this->actingAs($alice)->postJson('/api/kb/feedback', [
            'chunk_id' => $chunk->id,
            'signal' => KbChunkFeedback::SIGNAL_SHOULD_HAVE_CITED,
        ])->assertOk()->assertJson([
            'chunk_id' => $chunk->id,
            'signal' => KbChunkFeedback::SIGNAL_SHOULD_HAVE_CITED,
        ]);

        $this->actingAs($bob)->postJson('/api/kb/feedback', [
            'chunk_id' => $chunk->id,
            'signal' => KbChunkFeedback::SIGNAL_NOT_RELEVANT,
        ])->assertOk()->assertJson([
            'chunk_id' => $chunk->id,
            'signal' => KbChunkFeedback::SIGNAL_NOT_RELEVANT,
        ]);

        $this->assertDatabaseHas('kb_chunk_feedback', [
            'tenant_id' => 'default',
            'user_id' => $alice->id,
            'knowledge_chunk_id' => $chunk->id,
            'signal' => KbChunkFeedback::SIGNAL_SHOULD_HAVE_CITED,
        ]);
        $this->assertDatabaseHas('kb_chunk_feedback', [
            'tenant_id' => 'default',
            'user_id' => $bob->id,
            'knowledge_chunk_id' => $chunk->id,
            'signal' => KbChunkFeedback::SIGNAL_NOT_RELEVANT,
        ]);
    }
}

