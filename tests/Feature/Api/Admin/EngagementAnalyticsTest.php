<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Models\KbCanonicalHealthSnapshot;
use App\Models\KbContributionEvent;
use App\Models\KbEngagementSnapshot;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v8.15/W4 — per-user dashboard (/api/me/dashboard) + admin engagement trend
 * series (/api/admin/engagement/series).
 */
final class EngagementAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->reset();
        $this->seed(RbacSeeder::class);
    }

    private function makeUser(string $role = ''): User
    {
        $u = User::create(['name' => 'U', 'email' => 'u-'.uniqid().'@demo.local', 'password' => Hash::make('secret123')]);
        if ($role !== '') {
            $u->assignRole($role);
        }

        return $u;
    }

    private function event(int $userId, string $eventType, ?int $docId = null): void
    {
        KbContributionEvent::create([
            'tenant_id' => 'default', 'user_id' => $userId, 'document_id' => $docId,
            'project_key' => 'eng', 'event' => $eventType,
            'weight' => KbContributionEvent::WEIGHTS[$eventType] ?? 1, 'created_at' => now(),
        ]);
    }

    public function test_user_dashboard_aggregates_my_activity_and_rank(): void
    {
        $me = $this->makeUser();
        $other = $this->makeUser();

        // A doc I authored that now needs review (high debt score).
        $doc = KnowledgeDocument::create([
            'project_key' => 'eng', 'source_path' => 'd.md', 'source_type' => 'markdown',
            'title' => 'My Doc', 'mime_type' => 'text/markdown', 'language' => 'it',
            'access_scope' => 'internal', 'status' => 'active',
            'document_hash' => hash('sha256', 'd'), 'version_hash' => hash('sha256', 'd'),
            'metadata' => [], 'indexed_at' => now(),
        ]);
        KbCanonicalHealthSnapshot::create([
            'tenant_id' => 'default', 'knowledge_document_id' => $doc->id, 'project_key' => 'eng',
            'doc_slug' => null, 'health_score' => 88, 'factors' => [], 'computed_at' => now(),
        ]);

        $this->event($me->id, 'created', $doc->id);
        $this->event($me->id, 'promoted', $doc->id);  // me = 13 pts
        $this->event($other->id, 'created');           // other = 5 pts → I'm rank 1

        $res = $this->actingAs($me)->getJson('/api/me/dashboard');
        $res->assertOk();
        $d = $res->json('dashboard');
        $this->assertSame(1, $d['rank']);
        $this->assertSame(1, $d['authored_docs']);
        $this->assertGreaterThanOrEqual(1, count($d['docs_needing_review']));
        $this->assertSame('My Doc', $d['docs_needing_review'][0]['title']);
    }

    public function test_user_dashboard_requires_auth(): void
    {
        $this->getJson('/api/me/dashboard')->assertUnauthorized();
    }

    public function test_tied_top_contributors_share_rank_one(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        // Equal top scores → both rank 1 (ties share a rank).
        $this->event($a->id, 'created');   // 5
        $this->event($b->id, 'created');   // 5

        $this->assertSame(1, $this->actingAs($a)->getJson('/api/me/dashboard')->json('dashboard.rank'));
        $this->assertSame(1, $this->actingAs($b)->getJson('/api/me/dashboard')->json('dashboard.rank'));
    }

    public function test_mcp_summary_tool_response_includes_trend(): void
    {
        KbEngagementSnapshot::create([
            'tenant_id' => 'default', 'snapshot_date' => now()->toDateString(),
            'metrics' => ['contributors' => 2, 'new_docs' => 1, 'answers' => 0, 'avg_debt_score' => 30.0],
            'computed_at' => now(),
        ]);

        // R27/R44 — the additive `trend` key on the MCP tool is exercised at its layer.
        $response = (new \App\Mcp\Tools\KbEngagementSummaryTool())->handle(
            new \Laravel\Mcp\Request(['days' => 7]),
            app(\App\Services\Engagement\EngagementMetricsService::class),
            app(TenantContext::class),
        );
        $payload = json_decode((string) $response->content(), true);
        $this->assertArrayHasKey('trend', $payload);
        $this->assertIsArray($payload['trend']);
        $this->assertSame(2, $payload['trend'][0]['contributors']);
    }

    public function test_admin_engagement_series_returns_ordered_history(): void
    {
        // Two snapshots; the endpoint returns them oldest→newest (R16 strict order).
        KbEngagementSnapshot::create([
            'tenant_id' => 'default', 'snapshot_date' => now()->subDays(7)->toDateString(),
            'metrics' => ['contributors' => 1, 'new_docs' => 2, 'answers' => 3, 'avg_debt_score' => 40.0],
            'computed_at' => now()->subDays(7),
        ]);
        KbEngagementSnapshot::create([
            'tenant_id' => 'default', 'snapshot_date' => now()->toDateString(),
            'metrics' => ['contributors' => 4, 'new_docs' => 5, 'answers' => 6, 'avg_debt_score' => 38.0],
            'computed_at' => now(),
        ]);

        $admin = $this->makeUser('admin');
        $res = $this->actingAs($admin)->getJson('/api/admin/engagement/series');
        $res->assertOk();
        $series = $res->json('series');
        $this->assertCount(2, $series);
        $this->assertSame(1, $series[0]['contributors']);   // oldest first
        $this->assertSame(4, $series[1]['contributors']);    // newest last
    }
}
