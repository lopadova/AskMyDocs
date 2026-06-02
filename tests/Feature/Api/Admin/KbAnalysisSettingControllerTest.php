<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Models\KbAnalysisSetting;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v8.8/W3 — admin surface for the per-(tenant, project) deep-analysis gate.
 *
 * Coverage: index (defaults + wildcard + projects derived from real docs,
 * effective resolution), upsert (create + clear-to-inherit), per-project
 * split, cross-tenant isolation (R30), 403 non-admin, 401 guest.
 */
final class KbAnalysisSettingControllerTest extends TestCase
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
        // Spatie's permission cache survives the RefreshDatabase rollback under
        // Testbench — flush it so role checks aren't order-dependent/flaky.
        Cache::flush();
        config()->set('kb.change_analysis.enabled', true);
        config()->set('kb.change_analysis.canonical_default', true);
        config()->set('kb.change_analysis.non_canonical_default', false);
        config()->set('kb.change_analysis.delete_enabled', true);
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

    private function seedDoc(string $project): void
    {
        KnowledgeDocument::create([
            'project_key' => $project,
            'source_type' => 'markdown',
            'title' => "Doc $project",
            'source_path' => "docs/$project.md",
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', $project),
            'version_hash' => hash('sha256', $project.'v'),
        ]);
    }

    public function test_index_derives_projects_from_real_docs_with_effective_values(): void
    {
        $admin = $this->makeAdmin();
        $this->seedDoc('eng');
        $this->seedDoc('hr');

        $resp = $this->actingAs($admin)->getJson('/api/admin/kb/analysis-settings');

        $resp->assertOk();
        $this->assertTrue($resp->json('defaults.enabled'));
        $projects = collect($resp->json('projects'))->pluck('project_key')->all();
        $this->assertEqualsCanonicalizing(['eng', 'hr'], $projects);
        // Effective resolution falls back to config when no override exists.
        $eng = collect($resp->json('projects'))->firstWhere('project_key', 'eng');
        $this->assertNull($eng['override']);
        $this->assertTrue($eng['effective']['canonical']);
        $this->assertFalse($eng['effective']['non_canonical']);
    }

    public function test_upsert_creates_a_per_project_override(): void
    {
        $admin = $this->makeAdmin();

        $resp = $this->actingAs($admin)->putJson('/api/admin/kb/analysis-settings', [
            'project_key' => 'eng',
            'enabled' => false,
        ]);

        $resp->assertOk()->assertJsonPath('ok', true);
        $this->assertFalse($resp->json('setting.effective.enabled'));
        $this->assertDatabaseHas('kb_analysis_settings', [
            'tenant_id' => 'default',
            'project_key' => 'eng',
            'enabled' => false,
        ]);
    }

    public function test_upsert_clears_a_field_to_inherit(): void
    {
        $admin = $this->makeAdmin();
        KbAnalysisSetting::create(['tenant_id' => 'default', 'project_key' => 'eng', 'enabled' => false]);

        // Re-upsert with enabled omitted/null → clears the override → inherits config true.
        $resp = $this->actingAs($admin)->putJson('/api/admin/kb/analysis-settings', [
            'project_key' => 'eng',
            'enabled' => null,
        ]);

        $resp->assertOk();
        $this->assertNull($resp->json('setting.override.enabled'));
        $this->assertTrue($resp->json('setting.effective.enabled'), 'cleared field inherits config default');
    }

    public function test_upsert_partial_update_leaves_omitted_fields_unchanged(): void
    {
        $admin = $this->makeAdmin();
        KbAnalysisSetting::create([
            'tenant_id' => 'default', 'project_key' => 'eng',
            'enabled' => true, 'non_canonical' => true,
        ]);

        // Change ONLY canonical; enabled + non_canonical must survive.
        $resp = $this->actingAs($admin)->putJson('/api/admin/kb/analysis-settings', [
            'project_key' => 'eng',
            'canonical' => false,
        ]);

        $resp->assertOk();
        $this->assertTrue($resp->json('setting.override.enabled'), 'omitted enabled stays set');
        $this->assertTrue($resp->json('setting.override.non_canonical'), 'omitted non_canonical stays set');
        $this->assertFalse($resp->json('setting.override.canonical'));
    }

    public function test_effective_display_zeroes_dependents_when_disabled(): void
    {
        $admin = $this->makeAdmin();

        $resp = $this->actingAs($admin)->putJson('/api/admin/kb/analysis-settings', [
            'project_key' => 'eng',
            'enabled' => false,
            'canonical' => true,
        ]);

        $resp->assertOk();
        // override keeps the raw values the operator set…
        $this->assertTrue($resp->json('setting.override.canonical'));
        // …but the EFFECTIVE display agrees with the gate: nothing runs.
        $this->assertFalse($resp->json('setting.effective.enabled'));
        $this->assertFalse($resp->json('setting.effective.canonical'));
    }

    public function test_upsert_validation_rejects_non_boolean(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->putJson('/api/admin/kb/analysis-settings', [
            'project_key' => 'eng',
            'enabled' => 'yes-please',
        ])->assertStatus(422);
    }

    public function test_settings_are_tenant_scoped(): void
    {
        $admin = $this->makeAdmin();
        // An override owned by ANOTHER tenant must not surface for 'default'.
        KbAnalysisSetting::create(['tenant_id' => 'other', 'project_key' => 'eng', 'enabled' => false]);
        $this->seedDoc('eng');

        $resp = $this->actingAs($admin)->getJson('/api/admin/kb/analysis-settings');

        $eng = collect($resp->json('projects'))->firstWhere('project_key', 'eng');
        $this->assertNull($eng['override'], 'the other tenant override must not leak');
        $this->assertTrue($eng['effective']['enabled']);
    }

    public function test_non_admin_is_forbidden(): void
    {
        $user = User::create([
            'name' => 'Viewer',
            'email' => 'viewer-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole('viewer');

        $this->actingAs($user)->getJson('/api/admin/kb/analysis-settings')->assertStatus(403);
    }

    public function test_guest_is_unauthenticated(): void
    {
        $this->getJson('/api/admin/kb/analysis-settings')->assertStatus(401);
    }
}
