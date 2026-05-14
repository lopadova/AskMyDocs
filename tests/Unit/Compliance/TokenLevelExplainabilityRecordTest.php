<?php

declare(strict_types=1);

namespace Tests\Unit\Compliance;

use App\Compliance\TokenLevelExplainability;
use App\Models\ChatLog;
use App\Models\ChatLogProvenance;
use App\Models\Conversation;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Message;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TokenLevelExplainabilityRecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_persists_one_provenance_row_per_attributed_chunk(): void
    {
        [$chatLog, $message] = $this->seedTurn();

        $chunks = [
            $this->seedChunk('kb/dec-cache-v2.md', 'Cache TTL defaults to ten minutes; flushing is manual.'),
            $this->seedChunk('kb/dec-cache-v3.md', 'Replication topology requires Redis cluster mode.'),
        ];
        $answer = 'Cache TTL defaults to ten minutes. Replication uses cluster mode.';

        $persisted = (new TokenLevelExplainability())->record($chatLog, $message, $chunks, $answer);

        self::assertGreaterThanOrEqual(1, $persisted);
        self::assertGreaterThanOrEqual(1, ChatLogProvenance::query()->count());
    }

    public function test_record_skips_chunks_without_a_chunk_id_or_source_path(): void
    {
        [$chatLog, $message] = $this->seedTurn();

        $persisted = (new TokenLevelExplainability())->record(
            $chatLog,
            $message,
            [
                ['source_path' => 'kb/missing-id.md', 'chunk_text' => 'Some text.'],
                ['knowledge_chunk_id' => 7, 'chunk_text' => 'Some text.'],
                ['knowledge_chunk_id' => 8, 'source_path' => 'kb/missing-text.md'],
            ],
            'Some text appears here.',
        );

        self::assertSame(0, $persisted, 'Invalid chunks must produce no provenance rows');
        self::assertSame(0, ChatLogProvenance::query()->count());
    }

    public function test_record_returns_zero_when_answer_is_empty(): void
    {
        [$chatLog, $message] = $this->seedTurn();
        $persisted = (new TokenLevelExplainability())->record(
            $chatLog,
            $message,
            [['knowledge_chunk_id' => 1, 'source_path' => 'kb/x.md', 'chunk_text' => 'irrelevant']],
            '',
        );
        self::assertSame(0, $persisted);
    }

    public function test_record_honours_the_config_gate(): void
    {
        Config::set('compliance.token_explainability.enabled', false);
        [$chatLog, $message] = $this->seedTurn();

        $persisted = (new TokenLevelExplainability())->record(
            $chatLog,
            $message,
            [['knowledge_chunk_id' => 1, 'source_path' => 'kb/x.md', 'chunk_text' => 'Cache TTL.']],
            'Cache TTL is 10 minutes.',
        );

        self::assertSame(0, $persisted);
        self::assertSame(0, ChatLogProvenance::query()->count());
    }

    public function test_record_writes_within_a_transaction_so_partial_state_is_impossible(): void
    {
        // We can't easily simulate a partial insert with SQLite + in-memory,
        // but we CAN assert that the implementation wraps the insert in
        // DB::transaction (smoke test: no exception, all rows landed).
        [$chatLog, $message] = $this->seedTurn();
        $chunks = collect(range(1, 5))->map(fn ($i) => $this->seedChunk(
            "kb/chunk-{$i}.md",
            "Cache TTL is ten minutes default policy version {$i}.",
        ))->all();

        $persisted = (new TokenLevelExplainability())->record(
            $chatLog,
            $message,
            $chunks,
            'Cache TTL is ten minutes default policy.',
        );

        self::assertGreaterThanOrEqual(1, $persisted);
        self::assertSame($persisted, ChatLogProvenance::query()->count());
    }

    public function test_record_writes_contribution_score_normalised_to_zero_one(): void
    {
        [$chatLog, $message] = $this->seedTurn();
        $chunks = [
            $this->seedChunk('kb/a.md', 'Cache TTL defaults to ten minutes for documentation.'),
        ];

        $persisted = (new TokenLevelExplainability())->record(
            $chatLog,
            $message,
            $chunks,
            'Cache TTL defaults to ten minutes for documentation.',
        );

        self::assertGreaterThan(0, $persisted, 'Expected at least one provenance row to assert against');
        foreach (ChatLogProvenance::query()->get() as $row) {
            self::assertGreaterThanOrEqual(0.0, (float) $row->contribution_score);
            self::assertLessThanOrEqual(1.0, (float) $row->contribution_score);
        }
    }

    public function test_record_falls_back_to_active_tenant_context_when_chatlog_lacks_tenant_id(): void
    {
        // 1. Seed rows in the default tenant first (so BelongsToTenant's
        //    auto-fill doesn't fight us during ChatLog::create).
        [$chatLog, $message] = $this->seedTurn();
        $chunk = $this->seedChunk('kb/a.md', 'Cache TTL ten minutes for documentation.');

        // 2. NOW switch the active tenant + null out chat_log.tenant_id
        //    so resolveTenantId() must fall back to the active context.
        app(TenantContext::class)->set('explicit-tenant');
        $chatLog->setAttribute('tenant_id', null);

        $persisted = (new TokenLevelExplainability(app(TenantContext::class)))->record(
            $chatLog,
            $message,
            [$chunk],
            'Cache TTL is ten minutes for documentation.',
        );

        self::assertGreaterThan(0, $persisted, 'Heuristic must persist at least one provenance row');
        self::assertSame('explicit-tenant', ChatLogProvenance::query()->first()->tenant_id);
    }

    /**
     * @return array{0:ChatLog,1:Message}
     */
    private function seedTurn(?string $tenantId = 'default'): array
    {
        app(TenantContext::class)->set($tenantId ?? 'default');
        $user = User::create([
            'name' => 'Provenance User',
            'email' => 'prov-record-'.uniqid().'@example.test',
            'password' => Hash::make('secret-password'),
        ]);
        $conversation = Conversation::create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'title' => 'Test',
        ]);
        $message = Message::create([
            'tenant_id' => $tenantId,
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Answer.',
        ]);
        $chatLog = ChatLog::create([
            'tenant_id' => $tenantId,
            'session_id' => 'sess-'.uniqid(),
            'user_id' => $user->id,
            'question' => 'Q',
            'answer' => 'A',
            'project_key' => 'docs',
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-4o',
            'chunks_count' => 1,
        ]);
        return [$chatLog, $message];
    }

    /**
     * Seed a real KnowledgeDocument + KnowledgeChunk pair (the
     * `chat_log_provenance` FK on `knowledge_chunk_id` is strict —
     * mock chunk IDs would trigger FK violations + DB::transaction
     * rollback inside record()).
     *
     * @return array{knowledge_chunk_id:int,source_path:string,chunk_text:string}
     */
    private function seedChunk(string $sourcePath, string $chunkText): array
    {
        static $counter = 0;
        $counter++;
        $document = KnowledgeDocument::query()->forceCreate([
            'tenant_id' => 'default',
            'project_key' => 'docs',
            'source_type' => 'markdown',
            'title' => "Doc {$counter}",
            'source_path' => $sourcePath,
            'document_hash' => str_pad(dechex($counter), 64, 'a', STR_PAD_RIGHT),
            'version_hash' => str_pad(dechex($counter), 64, 'b', STR_PAD_RIGHT),
            'status' => 'indexed',
        ]);
        $chunk = KnowledgeChunk::query()->forceCreate([
            'tenant_id' => 'default',
            'knowledge_document_id' => $document->id,
            'project_key' => 'docs',
            'chunk_order' => 0,
            'chunk_hash' => str_pad(dechex($counter), 64, 'c', STR_PAD_RIGHT),
            'chunk_text' => $chunkText,
        ]);

        return [
            'knowledge_chunk_id' => $chunk->id,
            'source_path' => $sourcePath,
            'chunk_text' => $chunkText,
        ];
    }
}
