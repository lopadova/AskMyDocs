<?php

namespace Tests\Feature\Api;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Http\Controllers\Api\KbPromotionController;
use App\Jobs\IngestDocumentJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class KbPromotionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('kb');
        // Register routes without auth middleware for isolated testing.
        Route::post('/api/kb/promotion/suggest', [KbPromotionController::class, 'suggest']);
        Route::post('/api/kb/promotion/candidates', [KbPromotionController::class, 'candidates']);
        Route::post('/api/kb/promotion/promote', [KbPromotionController::class, 'promote']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // -------------------------------------------------------------
    // /candidates — validate only, writes nothing
    // -------------------------------------------------------------

    public function test_candidates_returns_valid_true_for_well_formed_markdown(): void
    {
        $response = $this->postJson('/api/kb/promotion/candidates', [
            'markdown' => $this->validDecisionMarkdown('dec-x'),
        ]);

        $response->assertOk()
            ->assertJson([
                'valid' => true,
                'parsed' => [
                    'slug' => 'dec-x',
                    'type' => 'decision',
                    'status' => 'accepted',
                ],
            ]);
    }

    public function test_candidates_returns_422_for_markdown_without_frontmatter(): void
    {
        $response = $this->postJson('/api/kb/promotion/candidates', [
            'markdown' => "# Just a heading\n\nNo frontmatter.",
        ]);

        $response->assertStatus(422)
            ->assertJson(['valid' => false])
            ->assertJsonPath('errors.frontmatter.0', 'No YAML frontmatter block detected at the top of the document.');
    }

    public function test_candidates_returns_422_for_missing_slug(): void
    {
        $response = $this->postJson('/api/kb/promotion/candidates', [
            'markdown' => "---\ntype: decision\nstatus: accepted\n---\n\n# Body",
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('valid', false)
            ->assertJsonStructure(['errors' => ['slug']]);
    }

    public function test_candidates_validates_request_body(): void
    {
        $this->postJson('/api/kb/promotion/candidates', [])
            ->assertStatus(422);
    }

    // -------------------------------------------------------------
    // /promote — writes markdown + dispatches ingest
    // -------------------------------------------------------------

    public function test_promote_writes_file_and_dispatches_ingest(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/kb/promotion/promote', [
            'project_key' => 'acme',
            'markdown' => $this->validDecisionMarkdown('dec-cache-v2'),
        ]);

        $response->assertStatus(202)
            ->assertJson([
                'status' => 'accepted',
                'path' => 'decisions/dec-cache-v2.md',
                'slug' => 'dec-cache-v2',
                'doc_id' => 'DEC-2026-0001',
            ]);

        Storage::disk('kb')->assertExists('decisions/dec-cache-v2.md');
        Queue::assertPushed(IngestDocumentJob::class, fn ($job) => $job->relativePath === 'decisions/dec-cache-v2.md');
    }

    public function test_promote_rejects_invalid_frontmatter_with_422(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/kb/promotion/promote', [
            'project_key' => 'acme',
            'markdown' => "---\ntype: decision\nstatus: accepted\n---\n\n# No slug",
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', 'invalid_frontmatter');

        Queue::assertNotPushed(IngestDocumentJob::class);
        Storage::disk('kb')->assertMissing('decisions/no-slug.md');
    }

    public function test_promote_returns_503_when_promotion_disabled(): void
    {
        config()->set('kb.promotion.enabled', false);

        $response = $this->postJson('/api/kb/promotion/promote', [
            'project_key' => 'acme',
            'markdown' => $this->validDecisionMarkdown('dec-x'),
        ]);

        $response->assertStatus(503)->assertJsonPath('error', 'promotion_disabled');
    }

    public function test_promote_validates_required_project_key(): void
    {
        $this->postJson('/api/kb/promotion/promote', [
            'markdown' => $this->validDecisionMarkdown('dec-x'),
        ])->assertStatus(422);
    }

    // -------------------------------------------------------------
    // /suggest — LLM extracts candidates
    // -------------------------------------------------------------

    public function test_suggest_proxies_to_promotion_service(): void
    {
        $this->mockAiManagerReturning(json_encode([
            'candidates' => [
                ['type' => 'decision', 'slug_proposal' => 'dec-a', 'title_proposal' => 'A', 'reason' => 'r', 'related' => []],
            ],
        ]));

        $response = $this->postJson('/api/kb/promotion/suggest', [
            'transcript' => 'We decided to A.',
            'project_key' => 'acme',
        ]);

        $response->assertOk()
            ->assertJsonCount(1, 'candidates')
            ->assertJsonPath('candidates.0.type', 'decision');
    }

    public function test_suggest_validates_request_body(): void
    {
        $this->postJson('/api/kb/promotion/suggest', [])
            ->assertStatus(422);
    }

    public function test_suggest_returns_503_when_promotion_disabled(): void
    {
        config()->set('kb.promotion.enabled', false);

        $this->postJson('/api/kb/promotion/suggest', ['transcript' => 't'])
            ->assertStatus(503)
            ->assertJsonPath('error', 'promotion_disabled');
    }

    // -------------------------------------------------------------
    // helpers
    // -------------------------------------------------------------

    private function validDecisionMarkdown(string $slug): string
    {
        return <<<MD
---
id: DEC-2026-0001
slug: {$slug}
type: decision
status: accepted
---

# Decision {$slug}

Body.
MD;
    }

    private function mockAiManagerReturning(string $content): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chat')->andReturn(new AiResponse(
            content: $content,
            provider: 'openai',
            model: 'gpt-4o-mini',
        ));
        $this->app->instance(AiManager::class, $ai);
    }
}
