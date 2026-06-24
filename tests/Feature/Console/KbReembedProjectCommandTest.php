<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Jobs\ReembedDocumentJob;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * v8.23 (Ciclo 4, PR5) — the `kb:reembed-project` CLI surface.
 */
final class KbReembedProjectCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->reset();
    }

    private function doc(string $tenant, string $project): void
    {
        $tenants = app(TenantContext::class);
        $tenants->set($tenant);
        KnowledgeDocument::create([
            'tenant_id' => $tenant, 'project_key' => $project, 'source_type' => 'markdown', 'title' => 'Doc',
            'source_path' => 'docs/'.uniqid().'.md', 'language' => 'en', 'access_scope' => 'internal',
            'status' => 'active', 'document_hash' => hash('sha256', uniqid()), 'version_hash' => hash('sha256', uniqid()),
        ]);
        $tenants->reset();
    }

    public function test_it_fails_fast_on_a_blank_project(): void
    {
        Queue::fake();
        $this->artisan('kb:reembed-project', ['project' => '   ', '--tenant' => 'acme'])
            ->assertFailed();
        Queue::assertNothingPushed();
    }

    public function test_it_queues_reembed_jobs_for_the_project(): void
    {
        Queue::fake();
        $this->doc('acme', 'support');
        $this->doc('acme', 'support');
        $this->doc('globex', 'support'); // other tenant — must not be queued

        $this->artisan('kb:reembed-project', ['project' => 'support', '--tenant' => 'acme'])
            ->expectsOutputToContain('Queued 2')
            ->assertSuccessful();

        Queue::assertPushed(ReembedDocumentJob::class, 2);
    }
}
