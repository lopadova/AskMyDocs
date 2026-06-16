<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Models\KbContributionEvent;
use App\Models\KbEngagementSnapshot;
use App\Models\User;
use App\Services\Engagement\ContributionRecorder;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v8.15/W1 — KB engagement analytics: contribution recorder + daily snapshot
 * compute + admin API + leaderboard.
 *
 * Coverage: snapshot computed per tenant (R30 isolation), summary returns the
 * snapshot then falls back to live, leaderboard ranked desc (R16 strict-monotonic
 * fixture), recorder flag OFF and ON (R43), 403 non-admin / 401 guest.
 */
final class EngagementControllerTest extends TestCase
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
        Cache::flush();
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

    private function recordEvent(string $event, ?int $userId, array $extra = []): void
    {
        KbContributionEvent::create(array_merge([
            'tenant_id' => 'default',
            'user_id' => $userId,
            'document_id' => 1,
            'project_key' => 'eng',
            'event' => $event,
            'weight' => KbContributionEvent::WEIGHTS[$event] ?? 1,
            'created_at' => now(),
        ], $extra));
    }

    public function test_recorder_appends_event_when_enabled(): void
    {
        config()->set('askmydocs.engagement.enabled', true);
        app(ContributionRecorder::class)->record(KbContributionEvent::EVENT_CREATED, userId: 7, documentId: 3, projectKey: 'eng');

        $this->assertDatabaseHas('kb_contribution_events', [
            'event' => 'created',
            'user_id' => 7,
            'document_id' => 3,
            'weight' => KbContributionEvent::WEIGHTS['created'],
        ]);
    }

    public function test_recorder_noops_when_disabled(): void
    {
        config()->set('askmydocs.engagement.enabled', false);
        app(ContributionRecorder::class)->record(KbContributionEvent::EVENT_CREATED, userId: 7);

        $this->assertDatabaseCount('kb_contribution_events', 0);
    }

    public function test_compute_writes_one_snapshot_per_tenant_with_metrics(): void
    {
        $this->recordEvent('created', 7);
        $this->recordEvent('promoted', 7);
        $this->recordEvent('created', 8);

        $this->artisan('engagement:compute', ['--tenant' => 'default'])->assertExitCode(0);

        $snapshot = KbEngagementSnapshot::query()->forTenant('default')->first();
        $this->assertNotNull($snapshot);
        $metrics = $snapshot->metrics;
        $this->assertSame(2, $metrics['new_docs']);
        $this->assertSame(1, $metrics['promoted_docs']);
        $this->assertSame(2, $metrics['contributors']);
        $this->assertArrayHasKey('top_contributors', $metrics);
        $this->assertArrayHasKey('canonical_coverage_pct', $metrics);
    }

    public function test_summary_returns_snapshot_then_live_fallback(): void
    {
        $admin = $this->makeAdmin();

        // No snapshot yet → live source.
        $this->recordEvent('created', 7);
        $live = $this->actingAs($admin)->getJson('/api/admin/engagement/summary');
        $live->assertOk()->assertJsonPath('source', 'live');

        // After compute → snapshot source.
        $this->artisan('engagement:compute', ['--tenant' => 'default'])->assertExitCode(0);
        $snap = $this->actingAs($admin)->getJson('/api/admin/engagement/summary');
        $snap->assertOk()->assertJsonPath('source', 'snapshot');
    }

    public function test_leaderboard_ranked_desc_by_score(): void
    {
        $admin = $this->makeAdmin();
        // Strict-monotonic fixture: low scorer first so asc ordering would FAIL (R16).
        $this->recordEvent('answered', 5);                 // weight 1
        $this->recordEvent('promoted', 9);                 // weight 8
        $this->recordEvent('created', 9);                  // weight 5 → user 9 = 13

        $res = $this->actingAs($admin)->getJson('/api/admin/engagement/leaderboard?days=30');
        $res->assertOk();
        $board = $res->json('leaderboard');
        $this->assertSame(9, $board[0]['user_id']);
        // Strict-desc: the top entry's score must exceed the runner-up's.
        $this->assertTrue(
            $board[0]['score'] > $board[1]['score'],
            'leaderboard must be ordered strictly descending by score',
        );
    }

    public function test_compute_is_tenant_isolated(): void
    {
        $this->recordEvent('created', 7, ['tenant_id' => 'tenant-a']);
        $this->recordEvent('created', 8, ['tenant_id' => 'tenant-b']);
        $this->recordEvent('created', 8, ['tenant_id' => 'tenant-b']);

        $this->artisan('engagement:compute', ['--tenant' => 'tenant-a'])->assertExitCode(0);
        $this->artisan('engagement:compute', ['--tenant' => 'tenant-b'])->assertExitCode(0);

        $a = KbEngagementSnapshot::query()->forTenant('tenant-a')->first();
        $b = KbEngagementSnapshot::query()->forTenant('tenant-b')->first();
        $this->assertSame(1, $a->metrics['new_docs']);
        $this->assertSame(2, $b->metrics['new_docs']);
    }

    public function test_document_create_records_contribution_via_hook(): void
    {
        config()->set('askmydocs.engagement.enabled', true);

        \App\Models\KnowledgeDocument::create([
            'project_key' => 'eng',
            'source_path' => 'docs/hooked.md',
            'source_type' => 'markdown',
            'title' => 'Hooked',
            'mime_type' => 'text/markdown',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', 'hooked'),
            'version_hash' => hash('sha256', 'hooked'),
            'metadata' => [],
            'indexed_at' => now(),
        ]);

        // The KnowledgeDocument::created → afterCommit hook records a 'created'
        // contribution event (independent of notification recipients).
        $this->assertDatabaseHas('kb_contribution_events', [
            'event' => 'created',
            'project_key' => 'eng',
        ]);
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/admin/engagement/summary')->assertUnauthorized();
    }

    public function test_non_admin_gets_403(): void
    {
        $viewer = User::create([
            'name' => 'Viewer',
            'email' => 'viewer-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $viewer->assignRole('viewer');

        $this->actingAs($viewer)->getJson('/api/admin/engagement/summary')->assertForbidden();
    }
}
