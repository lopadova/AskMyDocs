<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Investigation;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Investigation\KbSourceReader;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class KbSourceReaderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('reader-tenant');
    }

    public function test_reads_a_short_email_source_in_full(): void
    {
        $document = $this->document('Email reso', ['connector' => 'imap']);
        $first = $this->chunk($document, 0, 'Oggetto: reso ordine X effettuato con successo.');
        $this->chunk($document, 1, 'Il rimborso sarà visibile entro cinque giorni lavorativi.');

        $source = app(KbSourceReader::class)->readCandidate($this->candidate($document, $first));

        $this->assertNotNull($source);
        $this->assertTrue($source['complete_source']);
        $this->assertStringContainsString('Oggetto: reso ordine X', $source['excerpt']);
        $this->assertStringContainsString('cinque giorni lavorativi', $source['excerpt']);
    }

    public function test_reads_only_contiguous_sections_for_a_long_manual(): void
    {
        config()->set('kb.investigation.full_source_max_chars', 40);
        config()->set('kb.investigation.long_source_neighbor_radius', 1);
        $document = $this->document('Manuale esteso');
        $this->chunk($document, 0, 'INTRODUZIONE LONTANA');
        $before = $this->chunk($document, 1, 'SEZIONE CLIENTE PRIMA');
        $anchor = $this->chunk($document, 2, 'SEZIONE ORDINE PERTINENTE');
        $this->chunk($document, 3, 'SEZIONE CLIENTE DOPO');
        $this->chunk($document, 4, 'APPENDICE LONTANA');

        $source = app(KbSourceReader::class)->readCandidate($this->candidate($document, $anchor));

        $this->assertNotNull($source);
        $this->assertFalse($source['complete_source']);
        $this->assertStringContainsString($before->chunk_text, $source['excerpt']);
        $this->assertStringContainsString($anchor->chunk_text, $source['excerpt']);
        $this->assertStringContainsString('SEZIONE CLIENTE DOPO', $source['excerpt']);
        $this->assertStringNotContainsString('INTRODUZIONE LONTANA', $source['excerpt']);
        $this->assertStringNotContainsString('APPENDICE LONTANA', $source['excerpt']);
    }

    /** @param array<string,mixed> $metadata */
    private function document(string $title, array $metadata = []): KnowledgeDocument
    {
        return KnowledgeDocument::create([
            'tenant_id' => 'reader-tenant',
            'project_key' => 'orders',
            'source_type' => 'text',
            'title' => $title,
            'source_path' => 'sources/'.strtolower(str_replace(' ', '-', $title)),
            'mime_type' => 'text/plain',
            'status' => 'active',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => bin2hex(random_bytes(16)),
            'metadata' => $metadata,
        ]);
    }

    private function chunk(KnowledgeDocument $document, int $order, string $text): KnowledgeChunk
    {
        return KnowledgeChunk::create([
            'tenant_id' => 'reader-tenant',
            'knowledge_document_id' => $document->id,
            'project_key' => 'orders',
            'chunk_order' => $order,
            'chunk_hash' => hash('sha256', $text),
            'chunk_text' => $text,
        ]);
    }

    /** @return array<string,mixed> */
    private function candidate(KnowledgeDocument $document, KnowledgeChunk $chunk): array
    {
        return [
            'chunk_id' => $chunk->id,
            'document' => ['id' => $document->id],
        ];
    }
}
