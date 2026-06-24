<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Models\KbPiiSetting;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v8.23 (Ciclo 4) — admin surface for the per-(tenant, project) PII ingestion
 * policy (`kb_pii_settings`).
 *
 * Coverage: index (defaults + wildcard + projects derived from real docs, R18),
 * upsert (create + partial update + clear-to-inherit), strategy validation,
 * and the read-vs-write role boundary (admin reads, dpo writes).
 */
final class KbPiiSettingControllerTest extends TestCase
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
        config()->set('kb.pii_redactor.redact_inline_ingest', false);
        config()->set('kb.pii_redactor.ingest_strategy', 'mask');
    }

    private function makeUser(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role),
            'email' => $role.'-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole($role);

        return $user;
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

    public function test_index_derives_projects_from_real_docs_with_effective_defaults(): void
    {
        $this->seedDoc('support');
        $this->seedDoc('sales');

        $resp = $this->actingAs($this->makeUser('admin'))->getJson('/api/admin/pii/policy');

        $resp->assertOk();
        $this->assertFalse($resp->json('defaults.redact_enabled'));
        $this->assertSame('mask', $resp->json('defaults.strategy'));
        $this->assertEqualsCanonicalizing(['mask', 'tokenise'], $resp->json('strategies'));

        $projects = collect($resp->json('projects'))->pluck('project_key')->all();
        $this->assertEqualsCanonicalizing(['support', 'sales'], $projects);

        $support = collect($resp->json('projects'))->firstWhere('project_key', 'support');
        $this->assertNull($support['override']);
        $this->assertFalse($support['effective']['redact_enabled']);
        $this->assertSame('mask', $support['effective']['strategy']);
    }

    public function test_dpo_can_upsert_a_project_override_and_effective_reflects_it(): void
    {
        $resp = $this->actingAs($this->makeUser('dpo'))->putJson('/api/admin/pii/policy', [
            'project_key' => 'support',
            'redact_enabled' => true,
            'strategy' => 'tokenise',
        ]);

        $resp->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('setting.project_key', 'support')
            ->assertJsonPath('setting.override.redact_enabled', true)
            ->assertJsonPath('setting.override.strategy', 'tokenise')
            ->assertJsonPath('setting.effective.redact_enabled', true)
            ->assertJsonPath('setting.effective.strategy', 'tokenise');

        $this->assertDatabaseHas('kb_pii_settings', [
            'tenant_id' => 'default',
            'project_key' => 'support',
            'redact_enabled' => true,
            'strategy' => 'tokenise',
        ]);
    }

    public function test_partial_update_leaves_omitted_fields_unchanged(): void
    {
        $dpo = $this->makeUser('dpo');
        KbPiiSetting::create([
            'tenant_id' => 'default',
            'project_key' => 'support',
            'redact_enabled' => true,
            'strategy' => 'tokenise',
        ]);

        // Send ONLY the strategy — redact_enabled must stay true.
        $this->actingAs($dpo)->putJson('/api/admin/pii/policy', [
            'project_key' => 'support',
            'strategy' => 'mask',
        ])->assertOk()
            ->assertJsonPath('setting.override.redact_enabled', true)
            ->assertJsonPath('setting.override.strategy', 'mask');
    }

    public function test_explicit_null_clears_a_field_to_inherit(): void
    {
        $dpo = $this->makeUser('dpo');
        KbPiiSetting::create([
            'tenant_id' => 'default',
            'project_key' => 'support',
            'redact_enabled' => true,
            'strategy' => 'tokenise',
        ]);

        $this->actingAs($dpo)->putJson('/api/admin/pii/policy', [
            'project_key' => 'support',
            'strategy' => null,
        ])->assertOk()
            // strategy cleared → effective inherits the config default (mask).
            ->assertJsonPath('setting.override.strategy', null)
            ->assertJsonPath('setting.effective.strategy', 'mask')
            ->assertJsonPath('setting.effective.redact_enabled', true);
    }

    public function test_upsert_with_only_project_key_is_rejected_with_422(): void
    {
        // No mutable field → would create an all-NULL no-op row; reject it.
        $this->actingAs($this->makeUser('dpo'))->putJson('/api/admin/pii/policy', [
            'project_key' => 'support',
        ])->assertStatus(422)
            ->assertJsonValidationErrorFor('strategy');

        $this->assertDatabaseMissing('kb_pii_settings', ['project_key' => 'support']);
    }

    public function test_invalid_strategy_is_rejected_with_422(): void
    {
        $this->actingAs($this->makeUser('dpo'))->putJson('/api/admin/pii/policy', [
            'project_key' => 'support',
            'strategy' => 'bogus',
        ])->assertStatus(422)
            ->assertJsonValidationErrorFor('strategy');
    }

    public function test_admin_can_read_but_cannot_write(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->getJson('/api/admin/pii/policy')->assertOk();
        $this->actingAs($admin)->putJson('/api/admin/pii/policy', [
            'project_key' => 'support',
            'redact_enabled' => true,
        ])->assertForbidden();
    }
}
