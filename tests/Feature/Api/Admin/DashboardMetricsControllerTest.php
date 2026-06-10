<?php

namespace Tests\Feature\Api\Admin;

use App\Models\ChatLog;
use App\Models\KnowledgeDocument;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DashboardMetricsControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Mirror the Auth controller tests: mount routes/api.php under
     * `api + web` middleware stack so Sanctum stateful + Spatie
     * `role:` alias both resolve correctly. ResolveTenant is added
     * explicitly (production prepends it in bootstrap/app.php, which
     * Testbench does not execute) so X-Tenant-Id drives the active
     * tenant in the cache-isolation test below.
     */
    protected function defineRoutes($router): void
    {
        $router->middleware(['api', \App\Http\Middleware\ResolveTenant::class])
            ->prefix('api')
            ->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
    }

    public function test_overview_allows_admin(): void
    {
        $admin = $this->makeAdmin();
        $this->seedSomeData();

        $this->actingAs($admin)
            ->getJson('/api/admin/metrics/overview?days=7')
            ->assertOk()
            ->assertJsonPath('days', 7)
            ->assertJsonStructure([
                'project',
                'days',
                'overview' => [
                    'total_docs',
                    'total_chunks',
                    'total_chats',
                    'avg_latency_ms',
                    'failed_jobs',
                    'pending_jobs',
                    'cache_hit_rate',
                    'canonical_coverage_pct',
                    'storage_used_mb',
                ],
            ]);
    }

    public function test_series_allows_admin(): void
    {
        $admin = $this->makeAdmin();
        $this->seedSomeData();

        $this->actingAs($admin)
            ->getJson('/api/admin/metrics/series?days=14')
            ->assertOk()
            ->assertJsonPath('days', 14)
            ->assertJsonStructure([
                'chat_volume',
                'token_burn',
                'rating_distribution' => ['positive', 'negative', 'unrated', 'total'],
                'top_projects',
                'activity_feed',
            ]);
    }

    public function test_health_allows_admin(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->getJson('/api/admin/metrics/health')
            ->assertOk()
            ->assertJsonStructure([
                'db_ok',
                'pgvector_ok',
                'queue_ok',
                'kb_disk_ok',
                'embedding_provider_ok',
                'chat_provider_ok',
                'checked_at',
            ]);
    }

    public function test_viewer_gets_403_on_every_endpoint(): void
    {
        $viewer = User::create([
            'name' => 'Viewer',
            'email' => 'viewer@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $viewer->assignRole('viewer');

        $this->actingAs($viewer)
            ->getJson('/api/admin/metrics/overview')
            ->assertStatus(403);

        $this->actingAs($viewer)
            ->getJson('/api/admin/metrics/series')
            ->assertStatus(403);

        $this->actingAs($viewer)
            ->getJson('/api/admin/metrics/health')
            ->assertStatus(403);
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/admin/metrics/overview')
            ->assertStatus(401);

        $this->getJson('/api/admin/metrics/series')
            ->assertStatus(401);

        $this->getJson('/api/admin/metrics/health')
            ->assertStatus(401);
    }

    public function test_overview_is_cached_for_30_seconds_per_project_days_tuple(): void
    {
        $admin = $this->makeAdmin();
        $this->seedSomeData();

        // First call populates the cache.
        $first = $this->actingAs($admin)
            ->getJson('/api/admin/metrics/overview?days=7')
            ->assertOk()
            ->json('overview');

        // Mutate the source data — a fresh aggregation would now report
        // one more chat log. If the cache is respected, the API still
        // returns the first snapshot.
        $before = $first['total_chats'];

        ChatLog::create([
            'session_id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $admin->id,
            'question' => 'q',
            'answer' => 'a',
            'project_key' => 'hr-portal',
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-4o',
            'chunks_count' => 0,
            'sources' => [],
            'prompt_tokens' => 1,
            'completion_tokens' => 1,
            'total_tokens' => 2,
            'latency_ms' => 50,
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);

        $second = $this->actingAs($admin)
            ->getJson('/api/admin/metrics/overview?days=7')
            ->assertOk()
            ->json('overview');

        $this->assertSame($before, $second['total_chats']);

        // Different `days` query yields a different cache key → fresh data.
        $differentKey = $this->actingAs($admin)
            ->getJson('/api/admin/metrics/overview?days=30')
            ->assertOk()
            ->json('overview');

        $this->assertSame($before + 1, $differentKey['total_chats']);
    }

    public function test_overview_cache_does_not_leak_across_tenants(): void
    {
        // R30 — the cache key MUST include the tenant: the same
        // (project, days) tuple requested from two tenants within the
        // 30s TTL would otherwise serve tenant A's numbers to tenant B.
        $super = User::create([
            'name' => 'Super',
            'email' => 'super-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $super->assignRole('super-admin');

        // R16 — strictly differentiating fixture: 1 chat in 'default',
        // 2 in 'acme', so a leaked cache CANNOT produce the expected
        // number by coincidence.
        $this->seedSomeData(); // 1 chat log in 'default'
        foreach (['one', 'two'] as $ignored) {
            ChatLog::create([
                'tenant_id' => 'acme',
                'session_id' => (string) \Illuminate\Support\Str::uuid(),
                'question' => 'q',
                'answer' => 'a',
                'project_key' => 'acme-kb',
                'ai_provider' => 'openai',
                'ai_model' => 'gpt-4o',
                'chunks_count' => 0,
                'sources' => [],
                'prompt_tokens' => 1,
                'completion_tokens' => 1,
                'total_tokens' => 2,
                'latency_ms' => 50,
                'created_at' => Carbon::now()->toDateTimeString(),
            ]);
        }

        // Prime the cache from the default tenant...
        $defaultChats = $this->actingAs($super)
            ->getJson('/api/admin/metrics/overview?days=7')
            ->assertOk()
            ->json('overview.total_chats');
        $this->assertSame(1, $defaultChats);

        // ...then the SAME request from 'acme' within the TTL must
        // aggregate acme's data, not replay the cached default payload.
        $acmeChats = $this->actingAs($super)
            ->withHeader('X-Tenant-Id', 'acme')
            ->getJson('/api/admin/metrics/overview?days=7')
            ->assertOk()
            ->json('overview.total_chats');
        $this->assertSame(2, $acmeChats);

        $acmeDocs = $this->actingAs($super)
            ->withHeader('X-Tenant-Id', 'acme')
            ->getJson('/api/admin/metrics/overview?days=7')
            ->assertOk()
            ->json('overview.total_docs');
        $this->assertSame(0, $acmeDocs, 'acme has no documents; a non-zero count means the default-tenant cache leaked');
    }

    public function test_days_parameter_is_clamped(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->getJson('/api/admin/metrics/overview?days=9999')
            ->assertOk()
            ->assertJsonPath('days', 90);

        $this->actingAs($admin)
            ->getJson('/api/admin/metrics/overview?days=0')
            ->assertOk()
            ->assertJsonPath('days', 1);
    }

    private function makeAdmin(): User
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function seedSomeData(): void
    {
        KnowledgeDocument::create([
            'project_key' => 'hr-portal',
            'source_type' => 'markdown',
            'title' => 'Policy',
            'source_path' => 'policies/remote.md',
            'document_hash' => hash('sha256', 'a'),
            'version_hash' => hash('sha256', 'a:v1'),
            'status' => 'indexed',
        ]);

        ChatLog::create([
            'session_id' => (string) \Illuminate\Support\Str::uuid(),
            'question' => 'q',
            'answer' => 'a',
            'project_key' => 'hr-portal',
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-4o',
            'chunks_count' => 0,
            'sources' => [],
            'prompt_tokens' => 10,
            'completion_tokens' => 20,
            'total_tokens' => 30,
            'latency_ms' => 100,
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
    }
}
