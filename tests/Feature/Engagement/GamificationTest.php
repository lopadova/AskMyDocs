<?php

declare(strict_types=1);

namespace Tests\Feature\Engagement;

use App\Models\KbContributionEvent;
use App\Models\User;
use App\Services\Engagement\GamificationService;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v8.15/W5 — opt-in gamification: badge awarding + the /api/me/badges surface,
 * tested in BOTH states (R43).
 */
final class GamificationTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->reset();
        $this->seed(RbacSeeder::class);
        Cache::flush();
    }

    private function user(): User
    {
        return User::create(['name' => 'U', 'email' => 'u-'.uniqid().'@demo.local', 'password' => Hash::make('secret123')]);
    }

    private function events(int $userId, string $event, int $n, ?int $docId = null): void
    {
        for ($i = 0; $i < $n; $i++) {
            KbContributionEvent::create([
                'tenant_id' => 'default', 'user_id' => $userId, 'document_id' => $docId,
                'project_key' => 'eng', 'event' => $event,
                'weight' => KbContributionEvent::WEIGHTS[$event] ?? 1, 'created_at' => now(),
            ]);
        }
    }

    public function test_disabled_awards_nothing_and_reports_disabled(): void
    {
        config()->set('kb.gamification.enabled', false);
        $u = $this->user();
        $this->events($u->id, 'created', 10);

        $this->assertSame([], app(GamificationService::class)->evaluate($u->id));
        $this->assertSame(['enabled' => false, 'badges' => []], app(GamificationService::class)->badgesFor($u->id));
        $this->assertDatabaseCount('kb_user_badges', 0);

        $this->actingAs($u)->getJson('/api/me/badges')->assertOk()->assertJsonPath('enabled', false);
    }

    public function test_enabled_awards_threshold_badges(): void
    {
        config()->set('kb.gamification.enabled', true);
        $u = $this->user();
        // 1 created event (weight 5) → first_contribution (events>=1) + contributor needs 25.
        $this->events($u->id, 'created', 1);

        $awarded = app(GamificationService::class)->evaluate($u->id);
        $this->assertContains('first_contribution', $awarded);
        $this->assertDatabaseHas('kb_user_badges', ['user_id' => $u->id, 'badge_key' => 'first_contribution']);

        // Re-evaluate is idempotent (no duplicate award).
        $this->assertSame([], app(GamificationService::class)->evaluate($u->id));
    }

    public function test_badges_endpoint_reports_earned_and_progress(): void
    {
        config()->set('kb.gamification.enabled', true);
        $u = $this->user();
        $this->events($u->id, 'created', 6, 1);  // 6×created weight5 = 30 score, 1 distinct doc

        $res = $this->actingAs($u)->getJson('/api/me/badges');
        $res->assertOk()->assertJsonPath('enabled', true);
        $badges = collect($res->json('badges'));
        // contributor (score>=25) earned; author (authored>=5) not (only 1 doc).
        $this->assertTrue($badges->firstWhere('key', 'contributor')['earned']);
        $this->assertFalse($badges->firstWhere('key', 'author')['earned']);
    }

    public function test_recompute_command_awards_per_tenant(): void
    {
        config()->set('kb.gamification.enabled', true);
        $u = $this->user();
        $this->events($u->id, 'created', 1);

        $this->artisan('gamification:recompute', ['--tenant' => 'default'])->assertExitCode(0);
        $this->assertDatabaseHas('kb_user_badges', ['user_id' => $u->id, 'badge_key' => 'first_contribution']);
    }

    public function test_recompute_noop_when_disabled(): void
    {
        config()->set('kb.gamification.enabled', false);
        $u = $this->user();
        $this->events($u->id, 'created', 5);

        $this->artisan('gamification:recompute')->assertExitCode(0);
        $this->assertDatabaseCount('kb_user_badges', 0);
    }

    public function test_badges_requires_auth(): void
    {
        $this->getJson('/api/me/badges')->assertUnauthorized();
    }
}
