<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Models\AdminCommandAudit;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Padosoft\PiiRedactor\Facades\Pii;
use Tests\TestCase;

/**
 * v8.23 (Ciclo 4) — HTTP re-identification of a tokenised KB document.
 *
 * Mirrors LogViewerDetokenizeTest's three contracts (422 no-audit / 403 audited
 * rejection / 200 audited success) plus tenant isolation (R30) and the guest
 * boundary.
 */
final class KbDocumentDetokenizeControllerTest extends TestCase
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
        config()->set('pii-redactor.strategy', 'tokenise');
        config()->set('pii-redactor.salt', 'detok-http-salt');
    }

    private function user(string $role): User
    {
        $u = User::create([
            'name' => $role,
            'email' => $role.'-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $u->assignRole($role);

        return $u;
    }

    private function tokenisedDoc(string $email = 'mario.rossi@example.com', string $project = 'support'): KnowledgeDocument
    {
        $tokenised = Pii::redact("Contact {$email} now.");
        $doc = KnowledgeDocument::create([
            'project_key' => $project,
            'source_type' => 'markdown',
            'title' => 'Doc',
            'source_path' => "docs/{$project}.md",
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', $project.uniqid()),
            'version_hash' => hash('sha256', $project.uniqid()),
        ]);
        KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id,
            'project_key' => $project,
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', $tokenised),
            'heading_path' => '',
            'chunk_text' => $tokenised,
            'metadata' => [],
            'embedding' => [0.1, 0.2, 0.3],
        ]);

        return $doc;
    }

    public function test_returns_422_when_strategy_is_not_tokenise(): void
    {
        config()->set('pii-redactor.strategy', 'mask');
        $this->app->forgetInstance(\Padosoft\PiiRedactor\Strategies\RedactionStrategy::class);
        $doc = $this->tokenisedDoc();

        $this->actingAs($this->user('super-admin'))
            ->postJson("/api/admin/pii/documents/{$doc->id}/detokenize")
            ->assertStatus(422);

        $this->assertDatabaseMissing('admin_command_audit', ['command' => 'pii.detokenize']);
    }

    public function test_admin_without_permission_gets_403_and_an_audited_rejection(): void
    {
        $doc = $this->tokenisedDoc();

        $this->actingAs($this->user('admin'))
            ->postJson("/api/admin/pii/documents/{$doc->id}/detokenize")
            ->assertForbidden();

        $audit = AdminCommandAudit::query()->where('command', 'pii.detokenize')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame(AdminCommandAudit::STATUS_REJECTED, $audit->status);
        $this->assertSame(['document_id' => $doc->id, 'surface' => 'http'], $audit->args_json);
    }

    public function test_dpo_can_detokenize_and_the_unmask_is_audited(): void
    {
        $email = 'mario.rossi@example.com';
        $doc = $this->tokenisedDoc($email);

        $payload = $this->actingAs($this->user('dpo'))
            ->postJson("/api/admin/pii/documents/{$doc->id}/detokenize")
            ->assertOk()
            ->assertJsonPath('document_id', $doc->id)
            ->assertJsonPath('resolved_count', 1)
            ->json();

        $this->assertStringContainsString($email, $payload['chunks'][0]['text']);

        $audit = AdminCommandAudit::query()
            ->where('command', 'pii.detokenize')
            ->where('status', AdminCommandAudit::STATUS_COMPLETED)
            ->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame(['document_id' => $doc->id, 'surface' => 'http'], $audit->args_json);
    }

    public function test_a_document_from_another_tenant_is_not_found(): void
    {
        // Create the doc under tenant 'globex'…
        $tenants = app(TenantContext::class);
        $tenants->set('globex');
        $doc = $this->tokenisedDoc(project: 'support');
        $tenants->reset();

        // …a default-tenant dpo cannot re-identify it by id (R30 IDOR guard).
        $this->actingAs($this->user('dpo'))
            ->postJson("/api/admin/pii/documents/{$doc->id}/detokenize")
            ->assertNotFound();
    }

    public function test_guest_is_rejected_with_401(): void
    {
        $doc = $this->tokenisedDoc();
        $this->postJson("/api/admin/pii/documents/{$doc->id}/detokenize")->assertUnauthorized();
    }
}
