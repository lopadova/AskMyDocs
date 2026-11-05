<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Models\User;
use App\Services\Admin\AiModelCatalog;
use App\Services\Admin\AppSettingsAssistant;
use App\Services\Admin\AppSettingsResolver;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class AppSettingsFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
        config(['ai.default' => 'openrouter']);
        $this->mock(AiModelCatalog::class)->shouldReceive('get')->andReturn(['error' => null, 'models' => [
            ['id' => 'vendor/current', 'name' => 'Current', 'selectable' => true, 'input_per_million' => .1, 'output_per_million' => .4, 'currency' => 'USD'],
        ]]);
    }

    private function user(string $role = 'super-admin'): User
    {
        $user = User::create(['name' => 'Configuration reader', 'email' => $role.'@example.test', 'password' => bcrypt('secret')]);
        $user->assignRole($role);
        return $user;
    }

    public function test_provider_and_model_are_saved_as_one_configuration(): void
    {
        $this->actingAs($this->user())->putJson('/api/admin/app-settings/batch', ['project_key' => '*', 'values' => [
            'ai.provider' => 'openrouter', 'ai.chat_model' => 'vendor/current', 'connector.sync_cadence_minutes' => '30',
        ]])->assertOk();
        $tenant = app(TenantContext::class)->current();
        $resolver = app(AppSettingsResolver::class);
        $this->assertSame('vendor/current', $resolver->effective('ai.chat_model', $tenant));
        $this->assertSame(30, $resolver->effective('connector.sync_cadence_minutes', $tenant));
    }

    public function test_invalid_fields_do_not_partially_save_other_values(): void
    {
        $this->actingAs($this->user())->putJson('/api/admin/app-settings/batch', ['values' => [
            'agent.tenant_instructions' => 'A valid operating brief', 'connector.sync_cadence_minutes' => 3,
        ]])->assertUnprocessable();
        $this->assertDatabaseCount('app_settings', 0);
    }

    public function test_model_ids_must_belong_to_the_selected_provider_catalog(): void
    {
        $this->actingAs($this->user())->putJson('/api/admin/app-settings/batch', ['values' => [
            'ai.provider' => 'anthropic', 'ai.chat_model' => 'wrong-provider/model',
        ]])->assertUnprocessable();
        $this->assertDatabaseCount('app_settings', 0);
    }

    public function test_non_associative_batch_values_fail_cleanly(): void
    {
        $this->actingAs($this->user())->putJson('/api/admin/app-settings/batch', ['values' => ['not-a-setting']])->assertUnprocessable();
        $this->assertDatabaseCount('app_settings', 0);
    }

    public function test_read_only_and_tenant_only_keys_cannot_be_written_through_the_new_form(): void
    {
        $this->actingAs($this->user())->putJson('/api/admin/app-settings/batch', ['values' => ['ai_finops.enabled' => false]])->assertUnprocessable();
        $this->putJson('/api/admin/app-settings/batch', ['project_key' => 'sales', 'values' => ['agent.tenant_instructions' => 'New text']])->assertUnprocessable();
        $this->assertDatabaseCount('app_settings', 0);
    }

    public function test_generated_instructions_only_fill_an_editable_draft_and_never_save_configuration(): void
    {
        $this->mock(AiManager::class)->shouldReceive('chat')->once()->withArgs(function ($system, $payload, $options) {
            $context = json_decode($payload, true);
            $this->assertSame(['agent.tenant_instructions'], $context['allowed_keys']);
            $this->assertStringNotContainsString('unregistered-secret', $payload);
            return str_contains($system, 'Never save') && $options['response_format']['type'] === 'json_object';
        })->andReturn(new AiResponse(json_encode(['summary' => 'Operating brief', 'changes' => [
            ['key' => 'agent.tenant_instructions', 'value' => 'Use authorized order sources and distinguish historical evidence from current status.', 'reason' => 'Matches the support task.'],
        ]]), 'openrouter', 'vendor/current'));
        $this->actingAs($this->user())->postJson('/api/admin/app-settings/assist', [
            'prompt' => 'Help my support team with orders and returns.', 'provider' => 'openrouter', 'project_key' => '*',
            'target' => 'agent.tenant_instructions', 'draft' => ['not_registered' => 'unregistered-secret'],
        ])->assertOk()->assertJsonPath('changes.0.key', 'agent.tenant_instructions');
        $this->assertDatabaseCount('app_settings', 0);
    }

    public function test_an_agent_proposal_cannot_change_protected_settings(): void
    {
        $this->mock(AiManager::class)->shouldReceive('chat')->once()->andReturn(new AiResponse(json_encode([
            'summary' => 'Invalid', 'changes' => [['key' => 'ai_finops.enabled', 'value' => false]],
        ]), 'openrouter', 'vendor/current'));
        $this->actingAs($this->user())->postJson('/api/admin/app-settings/assist', [
            'prompt' => 'Make the configuration cheaper.', 'provider' => 'openrouter', 'target' => 'configuration', 'draft' => [],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('app_settings', 0);
    }

    public function test_project_scope_cannot_generate_tenant_instructions(): void
    {
        $this->mock(AiManager::class)->shouldNotReceive('chat');
        $this->actingAs($this->user())->postJson('/api/admin/app-settings/assist', [
            'prompt' => 'Write instructions.', 'provider' => 'openrouter', 'project_key' => 'sales', 'target' => 'agent.tenant_instructions', 'draft' => [],
        ])->assertUnprocessable();
    }

    public function test_agent_provider_errors_are_generic_and_do_not_change_saved_values(): void
    {
        $this->mock(AiManager::class)->shouldReceive('chat')->once()->andThrow(new \RuntimeException('Bearer must-not-leak'));
        $response = $this->actingAs($this->user())->postJson('/api/admin/app-settings/assist', [
            'prompt' => 'Write instructions.', 'provider' => 'openrouter', 'target' => 'agent.tenant_instructions', 'draft' => [],
        ])->assertStatus(503);
        $this->assertStringNotContainsString('must-not-leak', $response->getContent());
        $this->assertDatabaseCount('app_settings', 0);
    }

    public function test_every_new_surface_requires_super_admin(): void
    {
        $this->actingAs($this->user('viewer'))->getJson('/api/admin/app-settings/models?provider=openrouter')->assertForbidden();
        $this->postJson('/api/admin/app-settings/models/refresh', ['provider' => 'openrouter'])->assertForbidden();
        $this->postJson('/api/admin/app-settings/assist', [])->assertForbidden();
        $this->putJson('/api/admin/app-settings/batch', [])->assertForbidden();
    }
}
