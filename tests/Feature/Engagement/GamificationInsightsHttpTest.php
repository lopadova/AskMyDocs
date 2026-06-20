<?php

declare(strict_types=1);

namespace Tests\Feature\Engagement;

use App\Models\KbContributionEvent;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\Engagement\GamificationInsightsService;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * v8.18/W4 — HTTP surface (R44) for the AI gamification insights:
 *   - GET  /api/me/coaching                     (any authenticated user, self-scoped)
 *   - GET  /api/admin/engagement/insights       (admin|super-admin)
 *   - POST /api/admin/engagement/insights/regenerate (super-admin only)
 *
 * R43: every endpoint degrades cleanly (200 available:false) when disabled.
 */
final class GamificationInsightsHttpTest extends TestCase
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
        config()->set('kb.gamification.enabled', true);
        config()->set('kb.gamification.ai.enabled', false);
    }

    private function user(string $role = ''): User
    {
        $u = User::create(['name' => 'U', 'email' => 'u-'.uniqid().'@demo.local', 'password' => Hash::make('secret123')]);
        if ($role !== '') {
            $u->assignRole(Role::findByName($role, 'web'));
        }

        return $u;
    }

    private function seedComputed(int $userId): void
    {
        $doc = KnowledgeDocument::create([
            'tenant_id' => 'default', 'project_key' => 'eng', 'source_type' => 'markdown',
            'title' => 'D', 'source_path' => 'kb/'.uniqid().'.md',
            'document_hash' => hash('sha256', uniqid('', true)), 'version_hash' => hash('sha256', uniqid('', true)),
            'status' => 'active', 'is_canonical' => true, 'canonical_status' => 'accepted',
            'canonical_type' => 'decision', 'doc_id' => 'dec-'.uniqid(), 'slug' => 'dec-'.uniqid(),
            'evidence_tier' => 'primary', 'retrieval_priority' => 60, 'frontmatter_json' => ['slug' => 'x'],
        ]);
        KbContributionEvent::create([
            'tenant_id' => 'default', 'user_id' => $userId, 'document_id' => $doc->id,
            'project_key' => 'eng', 'event' => 'created', 'weight' => 5, 'created_at' => now(),
        ]);
        app(GamificationInsightsService::class)->recomputeForTenant('2026-W25');
    }

    public function test_me_coaching_requires_auth(): void
    {
        $this->getJson('/api/me/coaching')->assertUnauthorized();
    }

    public function test_me_coaching_returns_unavailable_before_compute(): void
    {
        $u = $this->user();
        $this->actingAs($u)->getJson('/api/me/coaching')
            ->assertOk()->assertJsonPath('available', false);
    }

    public function test_me_coaching_returns_the_callers_own_card(): void
    {
        $u = $this->user();
        $this->seedComputed($u->id);

        $this->actingAs($u)->getJson('/api/me/coaching')
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('insight.scope_type', 'user')
            ->assertJsonPath('insight.scope_id', (string) $u->id);
    }

    public function test_admin_insights_tenant_scope_reads(): void
    {
        $admin = $this->user('admin');
        $this->seedComputed($admin->id);

        $this->actingAs($admin)->getJson('/api/admin/engagement/insights?scope=tenant')
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('scope', 'tenant');
    }

    public function test_admin_insights_project_scope_requires_id(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin)->getJson('/api/admin/engagement/insights?scope=project')
            ->assertStatus(422);
    }

    public function test_regenerate_is_super_admin_only(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin)->postJson('/api/admin/engagement/insights/regenerate')
            ->assertStatus(403);

        $super = $this->user('super-admin');
        $this->actingAs($super)->postJson('/api/admin/engagement/insights/regenerate', ['period' => '2026-W25'])
            ->assertStatus(202)
            ->assertJsonPath('regenerated', true);
    }

    public function test_regenerate_requires_auth(): void
    {
        $this->postJson('/api/admin/engagement/insights/regenerate')->assertUnauthorized();
    }
}
