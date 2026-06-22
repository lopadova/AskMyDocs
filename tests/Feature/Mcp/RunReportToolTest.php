<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\KbRunReportTool;
use App\Models\KnowledgeDocument;
use App\Models\TabularCell;
use App\Models\TabularReview;
use App\Models\User;
use App\Support\TabularReview\CellFlag;
use App\Support\TabularReview\CellStatus;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Mcp\Request;
use Tests\TestCase;

/**
 * v8.19/W4 — the Agentic Knowledge Reports MCP read surface (R44 third surface).
 * Reads a saved report's computed matrix; tenant-scoped (R30); OFF-safe (R43).
 */
final class RunReportToolTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenants = app(TenantContext::class);
    }

    protected function tearDown(): void
    {
        $this->tenants->reset();
        parent::tearDown();
    }

    private function review(string $tenant): TabularReview
    {
        $this->tenants->set($tenant);

        $user = User::create([
            'name' => 'Owner',
            'email' => 'owner-'.uniqid().'@demo.local',
            'password' => Hash::make('secret-password'),
        ]);

        return TabularReview::create([
            'tenant_id' => $tenant,
            'project_key' => 'eng',
            'user_id' => $user->id,
            'title' => 'Governance Audit',
            'columns_config' => [
                ['name' => 'Canonical?', 'format' => 'yes_no', 'agent' => 'graph', 'metric' => 'is_canonical'],
                ['name' => 'Status', 'format' => 'text', 'agent' => 'graph', 'metric' => 'canonical_status'],
            ],
        ]);
    }

    private function doc(string $tenant): int
    {
        return (int) KnowledgeDocument::create([
            'tenant_id' => $tenant,
            'project_key' => 'eng',
            'source_type' => 'markdown',
            'title' => 'Doc '.uniqid(),
            'source_path' => 'kb/'.uniqid().'.md',
            'document_hash' => hash('sha256', uniqid('', true)),
            'version_hash' => hash('sha256', uniqid('', true)),
            'status' => 'active',
        ])->id;
    }

    private function cell(string $tenant, int $reviewId, int $docId, int $col, string $summary, CellFlag $flag): void
    {
        TabularCell::create([
            'tenant_id' => $tenant,
            'review_id' => $reviewId,
            'document_id' => $docId,
            'column_index' => $col,
            'content' => ['summary' => $summary, 'flag' => $flag->value, 'reasoning' => '', 'citations' => []],
            'status' => CellStatus::READY->value,
            'flag' => $flag->value,
            'generated_at' => now(),
        ]);
    }

    private function invoke(array $args): array
    {
        $response = (new KbRunReportTool())->handle(new Request($args), $this->tenants);

        return json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_reads_the_matrix_with_flag_summary_for_the_active_tenant(): void
    {
        $review = $this->review('tenant-a');
        $d1 = $this->doc('tenant-a');
        $d2 = $this->doc('tenant-a');
        $this->cell('tenant-a', $review->id, $d1, 0, 'Yes', CellFlag::GREEN);
        $this->cell('tenant-a', $review->id, $d1, 1, 'accepted', CellFlag::GREEN);
        $this->cell('tenant-a', $review->id, $d2, 0, 'No', CellFlag::GREY);
        $this->cell('tenant-a', $review->id, $d2, 1, 'deprecated', CellFlag::RED);

        $this->tenants->set('tenant-a');
        $payload = $this->invoke(['review_id' => $review->id]);

        $this->assertTrue($payload['available']);
        $this->assertSame('Governance Audit', $payload['report']['title']);
        $this->assertCount(2, $payload['report']['columns']);
        $this->assertSame('graph', $payload['report']['columns'][0]['agent']);
        // The full agentic column identity (metric) is preserved.
        $this->assertSame('is_canonical', $payload['report']['columns'][0]['metric']);
        $this->assertSame('canonical_status', $payload['report']['columns'][1]['metric']);
        $this->assertSame(2, $payload['report']['summary']['documents']);
        $this->assertSame(2, $payload['report']['summary']['total_documents']);
        $this->assertSame(2, $payload['report']['summary']['flag_counts']['green']);
        $this->assertSame(1, $payload['report']['summary']['flag_counts']['red']);
        $this->assertSame(1, $payload['report']['summary']['flag_counts']['grey']);
    }

    public function test_max_rows_caps_the_returned_documents_but_reports_the_total(): void
    {
        $review = $this->review('tenant-a');
        $d1 = $this->doc('tenant-a');
        $d2 = $this->doc('tenant-a');
        $this->cell('tenant-a', $review->id, $d1, 0, 'Yes', CellFlag::GREEN);
        $this->cell('tenant-a', $review->id, $d2, 0, 'No', CellFlag::GREY);

        $this->tenants->set('tenant-a');
        $payload = $this->invoke(['review_id' => $review->id, 'max_rows' => 1]);

        $this->assertCount(1, $payload['report']['rows']);
        $this->assertSame(1, $payload['report']['summary']['documents']);
        $this->assertSame(2, $payload['report']['summary']['total_documents']);
    }

    public function test_cross_tenant_review_is_invisible(): void
    {
        $review = $this->review('tenant-b');
        $this->cell('tenant-b', $review->id, $this->doc('tenant-b'), 0, 'Yes', CellFlag::GREEN);

        // Active tenant is tenant-a: tenant-b's review must not resolve.
        $this->tenants->set('tenant-a');
        $payload = $this->invoke(['review_id' => $review->id]);

        $this->assertFalse($payload['available']);
        $this->assertNull($payload['report']);
    }

    public function test_missing_review_returns_unavailable_without_throwing(): void
    {
        $this->tenants->set('tenant-a');
        $payload = $this->invoke(['review_id' => 999999]);

        $this->assertFalse($payload['available']);
        $this->assertNull($payload['report']);
    }
}
