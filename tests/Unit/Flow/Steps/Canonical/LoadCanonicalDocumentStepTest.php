<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Steps\Canonical;

use App\Flow\Steps\Canonical\LoadCanonicalDocumentStep;
use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelFlow\FlowContext;
use RuntimeException;
use Tests\TestCase;

final class LoadCanonicalDocumentStepTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_indexable_payload_for_canonical_doc(): void
    {
        $doc = $this->makeCanonicalDoc();
        $step = $this->app->make(LoadCanonicalDocumentStep::class);

        $result = $step->execute($this->context(['document_id' => $doc->id]));

        $this->assertTrue($result->success);
        $this->assertTrue($result->output['indexable']);
        $this->assertSame((int) $doc->id, $result->output['document_id']);
        $this->assertSame('dec-x', $result->output['slug']);
    }

    public function test_short_circuits_when_document_missing(): void
    {
        $step = $this->app->make(LoadCanonicalDocumentStep::class);
        $result = $step->execute($this->context(['document_id' => 999_999]));

        $this->assertTrue($result->success);
        $this->assertFalse($result->output['indexable']);
        $this->assertSame('document_not_found', $result->output['reason']);
    }

    public function test_short_circuits_when_document_not_canonical(): void
    {
        $doc = KnowledgeDocument::create([
            'project_key' => 'acme',
            'source_type' => 'markdown',
            'title' => 'X',
            'source_path' => 'docs/x.md',
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'access_scope' => 'public',
            'status' => 'active',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => str_repeat('b', 64),
            'metadata' => null,
            'is_canonical' => false,
        ]);

        $step = $this->app->make(LoadCanonicalDocumentStep::class);
        $result = $step->execute($this->context(['document_id' => $doc->id]));

        $this->assertFalse($result->output['indexable']);
        $this->assertSame('not_canonical', $result->output['reason']);
    }

    public function test_short_circuits_when_archived(): void
    {
        $doc = $this->makeCanonicalDoc(['status' => 'archived']);
        $step = $this->app->make(LoadCanonicalDocumentStep::class);
        $result = $step->execute($this->context(['document_id' => $doc->id]));

        $this->assertFalse($result->output['indexable']);
        $this->assertSame('archived', $result->output['reason']);
    }

    public function test_dry_run_runs_load_safely(): void
    {
        $doc = $this->makeCanonicalDoc();
        $step = $this->app->make(LoadCanonicalDocumentStep::class);

        $result = $step->execute($this->context(['document_id' => $doc->id], dryRun: true));

        $this->assertTrue($result->success);
        $this->assertTrue($result->output['indexable']);
    }

    public function test_throws_when_document_id_invalid(): void
    {
        $step = $this->app->make(LoadCanonicalDocumentStep::class);

        $this->expectException(RuntimeException::class);
        $step->execute($this->context(['document_id' => 0]));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function context(array $input, bool $dryRun = false): FlowContext
    {
        return new FlowContext(
            flowRunId: 'load-test-run',
            definitionName: 'kb.canonical-index',
            input: array_merge(['tenant_id' => 'default'], $input),
            stepOutputs: [],
            dryRun: $dryRun,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeCanonicalDoc(array $overrides = []): KnowledgeDocument
    {
        return KnowledgeDocument::create(array_merge([
            'project_key' => 'acme',
            'source_type' => 'markdown',
            'title' => 'Decision X',
            'source_path' => 'decisions/dec-x.md',
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'access_scope' => 'public',
            'status' => 'active',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => str_repeat('b', 64),
            'metadata' => null,
            'doc_id' => 'DEC-0001',
            'slug' => 'dec-x',
            'canonical_type' => 'decision',
            'canonical_status' => 'accepted',
            'is_canonical' => true,
            'retrieval_priority' => 80,
            'frontmatter_json' => ['_derived' => ['related_slugs' => [], 'supersedes_slugs' => [], 'superseded_by_slugs' => []]],
        ], $overrides));
    }
}
