<?php

declare(strict_types=1);

namespace Tests\Unit\Kb\Pii;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Pii\DetokenizeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\PiiRedactor\Facades\Pii;
use Padosoft\PiiRedactor\Strategies\RedactionStrategy;
use Padosoft\PiiRedactor\TokenStore\TokenResolutionService;
use Padosoft\PiiRedactor\TokenStore\TokenStore;
use RuntimeException;
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

    public function test_a_chunk_whose_vault_lookup_throws_degrades_to_the_redacted_form(): void
    {
        // A corrupt / key-rotated vault row can make the resolver throw. One bad
        // chunk must NOT abort the whole document — it degrades to its redacted
        // form, counts as unresolved, and the method still returns.
        $throwingStore = new class implements TokenStore
        {
            public function put(string $token, string $original): void {}

            public function get(string $token): ?string
            {
                throw new RuntimeException('vault decrypt failed');
            }

            public function has(string $token): bool
            {
                return false;
            }

            public function clear(): void {}

            public function dump(): array
            {
                return [];
            }

            public function load(array $map): void {}
        };
        $service = new DetokenizeService(new TokenResolutionService($throwingStore));

        $surrogate = '[tok:email:abcdef0123456789]';
        $doc = $this->makeDoc();
        KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id,
            'project_key' => $doc->project_key,
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', $surrogate),
            'heading_path' => '',
            'chunk_text' => "Contact {$surrogate}.",
            'metadata' => [],
            'embedding' => [0.1, 0.2, 0.3],
        ]);

        $result = $service->detokenizeDocument($doc->fresh());

        // Did not throw; the surrogate is preserved and accounted as unresolved.
        $this->assertSame(0, $result['resolved_count']);
        $this->assertSame([$surrogate], $result['unresolved_tokens']);
        $this->assertStringContainsString($surrogate, $result['chunks'][0]['text']);
    }
}
