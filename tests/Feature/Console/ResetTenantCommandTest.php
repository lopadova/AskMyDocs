<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;
use Tests\TestCase;

final class ResetTenantCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->reset();
        Storage::fake('kb');
    }

    public function test_it_deletes_only_the_selected_tenant_and_recreates_its_registry_profile(): void
    {
        $victim = $this->tenant('acme', 'Acme Srl', [
            'subscription_tier' => 'enterprise',
            'dpo_email' => 'dpo@acme.test',
            'config_overrides_json' => ['retrieval.limit' => 25],
        ]);
        $survivor = $this->tenant('globex', 'Globex Spa');
        $oldVictimId = $victim->id;

        $user = User::create([
            'name' => 'Global User',
            'email' => 'global@example.test',
            'password' => Hash::make('secret123'),
        ]);

        $this->projectAndMembership('acme', 'mail', $user);
        $this->projectAndMembership('globex', 'mail', $user);
        $victimDocument = $this->document('acme', 'mail', 'emails/acme-message.md', 'acme');
        $survivorDocument = $this->document('globex', 'mail', 'emails/globex-message.md', 'globex');
        $this->chunk($victimDocument, 'acme', 'mail');
        $this->chunk($survivorDocument, 'globex', 'mail');
        Storage::disk('kb')->put('emails/acme-message.md', 'Acme mail');
        Storage::disk('kb')->put('emails/globex-message.md', 'Globex mail');

        $this->artisan('tenant:reset', ['tenant' => 'acme', '--force' => true])
            ->expectsOutputToContain("Tenant 'acme' was recreated empty.")
            ->assertSuccessful();

        $replacement = Tenant::query()->where('slug', 'acme')->sole();
        $this->assertNotSame($oldVictimId, $replacement->id);
        $this->assertSame('Acme Srl', $replacement->name);
        $this->assertSame('enterprise', $replacement->subscription_tier);
        $this->assertSame('dpo@acme.test', $replacement->dpo_email);
        $this->assertSame(['retrieval.limit' => 25], $replacement->config_overrides_json);
        $this->assertFalse((bool) $replacement->getAttribute('is_system'));

        $this->assertDatabaseMissing('projects', ['tenant_id' => 'acme']);
        $this->assertDatabaseMissing('project_memberships', ['tenant_id' => 'acme']);
        $this->assertDatabaseMissing('knowledge_documents', ['tenant_id' => 'acme']);
        $this->assertDatabaseMissing('knowledge_chunks', ['tenant_id' => 'acme']);

        $this->assertDatabaseHas('tenants', ['id' => $survivor->id, 'slug' => 'globex']);
        $this->assertDatabaseHas('projects', ['tenant_id' => 'globex', 'project_key' => 'mail']);
        $this->assertDatabaseHas('project_memberships', ['tenant_id' => 'globex', 'user_id' => $user->id]);
        $this->assertDatabaseHas('knowledge_documents', ['id' => $survivorDocument->id, 'tenant_id' => 'globex']);
        $this->assertDatabaseHas('knowledge_chunks', ['tenant_id' => 'globex']);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'global@example.test']);
        Storage::disk('kb')->assertMissing('emails/acme-message.md');
        Storage::disk('kb')->assertExists('emails/globex-message.md');
    }

    public function test_keep_files_removes_database_rows_but_preserves_source_files(): void
    {
        $this->tenant('acme', 'Acme');
        $document = $this->document('acme', 'mail', 'emails/keep-me.md', 'keep');
        $this->chunk($document, 'acme', 'mail');
        Storage::disk('kb')->put('emails/keep-me.md', 'Keep me');

        $this->artisan('tenant:reset', [
            'tenant' => 'acme',
            '--force' => true,
            '--keep-files' => true,
        ])->assertSuccessful();

        $this->assertDatabaseMissing('knowledge_documents', ['tenant_id' => 'acme']);
        $this->assertDatabaseMissing('knowledge_chunks', ['tenant_id' => 'acme']);
        Storage::disk('kb')->assertExists('emails/keep-me.md');
    }

    public function test_it_changes_nothing_when_confirmation_is_refused(): void
    {
        $tenant = $this->tenant('acme', 'Acme');
        $project = Project::create([
            'tenant_id' => 'acme',
            'project_key' => 'mail',
            'name' => 'Mail',
            'description' => null,
        ]);

        $this->artisan('tenant:reset', ['tenant' => 'acme'])
            ->expectsConfirmation("Delete and recreate tenant 'acme' now?", 'no')
            ->expectsOutputToContain('Tenant reset cancelled; no data was changed.')
            ->assertSuccessful();

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'slug' => 'acme']);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'tenant_id' => 'acme']);
    }

    public function test_it_refuses_reserved_system_and_unknown_tenants(): void
    {
        $system = $this->tenant('internal', 'Internal', ['is_system' => true]);

        $this->artisan('tenant:reset', ['tenant' => 'default', '--force' => true])
            ->expectsOutputToContain("Refusing to reset reserved tenant 'default'.")
            ->assertFailed();

        $this->artisan('tenant:reset', ['tenant' => 'internal', '--force' => true])
            ->expectsOutputToContain("Refusing to reset system tenant 'internal'.")
            ->assertFailed();

        $this->artisan('tenant:reset', ['tenant' => 'missing', '--force' => true])
            ->expectsOutputToContain("Tenant 'missing' does not exist.")
            ->assertFailed();

        $this->assertDatabaseHas('tenants', ['id' => $system->id, 'slug' => 'internal']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function tenant(string $slug, string $name, array $overrides = []): Tenant
    {
        return Tenant::query()->create(array_merge([
            'slug' => $slug,
            'name' => $name,
            'subscription_tier' => 'team',
            'status' => 'active',
            'is_system' => false,
        ], $overrides));
    }

    private function projectAndMembership(string $tenant, string $project, User $user): void
    {
        Project::create([
            'tenant_id' => $tenant,
            'project_key' => $project,
            'name' => ucfirst($tenant).' mail',
            'description' => null,
        ]);
        ProjectMembership::create([
            'tenant_id' => $tenant,
            'user_id' => $user->id,
            'project_key' => $project,
            'role' => 'member',
            'scope_allowlist' => null,
        ]);
    }

    private function document(
        string $tenant,
        string $project,
        string $sourcePath,
        string $seed,
    ): KnowledgeDocument {
        return KnowledgeDocument::create([
            'tenant_id' => $tenant,
            'project_key' => $project,
            'source_path' => $sourcePath,
            'source_type' => 'email',
            'title' => ucfirst($seed).' email',
            'mime_type' => 'text/markdown',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', 'document-'.$seed),
            'version_hash' => hash('sha256', 'version-'.$seed),
            'metadata' => ['disk' => 'kb', 'prefix' => ''],
            'indexed_at' => now(),
        ]);
    }

    private function chunk(KnowledgeDocument $document, string $tenant, string $project): KnowledgeChunk
    {
        return KnowledgeChunk::create([
            'tenant_id' => $tenant,
            'knowledge_document_id' => $document->id,
            'project_key' => $project,
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', 'chunk-'.$tenant),
            'heading_path' => 'Email',
            'chunk_text' => 'Test email body',
            'metadata' => [],
        ]);
    }
}
