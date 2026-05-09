<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Steps\Graph;

use App\Flow\Steps\Graph\CountCanonicalDocumentsStep;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelFlow\Exceptions\FlowInputException;
use Padosoft\LaravelFlow\FlowContext;
use Tests\TestCase;

final class CountCanonicalDocumentsStepTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
    }

    public function test_counts_canonical_only(): void
    {
        $this->seedDoc('tenant-a', 'p', 'a.md', isCanonical: true);
        $this->seedDoc('tenant-a', 'p', 'b.md', isCanonical: false);

        $step = $this->app->make(CountCanonicalDocumentsStep::class);
        $result = $step->execute($this->context('tenant-a'));

        $this->assertSame(1, $result->output['canonical_count']);
    }

    public function test_throws_on_missing_tenant_id(): void
    {
        $step = $this->app->make(CountCanonicalDocumentsStep::class);
        $context = new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.rebuild-graph',
            input: [],
        );

        $this->expectException(FlowInputException::class);
        $step->execute($context);
    }

    public function test_tenant_isolation(): void
    {
        // Distinct projects to dodge the SQLite test schema's
        // (project_key, slug) UNIQUE while preserving the cross-tenant
        // isolation assertion.
        $this->seedDoc('tenant-a', 'pa', 'a.md', isCanonical: true);
        $this->seedDoc('tenant-b', 'pb', 'b.md', isCanonical: true);

        $step = $this->app->make(CountCanonicalDocumentsStep::class);
        $result = $step->execute($this->context('tenant-a'));

        $this->assertSame(1, $result->output['canonical_count']);
    }

    public function test_project_filter(): void
    {
        $this->seedDoc('tenant-a', 'project-1', 'a.md', isCanonical: true);
        $this->seedDoc('tenant-a', 'project-2', 'b.md', isCanonical: true);

        $step = $this->app->make(CountCanonicalDocumentsStep::class);
        $result = $step->execute($this->context('tenant-a', 'project-1'));

        $this->assertSame(1, $result->output['canonical_count']);
    }

    private function context(string $tenantId, string $projectKey = ''): FlowContext
    {
        return new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.rebuild-graph',
            input: ['tenant_id' => $tenantId, 'project_key' => $projectKey],
        );
    }

    private function seedDoc(string $tenantId, string $projectKey, string $sourcePath, bool $isCanonical): KnowledgeDocument
    {
        $tc = $this->app->make(TenantContext::class);
        $tc->set($tenantId);
        return KnowledgeDocument::create([
            'project_key' => $projectKey,
            'source_type' => 'markdown',
            'title' => 'X',
            'source_path' => $sourcePath,
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'access_scope' => 'public',
            'status' => 'active',
            'document_hash' => hash('sha256', $tenantId.$sourcePath.'doc'),
            'version_hash' => hash('sha256', $tenantId.$sourcePath.'ver'),
            'metadata' => null,
            'is_canonical' => $isCanonical,
            'doc_id' => $isCanonical ? 'DOC-'.$sourcePath : null,
            'slug' => $isCanonical ? 'slug-'.basename($sourcePath, '.md') : null,
            'canonical_type' => $isCanonical ? 'decision' : null,
            'canonical_status' => $isCanonical ? 'accepted' : null,
        ]);
    }
}
