<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentExecutionContext;
use App\Agent\Tools\AgentDocumentCatalogService;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AgentDocumentCatalogService backs the `list_knowledge_documents` agent
 * tool — a catalog/title lookup, deliberately distinct from
 * search_knowledge_base's semantic content search. Exists so a genuine
 * overview question ("what manuals do we have") has an actual answer
 * instead of the planner blindly guessing topics to search for.
 */
final class AgentDocumentCatalogServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('acme');
    }

    public function test_lists_both_canonical_and_non_canonical_documents(): void
    {
        $this->makeDoc('SizeCharts Manual', 'crm', isCanonical: false);
        $this->makeDoc('Decisione cache v2', 'crm', isCanonical: true, canonicalType: 'decision');

        $result = $this->service()->list($this->context('crm'), null, null);

        $this->assertSame(2, $result['count']);
        $titles = array_column($result['documents'], 'title');
        $this->assertContains('SizeCharts Manual', $titles);
        $this->assertContains('Decisione cache v2', $titles);
    }

    public function test_filters_by_title_keyword(): void
    {
        $this->makeDoc('SizeCharts Manual', 'crm', isCanonical: false);
        $this->makeDoc('Manuale Fatturazione', 'crm', isCanonical: false);

        $result = $this->service()->list($this->context('crm'), 'fattur', null);

        $this->assertSame(1, $result['count']);
        $this->assertSame('Manuale Fatturazione', $result['documents'][0]['title']);
    }

    public function test_title_filter_escapes_like_metacharacters(): void
    {
        // R19 — a literal `_` in the query must not act as a single-char
        // wildcard and match unrelated titles like "SizeXCharts".
        $this->makeDoc('Size_Charts', 'crm', isCanonical: false);
        $this->makeDoc('SizeXCharts', 'crm', isCanonical: false);

        $result = $this->service()->list($this->context('crm'), 'size_charts', null);

        $this->assertSame(1, $result['count']);
        $this->assertSame('Size_Charts', $result['documents'][0]['title']);
    }

    public function test_is_scoped_to_the_context_project(): void
    {
        $this->makeDoc('CRM manual', 'crm', isCanonical: false);
        $this->makeDoc('HR manual', 'hr', isCanonical: false);

        $result = $this->service()->list($this->context('crm'), null, null);

        $this->assertSame(1, $result['count']);
        $this->assertSame('CRM manual', $result['documents'][0]['title']);
    }

    public function test_excludes_archived_documents(): void
    {
        $this->makeDoc('Active manual', 'crm', isCanonical: false);
        $this->makeDoc('Archived manual', 'crm', isCanonical: false, status: 'archived');

        $result = $this->service()->list($this->context('crm'), null, null);

        $this->assertSame(1, $result['count']);
        $this->assertSame('Active manual', $result['documents'][0]['title']);
    }

    public function test_clamps_the_limit_to_the_configured_maximum(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->makeDoc("Manual {$i}", 'crm', isCanonical: false);
        }

        $result = $this->service()->list($this->context('crm'), null, 2);

        $this->assertSame(2, $result['count']);
    }

    private function service(): AgentDocumentCatalogService
    {
        return app(AgentDocumentCatalogService::class);
    }

    private function context(string $projectKey): AgentExecutionContext
    {
        return new AgentExecutionContext(
            runId: 'b03a7c27-daae-43cb-8ea2-fbe85cf66aaf',
            tenantId: 'acme',
            projectKey: $projectKey,
            channel: 'chat',
            actorType: 'user',
            actorId: '1',
            locale: 'it-IT',
            timezone: 'Europe/Rome',
        );
    }

    private function makeDoc(
        string $title,
        string $projectKey,
        bool $isCanonical,
        ?string $canonicalType = null,
        string $status = 'active',
    ): KnowledgeDocument {
        static $counter = 0;
        $counter++;

        return KnowledgeDocument::create([
            'project_key' => $projectKey,
            'source_path' => "docs/manual-{$counter}.md",
            'source_type' => 'markdown',
            'title' => $title,
            'mime_type' => 'text/markdown',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => $status,
            'document_hash' => hash('sha256', $title.$counter),
            'version_hash' => hash('sha256', $title.$counter),
            'metadata' => [],
            'is_canonical' => $isCanonical,
            'canonical_type' => $canonicalType,
            'canonical_status' => $isCanonical ? 'accepted' : null,
            'indexed_at' => now(),
        ]);
    }
}
