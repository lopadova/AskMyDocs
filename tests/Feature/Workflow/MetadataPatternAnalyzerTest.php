<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Models\KnowledgeDocument;
use App\Services\Workflow\MetadataPatternAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * v4.7/W2 — MetadataPatternAnalyzer unit-feature tests.
 *
 * Exercises the read-only analyser against in-memory KnowledgeDocument
 * rows so we never depend on actual ingestion ordering.
 */
final class MetadataPatternAnalyzerTest extends TestCase
{
    use RefreshDatabase;

    public function test_analyze_extracts_canonical_types(): void
    {
        $docs = collect([
            $this->makeDoc(['canonical_type' => 'decision']),
            $this->makeDoc(['canonical_type' => 'decision']),
            $this->makeDoc(['canonical_type' => 'runbook']),
        ]);

        $result = (new MetadataPatternAnalyzer)->analyze($docs);

        $this->assertSame(2, $result['canonical_types']['decision']);
        $this->assertSame(1, $result['canonical_types']['runbook']);
        $this->assertSame(3, $result['documents_analysed']);
    }

    public function test_analyze_extracts_top_k_tags(): void
    {
        $docs = collect([
            $this->makeDoc([], ['tags' => ['legal', 'contracts']]),
            $this->makeDoc([], ['tags' => ['legal']]),
            $this->makeDoc([], ['tags' => ['hr']]),
        ]);

        $result = (new MetadataPatternAnalyzer)->analyze($docs);

        $this->assertArrayHasKey('legal', $result['tags_top_k']);
        $this->assertSame(2, $result['tags_top_k']['legal']);
        $this->assertSame(1, $result['tags_top_k']['hr']);
    }

    public function test_analyze_detects_compliance_practice_signal(): void
    {
        $docs = collect([
            $this->makeDoc([], ['tags' => ['gdpr', 'audit']]),
            $this->makeDoc([], ['tags' => ['contracts', 'nda']]),
        ]);

        $result = (new MetadataPatternAnalyzer)->analyze($docs);

        // Each doc contributes at most one signal; gdpr → compliance,
        // contracts/nda → legal.
        $this->assertGreaterThanOrEqual(1, $result['practice_signals']['compliance'] ?? 0);
        $this->assertGreaterThanOrEqual(1, $result['practice_signals']['legal'] ?? 0);
    }

    public function test_analyze_handles_empty_collection(): void
    {
        $result = (new MetadataPatternAnalyzer)->analyze(new Collection);

        $this->assertSame(0, $result['documents_analysed']);
        $this->assertSame([], $result['canonical_types']);
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<string, mixed> $frontmatter
     */
    private function makeDoc(array $attrs = [], array $frontmatter = []): KnowledgeDocument
    {
        // Copilot iter 12: pass the frontmatter as a native array.
        // `KnowledgeDocument::frontmatter_json` is cast to `array`
        // by the model, so Eloquent will json_encode it on save and
        // json_decode it on read. Passing pre-encoded JSON would
        // double-encode the value and diverge from how production
        // rows are written by `DocumentIngestor`.
        return KnowledgeDocument::create(array_merge([
            'tenant_id' => 'default',
            'project_key' => 'p',
            'source_type' => 'markdown',
            'title' => 'D-'.uniqid(),
            'source_path' => 'd-'.uniqid().'.md',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => str_repeat('b', 64),
            'metadata' => [],
            'frontmatter_json' => $frontmatter !== [] ? $frontmatter : null,
            'status' => 'indexed',
        ], $attrs));
    }
}
