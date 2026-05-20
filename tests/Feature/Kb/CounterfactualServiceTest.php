<?php

declare(strict_types=1);

namespace Tests\Feature\Kb;

use App\Ai\EmbeddingsResponse;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Kb\EmbeddingCacheService;
use App\Services\Kb\Retrieval\CounterfactualService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

final class CounterfactualServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_anonymous_caller_gets_empty_array(): void
    {
        $service = new CounterfactualService($this->stubEmbeddingCache());
        $panels = $service->pick(
            query: 'how do we handle X',
            userId: null,
            tenantId: 'default',
            primaryProjectKey: 'projA',
        );
        $this->assertSame([], $panels);
    }

    public function test_user_with_only_primary_membership_gets_empty(): void
    {
        $user = $this->makeUser();
        ProjectMembership::query()->create([
            'tenant_id' => 'default',
            'user_id' => $user->id,
            'project_key' => 'projA',
            'role' => 'editor',
            'scope_allowlist' => null,
        ]);
        $service = new CounterfactualService($this->stubEmbeddingCache());
        $panels = $service->pick(
            query: 'how do we handle X',
            userId: $user->id,
            tenantId: 'default',
            primaryProjectKey: 'projA',
        );
        $this->assertSame([], $panels);
    }

    public function test_neighbor_projects_capped_to_max_neighbors_in_most_recent_order(): void
    {
        config(['kb.counterfactual.max_neighbors' => 2]);
        $user = $this->makeUser();

        // Model::create() auto-stamps `created_at = now()` even when
        // an explicit value is passed, so write via DB::table to
        // honour the manual timestamps (the service orders the
        // result by `created_at DESC`).
        foreach (['projB' => 30, 'projC' => 20, 'projD' => 10] as $key => $ageMinutes) {
            \Illuminate\Support\Facades\DB::table('project_memberships')->insert([
                'tenant_id' => 'default',
                'user_id' => $user->id,
                'project_key' => $key,
                'role' => 'editor',
                'scope_allowlist' => null,
                'created_at' => now()->subMinutes($ageMinutes)->toDateTimeString(),
                'updated_at' => now()->subMinutes($ageMinutes)->toDateTimeString(),
            ]);
        }

        $this->seedCachedPanelFor('how do we handle X', 'projD', 'default', [
            ['chunk_id' => 1, 'project_key' => 'projD'],
        ]);
        $this->seedCachedPanelFor('how do we handle X', 'projC', 'default', [
            ['chunk_id' => 2, 'project_key' => 'projC'],
        ]);
        $this->seedCachedPanelFor('how do we handle X', 'projB', 'default', [
            ['chunk_id' => 3, 'project_key' => 'projB'],
        ]);

        $service = new CounterfactualService($this->stubEmbeddingCache());
        $panels = $service->pick(
            query: 'how do we handle X',
            userId: $user->id,
            tenantId: 'default',
            primaryProjectKey: 'projA',
        );

        $this->assertCount(2, $panels);
        $this->assertSame('projD', $panels[0]['project_key']);
        $this->assertSame('projC', $panels[1]['project_key']);
    }

    public function test_rbac_strict_only_user_membership_projects_surface(): void
    {
        $alice = $this->makeUser('alice');
        $bob = $this->makeUser('bob');

        ProjectMembership::query()->create([
            'tenant_id' => 'default',
            'user_id' => $alice->id,
            'project_key' => 'projA-alice-only',
            'role' => 'editor',
            'scope_allowlist' => null,
        ]);
        ProjectMembership::query()->create([
            'tenant_id' => 'default',
            'user_id' => $bob->id,
            'project_key' => 'projB-bob-only',
            'role' => 'editor',
            'scope_allowlist' => null,
        ]);

        $this->seedCachedPanelFor('q', 'projA-alice-only', 'default', [
            ['chunk_id' => 1, 'project_key' => 'projA-alice-only'],
        ]);

        $service = new CounterfactualService($this->stubEmbeddingCache());
        $panels = $service->pick(
            query: 'q',
            userId: $alice->id,
            tenantId: 'default',
            primaryProjectKey: 'projOther',
        );

        $projectKeys = array_column($panels, 'project_key');
        $this->assertContains('projA-alice-only', $projectKeys);
        $this->assertNotContains('projB-bob-only', $projectKeys);
    }

    public function test_disabled_via_config_returns_empty(): void
    {
        config(['kb.counterfactual.enabled' => false]);
        $user = $this->makeUser();
        ProjectMembership::query()->create([
            'tenant_id' => 'default',
            'user_id' => $user->id,
            'project_key' => 'projB',
            'role' => 'editor',
            'scope_allowlist' => null,
        ]);
        $service = new CounterfactualService($this->stubEmbeddingCache());
        $panels = $service->pick(
            query: 'q',
            userId: $user->id,
            tenantId: 'default',
            primaryProjectKey: 'projA',
        );
        $this->assertSame([], $panels);
    }

    public function test_rbac_strict_same_project_key_across_tenants_does_not_leak(): void
    {
        // Copilot iter-1 R30 regression test: two TENANTS legitimately
        // share the same `project_key` (per R30 — tenant boundary, not
        // project_key, is the safety boundary). The user in tenant A
        // has a membership in `shared` and queries the counterfactual.
        // The cached panel for `tenant-A|shared` must NOT contain
        // chunks the request is somehow able to surface from
        // `tenant-B|shared` (the new `->forTenant()` clause makes the
        // SQL itself enforce the boundary; this test asserts the
        // contract holds end-to-end).
        $alice = $this->makeUser('alice');

        ProjectMembership::query()->create([
            'tenant_id' => 'tenant-a',
            'user_id' => $alice->id,
            'project_key' => 'shared',
            'role' => 'editor',
            'scope_allowlist' => null,
        ]);

        $this->seedCachedPanelFor('q', 'shared', 'tenant-a', [
            ['chunk_id' => 1, 'project_key' => 'shared', 'tenant_id' => 'tenant-a'],
        ]);

        $service = new CounterfactualService($this->stubEmbeddingCache());

        $panels = $service->pick(
            query: 'q',
            userId: $alice->id,
            tenantId: 'tenant-a',
            primaryProjectKey: 'projOther',
        );

        $this->assertCount(1, $panels);
        $this->assertSame('shared', $panels[0]['project_key']);
        // Cache key MUST include tenant_id so the lookup for
        // tenant-a|shared can never accidentally pick up
        // tenant-b|shared.
        $tenantBkey = 'cf:'.hash('sha256', 'tenant-b|shared|q');
        $this->assertFalse(
            Cache::has($tenantBkey),
            'cache should not be seeded under foreign tenant key',
        );
    }

    public function test_second_call_hits_cache_no_re_lookup(): void
    {
        $user = $this->makeUser();
        ProjectMembership::query()->create([
            'tenant_id' => 'default',
            'user_id' => $user->id,
            'project_key' => 'projB',
            'role' => 'editor',
            'scope_allowlist' => null,
        ]);

        $this->seedCachedPanelFor('q', 'projB', 'default', [
            ['chunk_id' => 1, 'project_key' => 'projB'],
        ]);

        $embeddingMock = Mockery::mock(EmbeddingCacheService::class);
        $embeddingMock->shouldNotReceive('generate');

        $service = new CounterfactualService($embeddingMock);
        $first = $service->pick(
            query: 'q',
            userId: $user->id,
            tenantId: 'default',
            primaryProjectKey: 'projA',
        );
        $second = $service->pick(
            query: 'q',
            userId: $user->id,
            tenantId: 'default',
            primaryProjectKey: 'projA',
        );

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertSame($first, $second);
    }

    private function stubEmbeddingCache(): EmbeddingCacheService
    {
        $mock = Mockery::mock(EmbeddingCacheService::class);
        $mock->shouldReceive('generate')
            ->andReturn(new EmbeddingsResponse(
                embeddings: [array_fill(0, 1536, 0.0)],
                provider: 'test',
                model: 'test',
            ));
        return $mock;
    }

    private function seedCachedPanelFor(string $query, string $projectKey, string $tenantId, array $topChunks): void
    {
        $key = 'cf:'.hash('sha256', $tenantId.'|'.$projectKey.'|'.$query);
        Cache::put($key, $topChunks, 3600);
    }

    private function makeUser(string $slug = 'cf'): User
    {
        return User::create([
            'name' => $slug,
            'email' => $slug.'-'.uniqid().'@demo.local',
            'password' => 'hash-placeholder',
        ]);
    }
}
