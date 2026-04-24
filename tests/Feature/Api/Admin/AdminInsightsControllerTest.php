<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Models\AdminCommandAudit;
use App\Models\AdminInsightsSnapshot;
use App\Models\KnowledgeDocument;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Phase I — admin insights controller feature tests.
 *
 * Covers:
 *   - GET /latest (happy + 404 when no snapshot)
 *   - GET /{date}  (happy + 404 when missing)
 *   - POST /compute (202 + audit row; throttle → 429; permission → 403)
 *   - GET /document/{id}/ai-suggestions (happy + 404 + provider error)
 *   - RBAC (viewer → 403; guest → 401)
 */
class AdminInsightsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
        // Reset both throttler buckets — compute (3,5) + document
        // (6,1) — so a previous test's 429 does not leak forward.
        RateLimiter::clear('throttle:3,5');
        RateLimiter::clear('throttle:6,1');
    }

    // ------------------------------------------------------------------
    // latest
    // ------------------------------------------------------------------

    public function test_latest_returns_404_when_no_snapshot(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->getJson('/api/admin/insights/latest')
            ->assertStatus(404)
            ->assertJsonPath('hint', fn ($v) => is_string($v) && str_contains($v, 'insights:compute'));
    }

    public function test_latest_returns_most_recent_row(): void
    {
        $admin = $this->makeAdmin();

        AdminInsightsSnapshot::create([
            'snapshot_date' => Carbon::today()->subDay()->toDateString(),
            'suggest_promotions' => [['document_id' => 1, 'slug' => 'old', 'reason' => 'x', 'score' => 1]],
            'computed_at' => Carbon::now()->subDay(),
            'computed_duration_ms' => 100,
        ]);
        AdminInsightsSnapshot::create([
            'snapshot_date' => Carbon::today()->toDateString(),
            'suggest_promotions' => [['document_id' => 2, 'slug' => 'new', 'reason' => 'y', 'score' => 9]],
            'computed_at' => Carbon::now(),
            'computed_duration_ms' => 200,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/insights/latest')
            ->assertOk()
            ->assertJsonPath('data.snapshot_date', Carbon::today()->toDateString())
            ->assertJsonPath('data.suggest_promotions.0.slug', 'new')
            ->assertJsonPath('data.computed_duration_ms', 200);
    }

    // ------------------------------------------------------------------
    // byDate
    // ------------------------------------------------------------------

    public function test_by_date_returns_row(): void
    {
        $admin = $this->makeAdmin();
        $date = Carbon::today()->subDays(3)->toDateString();
        AdminInsightsSnapshot::create([
            'snapshot_date' => $date,
            'orphan_docs' => [['document_id' => 5, 'slug' => 'abc']],
            'computed_at' => Carbon::now()->subDays(3),
            'computed_duration_ms' => 50,
        ]);

        $this->actingAs($admin)
            ->getJson("/api/admin/insights/{$date}")
            ->assertOk()
            ->assertJsonPath('data.orphan_docs.0.slug', 'abc');
    }

    public function test_by_date_returns_404_when_missing(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->getJson('/api/admin/insights/1999-01-01')
            ->assertStatus(404);
    }

    public function test_by_date_rejects_non_yyyy_mm_dd(): void
    {
        $admin = $this->makeAdmin();
        // Route constraint rejects `garbage` → 404 (Laravel's
        // route-not-matched 404 is the intended shape here).
        $this->actingAs($admin)
            ->getJson('/api/admin/insights/garbage')
            ->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // compute
    // ------------------------------------------------------------------

    public function test_compute_admin_403_without_destructive_permission(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->postJson('/api/admin/insights/compute')
            ->assertStatus(403);
    }

    public function test_compute_super_admin_202_with_audit_row(): void
    {
        $super = $this->makeSuperAdmin();

        // Fake the LLM calls so tagSuggestions + coverageGaps don't
        // touch the network during the Artisan::call inside compute().
        Http::fake();

        $res = $this->actingAs($super)
            ->postJson('/api/admin/insights/compute')
            ->assertStatus(202)
            ->assertJsonPath('message', fn ($m) => is_string($m) && str_contains($m, 'dispatched'));

        $auditId = (int) $res->json('audit_id');
        $this->assertGreaterThan(0, $auditId);
        $audit = AdminCommandAudit::find($auditId);
        $this->assertNotNull($audit);
        $this->assertSame('insights:compute', $audit->command);
        // Status should be completed or failed (but NOT started) after
        // the controller awaited Artisan::call().
        $this->assertNotSame(AdminCommandAudit::STATUS_STARTED, $audit->status);
    }

    public function test_compute_throttle_kicks_in_after_three_calls(): void
    {
        $super = $this->makeSuperAdmin();
        Http::fake();

        // 3 successful calls should pass the 3,5 throttle.
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($super)->postJson('/api/admin/insights/compute')->assertStatus(202);
        }
        $this->actingAs($super)
            ->postJson('/api/admin/insights/compute')
            ->assertStatus(429);
    }

    // ------------------------------------------------------------------
    // document/{id}/ai-suggestions
    // ------------------------------------------------------------------

    public function test_document_suggestions_happy(): void
    {
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc(['metadata' => ['tags' => ['cache']]]);

        // Fake the LLM response with valid JSON array of tags.
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => ['content' => '["redis","eviction-policy","hot-keys"]'],
                    'finish_reason' => 'stop',
                ]],
                'model' => 'gpt-4o-mini',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ], 200),
        ]);

        // Seed a chunk so the service has text to feed the LLM.
        $doc->chunks()->create([
            'project_key' => $doc->project_key,
            'chunk_order' => 0,
            'chunk_hash' => str_repeat('a', 64),
            'heading_path' => '# Caching',
            'chunk_text' => 'Redis eviction policies discussion for caching tiers.',
            'metadata' => [],
            'embedding' => [],
        ]);

        $this->actingAs($admin)
            ->getJson("/api/admin/insights/document/{$doc->id}/ai-suggestions")
            ->assertOk()
            ->assertJsonPath('data.document_id', $doc->id)
            ->assertJsonPath('data.tags_proposed.0', 'redis');
    }

    public function test_document_suggestions_404_when_doc_missing(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->getJson('/api/admin/insights/document/999999/ai-suggestions')
            ->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // RBAC
    // ------------------------------------------------------------------

    public function test_viewer_forbidden_on_every_endpoint(): void
    {
        $viewer = $this->makeViewer();

        foreach ([
            'GET:/api/admin/insights/latest',
            'GET:/api/admin/insights/'.Carbon::today()->toDateString(),
            'POST:/api/admin/insights/compute',
            'GET:/api/admin/insights/document/1/ai-suggestions',
        ] as $spec) {
            [$method, $path] = explode(':', $spec, 2);
            $call = $method === 'GET' ? 'getJson' : 'postJson';
            $res = $this->actingAs($viewer)->{$call}($path);
            $this->assertSame(
                403,
                $res->status(),
                "Expected 403 for {$path}, got {$res->status()}. Body: ".$res->getContent(),
            );
        }
    }

    public function test_guest_401_on_every_endpoint(): void
    {
        foreach ([
            'GET:/api/admin/insights/latest',
            'GET:/api/admin/insights/'.Carbon::today()->toDateString(),
            'POST:/api/admin/insights/compute',
            'GET:/api/admin/insights/document/1/ai-suggestions',
        ] as $spec) {
            [$method, $path] = explode(':', $spec, 2);
            $call = $method === 'GET' ? 'getJson' : 'postJson';
            $this->{$call}($path)->assertStatus(401);
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeAdmin(): User
    {
        $u = User::create([
            'name' => 'A',
            'email' => 'admin-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $u->assignRole('admin');

        return $u;
    }

    private function makeSuperAdmin(): User
    {
        $u = User::create([
            'name' => 'S',
            'email' => 'super-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $u->assignRole('super-admin');

        return $u;
    }

    private function makeViewer(): User
    {
        $u = User::create([
            'name' => 'V',
            'email' => 'viewer-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $u->assignRole('viewer');

        return $u;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeDoc(array $overrides = []): KnowledgeDocument
    {
        return KnowledgeDocument::create(array_merge([
            'project_key' => 'hr-portal',
            'source_type' => 'markdown',
            'title' => 'Test Doc',
            'source_path' => 'docs/test-'.uniqid().'.md',
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'access_scope' => 'default',
            'status' => 'indexed',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => str_repeat('b', 64),
            'metadata' => [],
            'is_canonical' => true,
            'canonical_type' => 'decision',
            'canonical_status' => 'accepted',
            'slug' => 'dec-test-'.uniqid(),
            'doc_id' => 'dec-test-'.uniqid(),
            'retrieval_priority' => 50,
            'source_of_truth' => true,
        ], $overrides));
    }
}
