<?php

declare(strict_types=1);

namespace Tests\Feature\Flow;

use App\Flow\Definitions\PromotionFlow;
use App\Jobs\IngestDocumentJob;
use App\Models\KbCanonicalAudit;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Padosoft\LaravelFlow\ApprovalTokenManager;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\FlowRun;
use Tests\TestCase;

/**
 * End-to-end coverage for the {@see PromotionFlow} saga: validate →
 * approval-gate → write-markdown → dispatch-ingest with the
 * {@see \App\Flow\Compensators\DeleteCanonicalMarkdownCompensator} on
 * the write step.
 */
final class PromotionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('kb');
        config()->set('kb.promotion.enabled', true);
    }

    protected function tearDown(): void
    {
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
    }

    public function test_happy_path_pauses_then_resumes_on_approval(): void
    {
        Queue::fake();

        $run = Flow::execute(
            PromotionFlow::NAME,
            [
                'tenant_id' => 'default',
                'project_key' => 'acme',
                'markdown' => $this->validDecision('dec-x'),
                'title' => 'Decision X',
            ],
            FlowExecutionOptions::make(correlationId: 'default'),
        );

        // Should pause at the approval gate before any write hits disk.
        $this->assertSame(FlowRun::STATUS_PAUSED, $run->status);
        Storage::disk('kb')->assertMissing('decisions/dec-x.md');
        Queue::assertNotPushed(IngestDocumentJob::class);

        // Operator approves → engine resumes the saga.
        $approvals = $this->app->make(ApprovalTokenManager::class);
        $issued = $approvals->reissuePendingForStep($run->id, PromotionFlow::APPROVAL_STEP);
        $this->assertNotNull($issued);

        $resumed = Flow::resume($issued->plainTextToken, actor: ['name' => 'qa-bot']);

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $resumed->status);
        Storage::disk('kb')->assertExists('decisions/dec-x.md');
        Queue::assertPushed(IngestDocumentJob::class, function (IngestDocumentJob $job) {
            return $job->relativePath === 'decisions/dec-x.md' && $job->projectKey === 'acme';
        });

        // Editorial trail records the promotion.
        $this->assertDatabaseHas('kb_canonical_audit', [
            'project_key' => 'acme',
            'slug' => 'dec-x',
            'event_type' => 'promoted',
        ]);
    }

    public function test_validation_failure_aborts_flow_without_issuing_token(): void
    {
        Queue::fake();

        $run = Flow::execute(
            PromotionFlow::NAME,
            [
                'tenant_id' => 'default',
                'project_key' => 'acme',
                'markdown' => "---\ntype: decision\nstatus: accepted\n---\n\n# Missing slug",
            ],
            FlowExecutionOptions::make(correlationId: 'default'),
        );

        $this->assertNotSame(FlowRun::STATUS_PAUSED, $run->status);
        $this->assertSame('validate-frontmatter', $run->failedStep);
        Storage::disk('kb')->assertMissing('decisions/dec-x.md');
        Queue::assertNotPushed(IngestDocumentJob::class);
    }

    public function test_rejection_halts_flow_without_writing_to_disk(): void
    {
        Queue::fake();

        $run = Flow::execute(
            PromotionFlow::NAME,
            [
                'tenant_id' => 'default',
                'project_key' => 'acme',
                'markdown' => $this->validDecision('dec-x'),
            ],
            FlowExecutionOptions::make(correlationId: 'default'),
        );

        $this->assertSame(FlowRun::STATUS_PAUSED, $run->status);

        $approvals = $this->app->make(ApprovalTokenManager::class);
        $issued = $approvals->reissuePendingForStep($run->id, PromotionFlow::APPROVAL_STEP);
        $this->assertNotNull($issued);

        $rejected = Flow::reject($issued->plainTextToken, payload: ['reason' => 'no-go'], actor: ['name' => 'qa-bot']);

        $this->assertNotSame(FlowRun::STATUS_SUCCEEDED, $rejected->status);
        Storage::disk('kb')->assertMissing('decisions/dec-x.md');
        Queue::assertNotPushed(IngestDocumentJob::class);

        // Bridge listener writes a rejected_promotion audit row when the
        // approval gate's FlowStepFailed event fires.
        $this->assertDatabaseHas('kb_canonical_audit', [
            'project_key' => 'acme',
            'event_type' => 'rejected_promotion',
        ]);
    }

    public function test_rejection_audit_carries_correct_tenant_id_when_run_under_non_default_tenant(): void
    {
        // Iteration 3 — Copilot flagged that the FlowServiceProvider
        // bridge for `rejected_promotion` must explicitly stamp
        // tenant_id on the kb_canonical_audit row from the audit's own
        // tenant_id (defence-in-depth vs. lost TenantContext binding
        // when the listener fires off the request thread).
        Queue::fake();

        $tenants = $this->app->make(TenantContext::class);
        $tenants->set('tenant-x');

        $run = Flow::execute(
            PromotionFlow::NAME,
            [
                'tenant_id' => 'tenant-x',
                'project_key' => 'acme',
                'markdown' => $this->validDecision('dec-rej'),
            ],
            FlowExecutionOptions::make(correlationId: 'tenant-x'),
        );

        $approvals = $this->app->make(ApprovalTokenManager::class);
        $issued = $approvals->reissuePendingForStep($run->id, PromotionFlow::APPROVAL_STEP);
        $this->assertNotNull($issued);

        Flow::reject($issued->plainTextToken, payload: ['reason' => 'no-go'], actor: ['name' => 'qa']);

        // Drop the TenantContext binding so any auto-fill fallback would
        // surface as a wrong tenant_id on the audit row.
        $tenants->reset();

        $auditRow = KbCanonicalAudit::where('event_type', 'rejected_promotion')->first();
        $this->assertNotNull($auditRow);
        $this->assertSame('tenant-x', (string) $auditRow->tenant_id);
        $this->assertSame('acme', (string) $auditRow->project_key);
    }

    public function test_persisted_flow_rows_carry_tenant_id(): void
    {
        Queue::fake();

        $tenants = $this->app->make(TenantContext::class);
        $tenants->set('tenant-x');

        $run = Flow::execute(
            PromotionFlow::NAME,
            [
                'tenant_id' => 'tenant-x',
                'project_key' => 'acme',
                'markdown' => $this->validDecision('dec-tenant'),
            ],
            FlowExecutionOptions::make(correlationId: 'tenant-x'),
        );

        $runRow = DB::table('flow_runs')->where('id', $run->id)->first();
        $this->assertSame('tenant-x', $runRow->tenant_id);
    }

    private function validDecision(string $slug): string
    {
        return <<<MD
---
id: DEC-0001
slug: {$slug}
type: decision
status: accepted
---

# Decision {$slug}

Body.
MD;
    }
}
