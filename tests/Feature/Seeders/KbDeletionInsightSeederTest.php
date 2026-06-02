<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\KbDocAnalysis;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Database\Seeders\KbDeletionInsightSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v8.8/W2 — guards the `KbDeletionInsightSeeder` the deletion-impact E2E
 * depends on (R22: a broken seeder surfaces as an opaque Playwright timeout,
 * so validate it here where the failure is legible). Idempotent.
 */
final class KbDeletionInsightSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
    }

    public function test_it_seeds_a_soft_deleted_doc_and_a_deleted_analysis(): void
    {
        (new KbDeletionInsightSeeder())->run();

        $doc = KnowledgeDocument::withTrashed()
            ->where('source_path', 'decisions/dec-cache-v1.md')
            ->sole();
        $this->assertTrue($doc->trashed(), 'the seeded doc must be soft-deleted');
        $this->assertSame('default', $doc->tenant_id);

        $row = KbDocAnalysis::query()->forTenant('default')->where('knowledge_document_id', $doc->id)->sole();
        $this->assertSame(KbDocAnalysis::TRIGGER_DELETED, $row->trigger);
        $this->assertSame(KbDocAnalysis::STATUS_COMPLETED, $row->status);
        $this->assertSame(1, $row->impacted_count);
        $this->assertSame('default', $row->tenant_id);
        $this->assertSame('update: drop the link to dec-cache-v1', $row->analysis_json['impacted_docs'][0]['suggested_action']);
    }

    public function test_running_it_twice_is_idempotent(): void
    {
        (new KbDeletionInsightSeeder())->run();
        (new KbDeletionInsightSeeder())->run();

        $this->assertSame(1, KnowledgeDocument::withTrashed()->forTenant('default')->where('source_path', 'decisions/dec-cache-v1.md')->count());
        $this->assertSame(1, KbDocAnalysis::query()->forTenant('default')->where('trigger', KbDocAnalysis::TRIGGER_DELETED)->count());
    }
}
