<?php

declare(strict_types=1);

namespace Tests\Unit\Kb\Pii;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Pii\DetokenizeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\PiiRedactor\Facades\Pii;
use Padosoft\PiiRedactor\Strategies\RedactionStrategy;
use Tests\TestCase;

/**
 * v8.23 (Ciclo 4) — the shared KB-document re-identification core.
 */
final class DetokenizeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('pii-redactor.strategy', 'tokenise');
        config()->set('pii-redactor.salt', 'detok-svc-salt');
    }

    private function makeDoc(string $project = 'support'): KnowledgeDocument
    {
        return KnowledgeDocument::create([
            'project_key' => $project,
            'source_type' => 'markdown',
            'title' => 'Doc',
            'source_path' => "docs/{$project}.md",
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', $project),
            'version_hash' => hash('sha256', $project.'v'),
        ]);
    }

    public function test_is_tokenise_active_reflects_the_configured_strategy(): void
    {
        $this->assertTrue(app(DetokenizeService::class)->isTokeniseActive());

        config()->set('pii-redactor.strategy', 'mask');
        $this->app->forgetInstance(RedactionStrategy::class);
        $this->assertFalse(app(DetokenizeService::class)->isTokeniseActive());
    }

    public function test_detokenize_document_restores_the_original_pii_in_each_chunk(): void
    {
        $email = 'mario.rossi@example.com';
        $tokenised = Pii::redact("Contact {$email} about the ticket.");
        $this->assertStringContainsString('[tok:', $tokenised);
        $this->assertStringNotContainsString($email, $tokenised);

        $doc = $this->makeDoc();
        KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id,
            'project_key' => $doc->project_key,
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', $tokenised),
            'heading_path' => 'Ticket',
            'chunk_text' => $tokenised,
            'metadata' => [],
            'embedding' => [0.1, 0.2, 0.3],
        ]);

        $result = app(DetokenizeService::class)->detokenizeDocument($doc->fresh());

        $this->assertSame(1, $result['resolved_count']);
        $this->assertSame(1, $result['token_count']);
        $this->assertSame([], $result['unresolved_tokens']);
        $this->assertStringContainsString($email, $result['chunks'][0]['text']);
    }
}
