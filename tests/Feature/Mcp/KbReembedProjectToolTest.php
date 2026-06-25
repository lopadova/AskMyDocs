<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Jobs\ReembedDocumentJob;
use App\Mcp\Tools\KbReembedProjectTool;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Pii\ReembedProjectService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Request;
use Tests\TestCase;

/**
 * v8.23 (Ciclo 4, PR5) — the re-embed-on-policy-change MCP tool (R44).
 */
final class KbReembedProjectToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->reset();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function doc(string $project): void
    {
        KnowledgeDocument::create([
            'project_key' => $project, 'source_type' => 'markdown', 'title' => 'Doc',
            'source_path' => 'docs/'.uniqid().'.md', 'language' => 'en', 'access_scope' => 'internal',
            'status' => 'active', 'document_hash' => hash('sha256', uniqid()), 'version_hash' => hash('sha256', uniqid()),
        ]);
    }

    public function test_it_queues_reembed_jobs_and_reports_the_count(): void
    {
        Queue::fake();
        $this->doc('support');
        $this->doc('support');

        $response = (new KbReembedProjectTool())->handle(
            new Request(['project_key' => 'support']),
            app(ReembedProjectService::class),
            app(TenantContext::class),
        );
        $payload = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(2, $payload['queued']);
        $this->assertSame('support', $payload['project_key']);
        Queue::assertPushed(ReembedDocumentJob::class, 2);
    }

    public function test_it_errors_on_a_blank_project_key(): void
    {
        $response = (new KbReembedProjectTool())->handle(
            new Request(['project_key' => '   ']),
            app(ReembedProjectService::class),
            app(TenantContext::class),
        );

        $this->assertStringContainsString('project_key is required', (string) $response->content());
    }
}
