<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\KnowledgeDocument;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Admin\Exceptions\TeamRegistryUnavailableException;
use App\Services\Admin\TeamRegistryService;
use App\Services\Auth\UserTeamsResolver;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * v8.28 — the shared core behind team (= tenant) create + rename. Covers the
 * create chain (tenant → project → membership) and its end-to-end outcome
 * (the actor "sees" the new team via UserTeamsResolver — the switcher's own
 * source), the membership-only rename authorization boundary, the reserved
 * `default` sentinel, and the R43 OFF-path (tenants table absent → 503-mapped
 * exception, never a silent success).
 */
final class TeamRegistryServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): TeamRegistryService
    {
        return app(TeamRegistryService::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        app(TenantContext::class)->set('default');
        Cache::flush();
    }

    public function test_create_makes_tenant_project_and_membership_and_surfaces_in_switcher(): void
    {
        $actor = $this->userWithRole('admin');

        $team = $this->service()->create(null, 'Acme Corp', $actor);

        $this->assertSame('acme-corp', $team['slug']);
        $this->assertSame('Acme Corp', $team['name']);
        $this->assertTrue($team['can_manage']);

        $this->assertDatabaseHas('tenants', ['slug' => 'acme-corp', 'name' => 'Acme Corp', 'status' => 'active']);
        $this->assertDatabaseHas('projects', ['tenant_id' => 'acme-corp', 'project_key' => 'acme-corp', 'name' => 'Acme Corp']);
        $this->assertDatabaseHas('project_memberships', [
            'tenant_id' => 'acme-corp',
            'user_id' => $actor->id,
            'project_key' => 'acme-corp',
            'role' => 'admin',
        ]);

        // The membership must make the team appear in the actor's switcher.
        $teamIds = array_column(app(UserTeamsResolver::class)->resolve($actor), 'tenant_id');
        $this->assertContains('acme-corp', $teamIds);

        // Context restored to the caller's tenant after the write burst.
        $this->assertSame('default', app(TenantContext::class)->current());
    }

    public function test_create_honours_an_explicit_slug(): void
    {
        $actor = $this->userWithRole('admin');

        $team = $this->service()->create('acme', 'Acme Corp', $actor);

        $this->assertSame('acme', $team['slug']);
        $this->assertDatabaseHas('tenants', ['slug' => 'acme', 'name' => 'Acme Corp']);
    }

    public function test_create_rejects_the_reserved_default_slug(): void
    {
        $actor = $this->userWithRole('admin');

        try {
            $this->service()->create('default', 'Whatever', $actor);
            $this->fail('Expected a ValidationException for the reserved slug.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('slug', $e->errors());
        }

        $this->assertDatabaseMissing('projects', ['tenant_id' => 'default', 'project_key' => 'default']);
    }

    public function test_create_rejects_a_duplicate_slug(): void
    {
        $actor = $this->userWithRole('admin');
        Tenant::create(['slug' => 'acme', 'name' => 'Existing Acme']);

        $this->expectException(ValidationException::class);
        $this->service()->create('acme', 'Acme Corp', $actor);
    }

    public function test_create_rejects_an_invalid_slug(): void
    {
        $actor = $this->userWithRole('admin');

        $this->expectException(ValidationException::class);
        $this->service()->create('Bad Slug!', 'Acme Corp', $actor);
    }

    public function test_create_rejects_a_blank_name(): void
    {
        $actor = $this->userWithRole('admin');

        try {
            $this->service()->create('acme', '   ', $actor);
            $this->fail('Expected a ValidationException for the blank name.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors());
        }
    }

    public function test_rename_updates_the_display_name_for_a_member(): void
    {
        $actor = $this->userWithRole('admin');
        $this->service()->create('acme', 'Acme Corp', $actor);

        $team = $this->service()->rename('acme', 'Acme Corporation', $actor);

        $this->assertSame('Acme Corporation', $team['name']);
        $this->assertDatabaseHas('tenants', ['slug' => 'acme', 'name' => 'Acme Corporation']);
    }

    public function test_rename_denies_a_non_member(): void
    {
        // The team exists with a membership for SOMEONE ELSE; the actor is not
        // a member → 404 (IDOR-safe), no write.
        Tenant::create(['slug' => 'foreign', 'name' => 'Foreign Co']);
        $other = $this->userWithRole('admin');
        ProjectMembership::create(['tenant_id' => 'foreign', 'user_id' => $other->id, 'project_key' => 'foreign', 'role' => 'admin']);

        $actor = $this->userWithRole('admin');

        $this->expectException(NotFoundHttpException::class);
        try {
            $this->service()->rename('foreign', 'Hacked', $actor);
        } finally {
            $this->assertDatabaseHas('tenants', ['slug' => 'foreign', 'name' => 'Foreign Co']);
        }
    }

    public function test_rename_denies_a_system_admin_without_membership(): void
    {
        $system = $this->userWithRole('system-admin');
        Tenant::create(['slug' => 'other', 'name' => 'Other Co', 'status' => 'active']);

        $this->expectException(NotFoundHttpException::class);
        try {
            $this->service()->rename('other', 'Other Company', $system);
        } finally {
            $this->assertDatabaseHas('tenants', ['slug' => 'other', 'name' => 'Other Co']);
        }
    }

    public function test_rename_rejects_the_default_team(): void
    {
        $super = $this->userWithRole('super-admin');

        $this->expectException(NotFoundHttpException::class);
        $this->service()->rename('default', 'Renamed Default', $super);
    }

    public function test_manageable_teams_lists_only_real_memberships(): void
    {
        $actor = $this->userWithRole('admin');
        $this->service()->create('acme', 'Acme Corp', $actor);

        $teams = collect($this->service()->manageableTeams($actor));

        $acme = $teams->firstWhere('slug', 'acme');
        $this->assertNotNull($acme);
        $this->assertTrue($acme['can_manage']);
        $this->assertFalse($acme['is_default']);
        $this->assertSame(1, $acme['project_count']);
        $this->assertSame(1, $acme['member_count']);

        $default = $teams->firstWhere('slug', 'default');
        $this->assertNull($default);
    }

    public function test_create_throws_when_the_registry_table_is_absent(): void
    {
        $actor = $this->userWithRole('admin');

        // Simulate a deployment that never migrated the AI-Act package.
        Schema::shouldReceive('hasTable')->andReturn(false);

        $this->expectException(TeamRegistryUnavailableException::class);
        $this->service()->create('acme', 'Acme Corp', $actor);
    }

    public function test_rename_throws_when_the_registry_table_is_absent(): void
    {
        $actor = $this->userWithRole('super-admin');

        Schema::shouldReceive('hasTable')->andReturn(false);

        $this->expectException(TeamRegistryUnavailableException::class);
        $this->service()->rename('acme', 'Acme Corp', $actor);
    }

    public function test_create_refuses_a_slug_that_already_owns_ingested_data_without_registry_rows(): void
    {
        // R30 security: a tenant can hold ingested documents with NO
        // tenants/projects/membership row (connector ingest writes documents
        // only). Claiming that slug here would mint the actor a membership in
        // the victim tenant and grant it operational access — so create()
        // must refuse it.
        $actor = $this->userWithRole('admin');
        $this->seedDocInTenant('victim');

        try {
            $this->service()->create('victim', 'Victim Corp', $actor);
            $this->fail('Expected a ValidationException — the slug already belongs to a data-bearing tenant.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('slug', $e->errors());
        }

        // Crucially, NO membership was minted in the victim tenant, and no
        // registry row was created.
        $this->assertDatabaseMissing('project_memberships', ['tenant_id' => 'victim', 'user_id' => $actor->id]);
        $this->assertDatabaseMissing('tenants', ['slug' => 'victim']);
    }

    public function test_create_refuses_a_slug_taken_by_a_bare_project(): void
    {
        $actor = $this->userWithRole('admin');
        Project::create(['tenant_id' => 'p-only', 'project_key' => 'p-only', 'name' => 'P Only']);

        $this->expectException(ValidationException::class);
        $this->service()->create('p-only', 'P Only', $actor);
    }

    public function test_create_refuses_a_slug_taken_by_a_bare_membership(): void
    {
        $actor = $this->userWithRole('admin');
        ProjectMembership::create(['tenant_id' => 'm-only', 'user_id' => $actor->id, 'project_key' => 'm-only', 'role' => 'admin']);

        $this->expectException(ValidationException::class);
        $this->service()->create('m-only', 'M Only', $actor);
    }

    public function test_create_refuses_a_slug_taken_by_a_vendor_tenant_table(): void
    {
        // connector_installations ships in a vendor package and was outside
        // the old literal scan set. The row deliberately has no registry,
        // project, membership or document companion: only schema discovery can
        // prevent the onboarding flow from claiming its tenant namespace.
        $actor = $this->userWithRole('admin');
        DB::table('connector_installations')->insert([
            'tenant_id' => 'ghost-connector',
            'connector_name' => 'imap',
        ]);

        try {
            $this->service()->create('ghost-connector', 'Ghost Connector', $actor);
            $this->fail('Expected a ValidationException for the occupied tenant namespace.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('slug', $e->errors());
        }

        $this->assertDatabaseMissing('tenants', ['slug' => 'ghost-connector']);
        $this->assertDatabaseMissing('project_memberships', [
            'tenant_id' => 'ghost-connector',
            'user_id' => $actor->id,
        ]);
    }

    public function test_create_rejects_a_name_over_200_characters(): void
    {
        $actor = $this->userWithRole('admin');

        try {
            $this->service()->create('acme', str_repeat('a', 201), $actor);
            $this->fail('Expected a ValidationException for an over-long name.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors());
        }

        $this->assertDatabaseMissing('tenants', ['slug' => 'acme']);
    }

    public function test_rename_rejects_a_name_over_200_characters(): void
    {
        $actor = $this->userWithRole('admin');
        $this->service()->create('acme', 'Acme Corp', $actor);

        $this->expectException(ValidationException::class);
        $this->service()->rename('acme', str_repeat('a', 201), $actor);
    }

    public function test_member_count_counts_distinct_users_not_memberships(): void
    {
        $actor = $this->userWithRole('admin');
        $this->service()->create('acme', 'Acme Corp', $actor);

        // Same user, a SECOND project + membership within the same tenant.
        Project::create(['tenant_id' => 'acme', 'project_key' => 'acme-2', 'name' => 'Acme 2']);
        ProjectMembership::create(['tenant_id' => 'acme', 'user_id' => $actor->id, 'project_key' => 'acme-2', 'role' => 'admin']);

        $acme = collect($this->service()->manageableTeams($actor))->firstWhere('slug', 'acme');
        $this->assertSame(2, $acme['project_count']);
        // Would be 2 under COUNT(*); DISTINCT keeps it at the one real user.
        $this->assertSame(1, $acme['member_count'], 'member_count must count DISTINCT users, not memberships.');

        // The create/rename return payload counts distinctly too.
        $renamed = $this->service()->rename('acme', 'Acme Corporation', $actor);
        $this->assertSame(1, $renamed['member_count']);
    }

    public function test_manageable_teams_marks_a_registry_less_team_unmanageable(): void
    {
        // A membership in a tenant that has NO tenants row (e.g. bootstrapped
        // while the registry table was absent). rename() would 404 on it, so
        // the list must not present it as manageable.
        $actor = $this->userWithRole('admin');
        ProjectMembership::create(['tenant_id' => 'legacy', 'user_id' => $actor->id, 'project_key' => 'legacy', 'role' => 'admin']);

        $legacy = collect($this->service()->manageableTeams($actor))->firstWhere('slug', 'legacy');
        $this->assertNotNull($legacy);
        $this->assertFalse($legacy['can_manage'], 'A team with no tenants row cannot be renamed, so it must not be can_manage.');
    }

    private function seedDocInTenant(string $tenantId): void
    {
        $ctx = app(TenantContext::class);
        $previous = $ctx->current();
        $ctx->set($tenantId);
        // BelongsToTenant auto-fills tenant_id from the active context; this
        // writes a knowledge_documents row with NO projects/memberships/tenants.
        KnowledgeDocument::create([
            'project_key' => $tenantId,
            'source_type' => 'markdown',
            'title' => 'Doc '.uniqid(),
            'source_path' => 'seed/'.uniqid().'.md',
            'document_hash' => hash('sha256', uniqid('', true)),
            'version_hash' => hash('sha256', uniqid('', true)),
            'status' => 'indexed',
        ]);
        $ctx->set($previous);
    }

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role),
            'email' => $role.'-'.uniqid().'@demo.local',
            'password' => Hash::make('secret-password'),
        ]);
        $user->assignRole($role === 'system-admin' ? ['system-admin', 'super-admin'] : $role);

        return $user;
    }
}
