<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Models\KbSearchFailure;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v8.8/W4 — admin "Content Gaps" surface.
 *
 * Coverage: ranked desc by occurrences (R16 strict-monotonic fixture),
 * resolved gaps excluded by default, reason filter, resolve action,
 * cross-tenant isolation (R30), 403 non-admin, 401 guest.
 */
final class KbContentGapControllerTest extends TestCase
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

    private function seedGap(string $query, int $occurrences, array $extra = []): KbSearchFailure
    {
        return KbSearchFailure::create(array_merge([
            'tenant_id' => 'default',
            'project_key' => 'eng',
            'query_hash' => hash('sha256', $query),
            'normalized_query' => $query,
            'query_text' => $query,
            'reason' => KbSearchFailure::REASON_NO_CONTEXT,
            'occurrences' => $occurrences,
            'last_seen_at' => now(),
        ], $extra));
    }

    public function test_index_ranks_gaps_by_occurrences_desc(): void
    {
        $admin = $this->makeAdmin();
        // Strict-monotonic fixture: would FAIL under asc ordering (R16).
        $this->seedGap('rarely asked', 2);
        $this->seedGap('most asked', 17);
        $this->seedGap('sometimes asked', 9);

        $resp = $this->actingAs($admin)->getJson('/api/admin/kb/content-gaps');

        $resp->assertOk();
        $occ = collect($resp->json('data'))->pluck('occurrences')->all();
        $this->assertSame([17, 9, 2], $occ);
        $this->assertGreaterThan($occ[1], $occ[0]);
        $this->assertGreaterThan($occ[2], $occ[1]);
    }

    public function test_index_returns_available_reasons_derived_from_db(): void
    {
        $admin = $this->makeAdmin();
        // R18 — the reason filter domain is derived from the DB, not hard-coded.
        $this->seedGap('q1', 3, ['reason' => KbSearchFailure::REASON_NO_CONTEXT]);
        $this->seedGap('q2', 5, ['reason' => KbSearchFailure::REASON_SELF_REFUSAL]);

        $resp = $this->actingAs($admin)->getJson('/api/admin/kb/content-gaps');

        $resp->assertOk();
        $reasons = $resp->json('meta.available_reasons');
        $this->assertIsArray($reasons);
        // Both recorded reasons are present; sorted alphabetically (orderBy reason).
        $this->assertContains(KbSearchFailure::REASON_NO_CONTEXT, $reasons);
        $this->assertContains(KbSearchFailure::REASON_SELF_REFUSAL, $reasons);
        // available_reasons must NOT filter by the current request filters.
        $filteredResp = $this->actingAs($admin)
            ->getJson('/api/admin/kb/content-gaps?reason='.KbSearchFailure::REASON_SELF_REFUSAL);
        $filteredReasons = $filteredResp->json('meta.available_reasons');
        $this->assertContains(KbSearchFailure::REASON_NO_CONTEXT, $filteredReasons);
        $this->assertContains(KbSearchFailure::REASON_SELF_REFUSAL, $filteredReasons);
    }

    public function test_resolved_gaps_are_excluded_by_default_but_included_on_request(): void
    {
        $admin = $this->makeAdmin();
        $this->seedGap('open gap', 5);
        $this->seedGap('closed gap', 8, ['resolved_at' => now()]);

        $open = $this->actingAs($admin)->getJson('/api/admin/kb/content-gaps');
        $this->assertSame(['open gap'], collect($open->json('data'))->pluck('query_text')->all());

        $all = $this->actingAs($admin)->getJson('/api/admin/kb/content-gaps?include_resolved=1');
        $this->assertSame(2, $all->json('meta.total'));
    }

    public function test_reason_filter(): void
    {
        $admin = $this->makeAdmin();
        $this->seedGap('no context q', 4, ['reason' => KbSearchFailure::REASON_NO_CONTEXT]);
        $this->seedGap('self refusal q', 6, ['reason' => KbSearchFailure::REASON_SELF_REFUSAL]);

        $resp = $this->actingAs($admin)->getJson('/api/admin/kb/content-gaps?reason='.KbSearchFailure::REASON_SELF_REFUSAL);

        $this->assertSame(['self refusal q'], collect($resp->json('data'))->pluck('query_text')->all());
    }

    public function test_resolve_marks_a_gap_resolved(): void
    {
        $admin = $this->makeAdmin();
        $gap = $this->seedGap('please cover this', 3);

        $resp = $this->actingAs($admin)->patchJson("/api/admin/kb/content-gaps/{$gap->id}/resolve");

        $resp->assertOk()->assertJsonPath('ok', true);
        $this->assertNotNull($gap->fresh()->resolved_at);
    }

    public function test_resolve_is_tenant_scoped_idor_safe(): void
    {
        $admin = $this->makeAdmin();
        // A gap owned by ANOTHER tenant must not be resolvable.
        $foreign = $this->seedGap('foreign gap', 3, ['tenant_id' => 'other']);

        $this->actingAs($admin)->patchJson("/api/admin/kb/content-gaps/{$foreign->id}/resolve")->assertStatus(404);
        $this->assertNull($foreign->fresh()->resolved_at);
    }

    public function test_index_is_tenant_scoped(): void
    {
        $admin = $this->makeAdmin();
        $this->seedGap('default gap', 5);
        $this->seedGap('other gap', 9, ['tenant_id' => 'other']);

        $resp = $this->actingAs($admin)->getJson('/api/admin/kb/content-gaps');

        $this->assertSame(['default gap'], collect($resp->json('data'))->pluck('query_text')->all());
    }

    public function test_non_admin_is_forbidden(): void
    {
        $user = User::create([
            'name' => 'Viewer',
            'email' => 'viewer-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole('viewer');

        $this->actingAs($user)->getJson('/api/admin/kb/content-gaps')->assertStatus(403);
    }

    public function test_guest_is_unauthenticated(): void
    {
        $this->getJson('/api/admin/kb/content-gaps')->assertStatus(401);
    }
}
