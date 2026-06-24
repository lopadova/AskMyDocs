<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Models\AdminCommandAudit;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Mcp\Tools\KbDetokenizeTool;
use App\Services\Kb\Pii\DetokenizeService;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Mcp\Request;
use Padosoft\PiiRedactor\Facades\Pii;
use Padosoft\PiiRedactor\Strategies\RedactionStrategy;
use Tests\TestCase;

/**
 * v8.23 (Ciclo 4) — the KB-document re-identification MCP tool (R44).
 *
 * Doubly gated (see the tool docblock): in production the MCP authorizer admits
 * admin/super-admin and this tool additionally requires pii.detokenize → net
 * super-admin only. These tests call handle() directly (the authorizer is a
 * separate layer) to prove the tool's OWN permission gate, strategy preflight,
 * tenant scoping, and audit trail.
 */
final class KbDetokenizeToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->reset();
        $this->seed(RbacSeeder::class);
        // Flush the Spatie permission cache so $user->can() is not order-dependent
        // across methods under Testbench (RefreshDatabase doesn't clear it).
        Cache::flush();
        config()->set('pii-redactor.strategy', 'tokenise');
        config()->set('pii-redactor.salt', 'detok-mcp-salt');
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
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

    private function tokenisedDoc(string $email): KnowledgeDocument
    {
        $tokenised = Pii::redact("Contact {$email}.");
        $doc = KnowledgeDocument::create([
            'project_key' => 'support',
            'source_type' => 'markdown',
            'title' => 'Doc',
            'source_path' => 'docs/support.md',
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', uniqid()),
            'version_hash' => hash('sha256', uniqid()),
        ]);
        KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id,
            'project_key' => 'support',
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', $tokenised),
            'heading_path' => '',
            'chunk_text' => $tokenised,
            'metadata' => [],
            'embedding' => [0.1, 0.2, 0.3],
        ]);

        return $doc;
    }

    private function invoke(int $documentId): \Laravel\Mcp\Response
    {
        $tool = new KbDetokenizeTool();

        return $tool->handle(new Request(['document_id' => $documentId]), app(DetokenizeService::class));
    }

    public function test_super_admin_can_detokenize_and_it_is_audited(): void
    {
        $email = 'mario.rossi@example.com';
        $doc = $this->tokenisedDoc($email);
        $this->actingAs($this->user('super-admin'));

        $response = $this->invoke($doc->id);
        $payload = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($doc->id, $payload['document_id']);
        $this->assertSame(1, $payload['resolved_count']);
        $this->assertStringContainsString($email, $payload['chunks'][0]['text']);

        $this->assertDatabaseHas('admin_command_audit', [
            'command' => 'pii.detokenize',
            'status' => AdminCommandAudit::STATUS_COMPLETED,
        ]);
    }

    public function test_caller_without_permission_is_refused_and_audited(): void
    {
        $doc = $this->tokenisedDoc('mario.rossi@example.com');
        $this->actingAs($this->user('admin')); // admin lacks pii.detokenize

        $response = $this->invoke($doc->id);

        $this->assertStringContainsString('Forbidden', (string) $response->content());
        $this->assertDatabaseHas('admin_command_audit', [
            'command' => 'pii.detokenize',
            'status' => AdminCommandAudit::STATUS_REJECTED,
        ]);
    }

    public function test_it_cannot_detokenize_a_document_from_another_tenant(): void
    {
        // Doc created under tenant 'globex'…
        $tenants = app(TenantContext::class);
        $tenants->set('globex');
        $doc = $this->tokenisedDoc('mario.rossi@example.com');
        $tenants->reset();

        // …a super-admin acting in the default tenant cannot re-identify it by id
        // (R30 / IDOR guard) — findDocument is forTenant-scoped → not found.
        $this->actingAs($this->user('super-admin'));
        $response = $this->invoke($doc->id);

        $this->assertStringContainsString('not found', (string) $response->content());
        $this->assertStringNotContainsString('mario.rossi@example.com', (string) $response->content());
    }

    public function test_it_refuses_when_strategy_is_not_tokenise(): void
    {
        config()->set('pii-redactor.strategy', 'mask');
        $this->app->forgetInstance(RedactionStrategy::class);
        $this->actingAs($this->user('super-admin'));

        $response = $this->invoke(1);
        $this->assertStringContainsString('tokenise', (string) $response->content());
    }
}
