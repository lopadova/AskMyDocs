<?php

namespace Tests\Feature\Admin;

use App\Models\ChatLog;
use App\Models\Conversation;
use App\Models\EmbeddingCache;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Message;
use App\Models\User;
use App\Services\Admin\AdminMetricsService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminMetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_kpi_overview_aggregates_docs_chunks_chats_within_the_window(): void
    {
        $this->seedDoc('hr-portal', 'policy.md', canonical: true);
        $this->seedDoc('hr-portal', 'handbook.md', canonical: false);
        $this->seedDoc('engineering', 'runbook.md', canonical: true);

        $this->seedChunk(1);
        $this->seedChunk(2);
        $this->seedChunk(3);

        $this->seedChatLog(latency: 100, projectKey: 'hr-portal', at: Carbon::now()->subHour());
        $this->seedChatLog(latency: 200, projectKey: 'hr-portal', at: Carbon::now()->subHour());
        $this->seedChatLog(latency: 300, projectKey: 'engineering', at: Carbon::now()->subHour());
        // Outside the 7-day window — excluded.
        $this->seedChatLog(latency: 999, projectKey: 'hr-portal', at: Carbon::now()->subDays(30));

        $svc = new AdminMetricsService;
        $overview = $svc->kpiOverview(null, 7);

        $this->assertSame(3, $overview['total_docs']);
        $this->assertSame(3, $overview['total_chunks']);
        $this->assertSame(3, $overview['total_chats']);
        $this->assertSame(200, $overview['avg_latency_ms']);
        $this->assertSame(66.7, $overview['canonical_coverage_pct']);
    }

    public function test_kpi_overview_filters_by_project(): void
    {
        $this->seedDoc('hr-portal', 'a.md');
        $this->seedDoc('engineering', 'b.md');
        $this->seedChatLog(latency: 100, projectKey: 'hr-portal', at: Carbon::now()->subHour());
        $this->seedChatLog(latency: 100, projectKey: 'engineering', at: Carbon::now()->subHour());

        $svc = new AdminMetricsService;

        $hr = $svc->kpiOverview('hr-portal', 7);
        $this->assertSame(1, $hr['total_docs']);
        $this->assertSame(1, $hr['total_chats']);

        $eng = $svc->kpiOverview('engineering', 7);
        $this->assertSame(1, $eng['total_docs']);
        $this->assertSame(1, $eng['total_chats']);
    }

    public function test_kpi_overview_hides_soft_deleted_documents(): void
    {
        $live = $this->seedDoc('hr-portal', 'live.md');
        $trashed = $this->seedDoc('hr-portal', 'trashed.md');
        $trashed->delete();

        $svc = new AdminMetricsService;
        $overview = $svc->kpiOverview('hr-portal', 7);

        $this->assertSame(1, $overview['total_docs']);
        $this->assertSame($live->id, $live->fresh()->id);
    }

    public function test_chat_volume_buckets_by_day_and_includes_all_rows(): void
    {
        $today = Carbon::now();
        $this->seedChatLog(at: $today);
        $this->seedChatLog(at: $today);
        $this->seedChatLog(at: $today->copy()->subDay());

        $svc = new AdminMetricsService;
        $buckets = $svc->chatVolume(null, 7);

        $this->assertGreaterThanOrEqual(1, count($buckets));
        $total = 0;
        foreach ($buckets as $bucket) {
            $total += $bucket['count'];
            $this->assertArrayHasKey('date', $bucket);
            $this->assertArrayHasKey('count', $bucket);
        }
        $this->assertSame(3, $total);
    }

    public function test_token_burn_groups_by_provider(): void
    {
        $this->seedChatLog(provider: 'openai', promptTokens: 10, completionTokens: 20, totalTokens: 30, at: Carbon::now());
        $this->seedChatLog(provider: 'openai', promptTokens: 5, completionTokens: 7, totalTokens: 12, at: Carbon::now());
        $this->seedChatLog(provider: 'anthropic', promptTokens: 100, completionTokens: 200, totalTokens: 300, at: Carbon::now());

        $svc = new AdminMetricsService;
        $rows = $svc->tokenBurn(null, 7);

        $this->assertCount(2, $rows);
        $byProvider = collect($rows)->keyBy('provider');
        $this->assertSame(15, $byProvider['openai']['prompt_tokens']);
        $this->assertSame(27, $byProvider['openai']['completion_tokens']);
        $this->assertSame(42, $byProvider['openai']['total_tokens']);
        $this->assertSame(300, $byProvider['anthropic']['total_tokens']);
    }

    public function test_rating_distribution_counts_assistant_messages_by_rating(): void
    {
        $user = $this->makeUser();
        $convo = Conversation::create([
            'user_id' => $user->id,
            'title' => 'test',
            'project_key' => 'hr-portal',
        ]);

        Message::create([
            'conversation_id' => $convo->id,
            'role' => 'assistant',
            'content' => 'hi',
            'rating' => 'positive',
            'created_at' => Carbon::now(),
        ]);
        Message::create([
            'conversation_id' => $convo->id,
            'role' => 'assistant',
            'content' => 'hi',
            'rating' => 'negative',
            'created_at' => Carbon::now(),
        ]);
        Message::create([
            'conversation_id' => $convo->id,
            'role' => 'assistant',
            'content' => 'hi',
            'rating' => null,
            'created_at' => Carbon::now(),
        ]);
        // User messages must NOT count as assistant rated.
        Message::create([
            'conversation_id' => $convo->id,
            'role' => 'user',
            'content' => 'hi',
            'rating' => 'positive',
            'created_at' => Carbon::now(),
        ]);

        $svc = new AdminMetricsService;
        $dist = $svc->ratingDistribution(null, 7);

        $this->assertSame(1, $dist['positive']);
        $this->assertSame(1, $dist['negative']);
        $this->assertSame(1, $dist['unrated']);
        $this->assertSame(3, $dist['total']);
    }

    public function test_top_projects_orders_by_chat_count_descending(): void
    {
        $this->seedChatLog(projectKey: 'hr-portal', at: Carbon::now());
        $this->seedChatLog(projectKey: 'hr-portal', at: Carbon::now());
        $this->seedChatLog(projectKey: 'engineering', at: Carbon::now());
        $this->seedChatLog(projectKey: null, at: Carbon::now()); // excluded

        $svc = new AdminMetricsService;
        $rows = $svc->topProjects(5);

        $this->assertSame('hr-portal', $rows[0]['project_key']);
        $this->assertSame(2, $rows[0]['count']);
        $this->assertSame('engineering', $rows[1]['project_key']);
        $this->assertSame(1, $rows[1]['count']);
    }

    public function test_activity_feed_merges_chat_and_audit_and_limits(): void
    {
        $user = $this->makeUser();
        $this->seedChatLog(userId: $user->id, projectKey: 'hr-portal', at: Carbon::now());

        DB::table('kb_canonical_audit')->insert([
            'project_key' => 'hr-portal',
            'event_type' => 'promoted',
            'actor' => 'system',
            'slug' => 'remote-work',
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);

        $svc = new AdminMetricsService;
        $feed = $svc->activityFeed(10);

        $this->assertCount(2, $feed);
        $sources = array_column($feed, 'source');
        sort($sources);
        $this->assertSame(['audit', 'chat'], $sources);
    }

    public function test_activity_feed_is_resilient_when_audit_table_is_empty(): void
    {
        $this->seedChatLog(at: Carbon::now());

        $svc = new AdminMetricsService;
        $feed = $svc->activityFeed(5);

        $this->assertCount(1, $feed);
        $this->assertSame('chat', $feed[0]['source']);
    }

    public function test_cache_hit_rate_ratios_recently_used_cache_entries(): void
    {
        EmbeddingCache::create([
            'text_hash' => hash('sha256', 'a'),
            'provider' => 'openai',
            'model' => 'text-embedding-3-small',
            'embedding' => '[0.1,0.2]',
            'created_at' => Carbon::now()->subDays(30),
            'last_used_at' => Carbon::now()->subHour(),
        ]);
        EmbeddingCache::create([
            'text_hash' => hash('sha256', 'b'),
            'provider' => 'openai',
            'model' => 'text-embedding-3-small',
            'embedding' => '[0.3,0.4]',
            'created_at' => Carbon::now()->subDays(30),
            'last_used_at' => Carbon::now()->subDays(60),
        ]);

        $svc = new AdminMetricsService;
        $overview = $svc->kpiOverview(null, 7);

        $this->assertSame(50.0, $overview['cache_hit_rate']);
    }

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Admin User',
            'email' => 'admin-metrics-'.uniqid().'@test.local',
            'password' => Hash::make('secret123'),
        ]);
    }

    private function seedDoc(string $projectKey, string $sourcePath, bool $canonical = false): KnowledgeDocument
    {
        return KnowledgeDocument::create([
            'project_key' => $projectKey,
            'source_type' => 'markdown',
            'title' => basename($sourcePath),
            'source_path' => $sourcePath,
            'document_hash' => hash('sha256', $sourcePath),
            'version_hash' => hash('sha256', $sourcePath.':v1'),
            'status' => 'indexed',
            'is_canonical' => $canonical,
            'canonical_status' => $canonical ? 'accepted' : null,
            'canonical_type' => $canonical ? 'policy' : null,
            'slug' => $canonical ? 'slug-'.basename($sourcePath, '.md') : null,
        ]);
    }

    private function seedChunk(int $docId): void
    {
        $doc = KnowledgeDocument::withoutGlobalScopes()->find($docId);
        if ($doc === null) {
            return;
        }
        KnowledgeChunk::create([
            'knowledge_document_id' => $docId,
            'project_key' => $doc->project_key,
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', 'chunk'.$docId),
            'heading_path' => '#',
            'chunk_text' => 'Lorem ipsum '.$docId,
            'metadata' => [],
        ]);
    }

    private function seedChatLog(
        ?string $projectKey = null,
        int $latency = 100,
        string $provider = 'openai',
        string $model = 'gpt-4o',
        int $promptTokens = 10,
        int $completionTokens = 20,
        int $totalTokens = 30,
        ?int $userId = null,
        ?Carbon $at = null,
    ): void {
        ChatLog::create([
            'session_id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $userId,
            'question' => 'q',
            'answer' => 'a',
            'project_key' => $projectKey,
            'ai_provider' => $provider,
            'ai_model' => $model,
            'chunks_count' => 1,
            'sources' => [],
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
            'latency_ms' => $latency,
            'created_at' => ($at ?? Carbon::now())->toDateTimeString(),
        ]);
    }
}
