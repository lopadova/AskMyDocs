<?php

declare(strict_types=1);

namespace Tests\Feature\Engagement;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Models\KbContributionEvent;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\Engagement\GamificationInsightsService;
use App\Services\Engagement\GamificationQualityMetricsService;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

/**
 * v8.18/W4 — AI gamification insights core: curation-quality metrics + the
 * compute→narrate→persist→read pipeline, tested in BOTH states (R43) and with
 * the AI path mocked (no real LLM).
 */
final class GamificationInsightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->reset();
        $this->seed(RbacSeeder::class);
        config()->set('kb.gamification.enabled', true);
        config()->set('kb.gamification.ai.enabled', false); // deterministic path by default
    }

    private function user(): User
    {
        return User::create(['name' => 'U', 'email' => 'u-'.uniqid().'@demo.local', 'password' => Hash::make('secret123')]);
    }

    private function doc(string $project, bool $canonical, string $status = 'accepted', ?string $evidence = 'primary'): KnowledgeDocument
    {
        return KnowledgeDocument::create([
            'tenant_id' => 'default',
            'project_key' => $project,
            'source_type' => 'markdown',
            'title' => 'Doc '.uniqid(),
            'source_path' => 'kb/'.uniqid().'.md',
            'document_hash' => hash('sha256', uniqid('', true)),
            'version_hash' => hash('sha256', uniqid('', true)),
            'status' => 'active',
            'is_canonical' => $canonical,
            'canonical_status' => $canonical ? $status : null,
            'canonical_type' => $canonical ? 'decision' : null,
            'doc_id' => $canonical ? 'dec-'.uniqid() : null,
            'slug' => $canonical ? 'dec-'.uniqid() : null,
            'evidence_tier' => $evidence,
            'retrieval_priority' => 60,
            'frontmatter_json' => $canonical ? ['slug' => 'x', 'type' => 'decision'] : null,
        ]);
    }

    private function event(int $userId, string $event, string $project, ?int $docId): void
    {
        KbContributionEvent::create([
            'tenant_id' => 'default', 'user_id' => $userId, 'document_id' => $docId,
            'project_key' => $project, 'event' => $event,
            'weight' => KbContributionEvent::WEIGHTS[$event] ?? 1, 'created_at' => now(),
        ]);
    }

    public function test_user_quality_metrics_reflect_authored_canonical_docs(): void
    {
        $u = $this->user();
        $d1 = $this->doc('eng', true, 'accepted');
        $d2 = $this->doc('eng', true, 'draft');   // canonical but not accepted
        $this->event($u->id, 'created', 'eng', $d1->id);
        $this->event($u->id, 'created', 'eng', $d2->id);
        $this->event($u->id, 'cited', 'eng', $d1->id);

        $q = app(GamificationQualityMetricsService::class)->userQuality($u->id);

        $this->assertSame(2, $q['authored_docs']);
        $this->assertSame(2, $q['canonical_docs']);
        $this->assertSame(1, $q['accepted_docs']);
        $this->assertEqualsWithDelta(0.5, $q['canonicalization_rate'], 1e-9); // 1 accepted / 2 canonical
        $this->assertSame(1, $q['citation_usefulness']);
        $this->assertEqualsWithDelta(1.0, $q['frontmatter_completeness_rate'], 1e-9); // both canonical have frontmatter
    }

    public function test_recompute_persists_all_three_scopes_with_deterministic_copy(): void
    {
        $u = $this->user();
        $d = $this->doc('eng', true);
        $this->event($u->id, 'created', 'eng', $d->id);

        $result = app(GamificationInsightsService::class)->recomputeForTenant('2026-W25');

        $this->assertSame('2026-W25', $result['period']);
        $this->assertSame(1, $result['users']);
        $this->assertSame(1, $result['projects']);
        $this->assertSame(1, $result['tenant']);

        $this->assertDatabaseHas('kb_gamification_insights', [
            'tenant_id' => 'default', 'scope_type' => 'user', 'scope_id' => (string) $u->id, 'period_label' => '2026-W25', 'model' => null,
        ]);
        $this->assertDatabaseHas('kb_gamification_insights', [
            'scope_type' => 'project', 'scope_id' => 'eng',
        ]);
        $this->assertDatabaseHas('kb_gamification_insights', [
            'scope_type' => 'tenant', 'scope_id' => '',
        ]);

        // The user coaching card reads back with a deterministic narrative.
        $card = app(GamificationInsightsService::class)->forUser($u->id);
        $this->assertNotNull($card);
        $this->assertArrayHasKey('headline', $card['narrative']);
        $this->assertNull($card['model']);
    }

    public function test_recompute_is_idempotent_on_rerun(): void
    {
        $u = $this->user();
        $this->event($u->id, 'created', 'eng', $this->doc('eng', true)->id);

        app(GamificationInsightsService::class)->recomputeForTenant('2026-W25');
        app(GamificationInsightsService::class)->recomputeForTenant('2026-W25');

        $this->assertDatabaseCount('kb_gamification_insights', 3); // user + project + tenant, not duplicated
    }

    public function test_disabled_is_a_noop_and_reads_null(): void
    {
        config()->set('kb.gamification.enabled', false);
        $u = $this->user();
        $this->event($u->id, 'created', 'eng', $this->doc('eng', true)->id);

        $result = app(GamificationInsightsService::class)->recomputeForTenant('2026-W25');

        $this->assertSame(0, $result['users']);
        $this->assertDatabaseCount('kb_gamification_insights', 0);
        $this->assertNull(app(GamificationInsightsService::class)->forUser($u->id));
    }

    public function test_ai_path_persists_model_and_llm_narrative(): void
    {
        config()->set('kb.gamification.ai.enabled', true);
        config()->set('kb.gamification.ai.model', 'test/free-model');

        $u = $this->user();
        $this->event($u->id, 'created', 'eng', $this->doc('eng', true)->id);

        $json = json_encode([
            'narrative' => ['headline' => 'Great work!', 'strengths' => ['s'], 'growth' => ['g'], 'next_steps' => ['n'], 'summary' => 'sum'],
            'titles' => [['key' => 'cartographer', 'label' => 'Il Cartografo', 'icon' => '🗺️', 'reason' => 'links']],
        ]);

        $provider = Mockery::mock(\App\Ai\AiProviderInterface::class);
        $provider->shouldReceive('chat')->andReturn(new AiResponse(content: $json, provider: 'mock', model: 'mock'));
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('provider')->andReturn($provider);
        $this->app->instance(AiManager::class, $ai);
        // Rebind narrator + insights so they pick up the mocked AiManager.
        $this->app->forgetInstance(\App\Services\Engagement\GamificationNarratorService::class);
        $this->app->forgetInstance(GamificationInsightsService::class);

        app(GamificationInsightsService::class)->recomputeForTenant('2026-W25');

        $card = app(GamificationInsightsService::class)->forUser($u->id);
        $this->assertSame('test/free-model', $card['model']);
        $this->assertSame('Great work!', $card['narrative']['headline']);
        $this->assertSame('Il Cartografo', $card['titles'][0]['label']);
    }

    public function test_partial_llm_narrative_is_merged_over_the_deterministic_shape(): void
    {
        // A free model that returns a well-formed-JSON-but-INCOMPLETE narrative
        // (only a headline) must NOT persist a row missing strengths/growth/
        // next_steps/summary — the FE reads those arrays directly, so a missing
        // key would white-screen the card (R14). The narrator merges the LLM
        // narrative OVER the deterministic shape, so every required key survives.
        config()->set('kb.gamification.ai.enabled', true);
        config()->set('kb.gamification.ai.model', 'test/free-model');

        $u = $this->user();
        $this->event($u->id, 'created', 'eng', $this->doc('eng', true)->id);

        $json = json_encode(['narrative' => ['headline' => 'Only a headline']]); // no strengths/growth/...

        $provider = Mockery::mock(\App\Ai\AiProviderInterface::class);
        $provider->shouldReceive('chat')->andReturn(new AiResponse(content: $json, provider: 'mock', model: 'mock'));
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('provider')->andReturn($provider);
        $this->app->instance(AiManager::class, $ai);
        $this->app->forgetInstance(\App\Services\Engagement\GamificationNarratorService::class);
        $this->app->forgetInstance(GamificationInsightsService::class);

        app(GamificationInsightsService::class)->recomputeForTenant('2026-W25');

        $card = app(GamificationInsightsService::class)->forUser($u->id);
        $this->assertSame('Only a headline', $card['narrative']['headline']); // LLM value kept
        // Required keys still present (from the deterministic fallback merge).
        $this->assertIsArray($card['narrative']['strengths']);
        $this->assertIsArray($card['narrative']['growth']);
        $this->assertIsArray($card['narrative']['next_steps']);
        $this->assertArrayHasKey('summary', $card['narrative']);
    }

    public function test_narrate_command_runs_per_tenant(): void
    {
        $u = $this->user();
        $this->event($u->id, 'created', 'eng', $this->doc('eng', true)->id);

        $this->artisan('gamification:narrate', ['--tenant' => 'default', '--period' => '2026-W25'])->assertExitCode(0);

        $this->assertDatabaseHas('kb_gamification_insights', ['scope_type' => 'user', 'scope_id' => (string) $u->id]);
    }
}
