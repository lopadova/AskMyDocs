<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\AdminCommandAudit;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\PiiRedactor\Facades\Pii;
use Padosoft\PiiRedactor\Strategies\RedactionStrategy;
use Tests\TestCase;

/**
 * v8.23 (Ciclo 4) — the `kb:detokenize-document` CLI re-identification surface.
 */
final class KbDetokenizeDocumentCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->reset();
        config()->set('pii-redactor.strategy', 'tokenise');
        config()->set('pii-redactor.salt', 'detok-cli-salt');
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

    public function test_it_detokenises_a_document_and_audits(): void
    {
        $email = 'mario.rossi@example.com';
        $doc = $this->tokenisedDoc($email);

        $this->artisan('kb:detokenize-document', ['id' => $doc->id, '--tenant' => 'default'])
            ->expectsOutputToContain($email)
            ->assertSuccessful();

        $this->assertDatabaseHas('admin_command_audit', [
            'command' => 'pii.detokenize',
            'status' => AdminCommandAudit::STATUS_COMPLETED,
        ]);
    }

    public function test_it_fails_when_strategy_is_not_tokenise(): void
    {
        config()->set('pii-redactor.strategy', 'mask');
        $this->app->forgetInstance(RedactionStrategy::class);

        $this->artisan('kb:detokenize-document', ['id' => 1])
            ->assertFailed();
    }

    public function test_it_fails_when_the_document_is_missing(): void
    {
        $this->artisan('kb:detokenize-document', ['id' => 99999, '--tenant' => 'default'])
            ->assertFailed();
    }
}
