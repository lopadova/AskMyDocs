<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Models\KbAnalysisSetting;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v8.11/P10 — the per-(tenant, project) Auto-Wiki settings admin surface:
 * layered effective resolution (config → tenant '*' → project), partial-update
 * upsert (R43 both states), R18 project list from real docs, RBAC (R32).
 */
final class KbAutoWikiSettingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        app(TenantContext::class)->set('default');
        config(['kb.autowiki.enabled' => true, 'kb.autowiki.canonical_default' => true, 'kb.autowiki.non_canonical_default' => true]);
    }

    private function admin(): User
    {
        $u = User::create(['name' => 'A', 'email' => 'a-'.uniqid().'@t.local', 'password' => Hash::make('x')]);
        $u->assignRole('admin');

        return $u;
    }

    private function viewer(): User
    {
        $u = User::create(['name' => 'V', 'email' => 'v-'.uniqid().'@t.local', 'password' => Hash::make('x')]);
        $u->assignRole('viewer');

        return $u;
    }

    private function doc(string $project): void
    {
        KnowledgeDocument::create([
            'tenant_id' => 'default', 'project_key' => $project, 'source_type' => 'markdown',
            'source_path' => "decisions/{$project}-x.md", 'title' => 'X', 'mime_type' => 'text/markdown',
            'status' => 'active', 'document_hash' => str_repeat('a', 64), 'version_hash' => bin2hex(random_bytes(16)),
        ]);
    }

    public function test_index_returns_defaults_and_projects(): void
    {
        $this->doc('eng');

        $this->actingAs($this->admin())
            ->getJson('/api/admin/kb/autowiki-settings')
            ->assertOk()
            ->assertJsonPath('defaults.enabled', true)
            ->assertJsonPath('projects.0.project_key', 'eng')
            ->assertJsonPath('projects.0.effective.enabled', true);
    }

    public function test_upsert_disables_a_project_and_effective_reflects_it(): void
    {
        $this->doc('eng');

        $this->actingAs($this->admin())
            ->putJson('/api/admin/kb/autowiki-settings', ['project_key' => 'eng', 'autowiki_enabled' => false])
            ->assertOk()
            ->assertJsonPath('setting.override.enabled', false)
            ->assertJsonPath('setting.effective.enabled', false)
            // master OFF nets the dependent knobs off too.
            ->assertJsonPath('setting.effective.canonical', false);

        $this->assertDatabaseHas('kb_analysis_settings', [
            'tenant_id' => 'default', 'project_key' => 'eng', 'autowiki_enabled' => false,
        ]);
    }

    public function test_upsert_is_a_partial_update(): void
    {
        KbAnalysisSetting::create([
            'tenant_id' => 'default', 'project_key' => 'eng',
            'autowiki_enabled' => true, 'autowiki_canonical' => false,
        ]);

        // Sending only non_canonical must leave canonical untouched.
        $this->actingAs($this->admin())
            ->putJson('/api/admin/kb/autowiki-settings', ['project_key' => 'eng', 'autowiki_non_canonical' => false])
            ->assertOk();

        $row = KbAnalysisSetting::where('project_key', 'eng')->first();
        $this->assertTrue((bool) $row->autowiki_enabled);
        $this->assertFalse((bool) $row->autowiki_canonical);
        $this->assertFalse((bool) $row->autowiki_non_canonical);
    }

    public function test_upsert_null_clears_a_field_to_inherit(): void
    {
        KbAnalysisSetting::create(['tenant_id' => 'default', 'project_key' => 'eng', 'autowiki_enabled' => false]);

        $this->actingAs($this->admin())
            ->putJson('/api/admin/kb/autowiki-settings', ['project_key' => 'eng', 'autowiki_enabled' => null])
            ->assertOk()
            // cleared → inherits config default (true).
            ->assertJsonPath('setting.effective.enabled', true);

        $this->assertNull(KbAnalysisSetting::where('project_key', 'eng')->first()->autowiki_enabled);
    }

    public function test_upsert_requires_project_key(): void
    {
        $this->actingAs($this->admin())
            ->putJson('/api/admin/kb/autowiki-settings', ['autowiki_enabled' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('project_key');
    }

    public function test_viewer_is_forbidden(): void
    {
        $this->actingAs($this->viewer())
            ->getJson('/api/admin/kb/autowiki-settings')
            ->assertForbidden();
    }
}
