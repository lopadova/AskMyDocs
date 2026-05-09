<?php

namespace Tests\Feature\Api;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Http\Controllers\Api\KbPromotionController;
use App\Jobs\IngestDocumentJob;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\FlowStepResult;
use Padosoft\LaravelFlow\Models\FlowApprovalRecord;
use Tests\TestCase;

class KbPromotionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('kb');
        // Register routes without auth middleware for isolated testing.
        Route::post('/api/kb/promotion/suggest', [KbPromotionController::class, 'suggest']);
        Route::post('/api/kb/promotion/candidates', [KbPromotionController::class, 'candidates']);
        Route::post('/api/kb/promotion/promote', [KbPromotionController::class, 'promote']);
        Route::post('/api/kb/promotion/{approvalId}/approve', [KbPromotionController::class, 'approve']);
        Route::post('/api/kb/promotion/{approvalId}/reject', [KbPromotionController::class, 'reject']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // -------------------------------------------------------------
    // /candidates — validate only, writes nothing
    // -------------------------------------------------------------

    public function test_candidates_returns_valid_true_for_well_formed_markdown(): void
    {
        $response = $this->postJson('/api/kb/promotion/candidates', [
            'markdown' => $this->validDecisionMarkdown('dec-x'),
        ]);

        $response->assertOk()
            ->assertJson([
                'valid' => true,
                'parsed' => [
                    'slug' => 'dec-x',
                    'type' => 'decision',
                    'status' => 'accepted',
                ],
            ]);
    }

    public function test_candidates_returns_422_for_markdown_without_frontmatter(): void
    {
        $response = $this->postJson('/api/kb/promotion/candidates', [
            'markdown' => "# Just a heading\n\nNo frontmatter.",
        ]);

        $response->assertStatus(422)
            ->assertJson(['valid' => false])
            ->assertJsonPath('errors.frontmatter.0', 'No YAML frontmatter block detected at the top of the document.');
    }

    public function test_candidates_returns_422_for_missing_slug(): void
    {
        $response = $this->postJson('/api/kb/promotion/candidates', [
            'markdown' => "---\ntype: decision\nstatus: accepted\n---\n\n# Body",
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('valid', false)
            ->assertJsonStructure(['errors' => ['slug']]);
    }

    public function test_candidates_validates_request_body(): void
    {
        $this->postJson('/api/kb/promotion/candidates', [])
            ->assertStatus(422);
    }

    // -------------------------------------------------------------
    // /promote — writes markdown + dispatches ingest
    // -------------------------------------------------------------

    public function test_promote_pauses_at_approval_gate_and_returns_token(): void
    {
        // v4.2/W2 PR #116 — `promote` now starts the PromotionFlow which
        // pauses at the approval-gate step. The 202 response carries an
        // `approval` block with the single-use plain-text token + the
        // approve/reject URLs the operator must POST to. The disk write
        // happens only AFTER the operator approves.
        Queue::fake();

        $response = $this->postJson('/api/kb/promotion/promote', [
            'project_key' => 'acme',
            'markdown' => $this->validDecisionMarkdown('dec-cache-v2'),
        ]);

        $response->assertStatus(202)
            ->assertJson([
                'status' => 'paused',
                'doc_id' => 'DEC-2026-0001',
                'slug' => 'dec-cache-v2',
            ])
            ->assertJsonStructure([
                'status',
                'flow_run_id',
                'doc_id',
                'slug',
                'approval' => [
                    'approval_id',
                    'token',
                    'expires_at',
                    'approve_url',
                    'reject_url',
                ],
            ]);

        // Pre-approval: no disk write, no ingest dispatched.
        Storage::disk('kb')->assertMissing('decisions/dec-cache-v2.md');
        Queue::assertNotPushed(IngestDocumentJob::class);
    }

    public function test_approve_endpoint_writes_file_and_dispatches_ingest(): void
    {
        // Drives the full happy-path: dispatch -> token -> approve -> the
        // engine resumes the saga -> write-markdown + dispatch-ingest run.
        Queue::fake();

        $promote = $this->postJson('/api/kb/promotion/promote', [
            'project_key' => 'acme',
            'markdown' => $this->validDecisionMarkdown('dec-cache-v2'),
        ])->assertStatus(202);

        $approvalId = $promote->json('approval.approval_id');
        $token = $promote->json('approval.token');

        $approve = $this->postJson("/api/kb/promotion/{$approvalId}/approve", [
            'token' => $token,
            'actor' => ['name' => 'qa-bot'],
        ]);

        $approve->assertStatus(200)
            ->assertJson([
                'status' => 'accepted',
                'approval_id' => $approvalId,
                'path' => 'decisions/dec-cache-v2.md',
            ]);

        Storage::disk('kb')->assertExists('decisions/dec-cache-v2.md');
        Queue::assertPushed(IngestDocumentJob::class, fn ($job) => $job->relativePath === 'decisions/dec-cache-v2.md');
    }

    public function test_reject_endpoint_halts_flow_without_writing_to_disk(): void
    {
        Queue::fake();

        $promote = $this->postJson('/api/kb/promotion/promote', [
            'project_key' => 'acme',
            'markdown' => $this->validDecisionMarkdown('dec-cache-v2'),
        ])->assertStatus(202);

        $approvalId = $promote->json('approval.approval_id');
        $token = $promote->json('approval.token');

        $reject = $this->postJson("/api/kb/promotion/{$approvalId}/reject", [
            'token' => $token,
            'reason' => 'not now',
        ]);

        $reject->assertStatus(200);
        Storage::disk('kb')->assertMissing('decisions/dec-cache-v2.md');
        Queue::assertNotPushed(IngestDocumentJob::class);
    }

    public function test_approve_returns_403_when_token_does_not_match_approval_id(): void
    {
        // B.1 — approvalId from the URL must match the token from the
        // body. Mismatch returns a uniform 403 without leaking which
        // side failed (id vs token).
        Queue::fake();

        $promote = $this->postJson('/api/kb/promotion/promote', [
            'project_key' => 'acme',
            'markdown' => $this->validDecisionMarkdown('dec-cache-v2'),
        ])->assertStatus(202);

        $approvalId = $promote->json('approval.approval_id');

        $approve = $this->postJson("/api/kb/promotion/{$approvalId}/approve", [
            'token' => 'completely-bogus-token-that-does-not-match',
        ]);

        $approve->assertStatus(403)->assertJsonPath('error', 'forbidden');
        Storage::disk('kb')->assertMissing('decisions/dec-cache-v2.md');
        Queue::assertNotPushed(IngestDocumentJob::class);
    }

    public function test_approve_returns_403_when_approval_id_unknown(): void
    {
        // B.1 — unknown approval_id returns the same uniform 403 as a
        // token mismatch (no internal-state distinction leaked).
        Queue::fake();

        $promote = $this->postJson('/api/kb/promotion/promote', [
            'project_key' => 'acme',
            'markdown' => $this->validDecisionMarkdown('dec-cache-v2'),
        ])->assertStatus(202);

        $token = $promote->json('approval.token');

        $approve = $this->postJson('/api/kb/promotion/00000000-0000-0000-0000-000000000000/approve', [
            'token' => $token,
        ]);

        $approve->assertStatus(403)->assertJsonPath('error', 'forbidden');
    }

    public function test_reject_returns_403_when_token_does_not_match_approval_id(): void
    {
        // B.1 — same guard as approve.
        Queue::fake();

        $promote = $this->postJson('/api/kb/promotion/promote', [
            'project_key' => 'acme',
            'markdown' => $this->validDecisionMarkdown('dec-cache-v2'),
        ])->assertStatus(202);

        $approvalId = $promote->json('approval.approval_id');

        $reject = $this->postJson("/api/kb/promotion/{$approvalId}/reject", [
            'token' => 'completely-bogus-token-that-does-not-match',
            'reason' => 'phishing attempt',
        ]);

        $reject->assertStatus(403)->assertJsonPath('error', 'forbidden');
    }

    public function test_approve_returns_403_when_approval_already_consumed(): void
    {
        // Iteration 3 — strengthened approvalIdMatchesToken() must reject
        // a row whose `consumed_at` is non-null (the token was already
        // spent). Replay attacks must NOT pass the gate.
        Queue::fake();

        $promote = $this->postJson('/api/kb/promotion/promote', [
            'project_key' => 'acme',
            'markdown' => $this->validDecisionMarkdown('dec-cache-v2'),
        ])->assertStatus(202);

        $approvalId = $promote->json('approval.approval_id');
        $token = $promote->json('approval.token');

        // Mark the approval as already-consumed (simulates a successful
        // first approve() landing — the second call should 403).
        FlowApprovalRecord::query()->where('id', $approvalId)
            ->update(['consumed_at' => Carbon::now()]);

        $approve = $this->postJson("/api/kb/promotion/{$approvalId}/approve", [
            'token' => $token,
        ]);

        $approve->assertStatus(403)->assertJsonPath('error', 'forbidden');
    }

    public function test_approve_returns_403_when_approval_already_decided(): void
    {
        // Iteration 3 — `decided_at` is set after Flow::resume()/reject()
        // records the decision. A token presented after the decision was
        // recorded must 403.
        Queue::fake();

        $promote = $this->postJson('/api/kb/promotion/promote', [
            'project_key' => 'acme',
            'markdown' => $this->validDecisionMarkdown('dec-cache-v2'),
        ])->assertStatus(202);

        $approvalId = $promote->json('approval.approval_id');
        $token = $promote->json('approval.token');

        FlowApprovalRecord::query()->where('id', $approvalId)
            ->update(['decided_at' => Carbon::now()]);

        $approve = $this->postJson("/api/kb/promotion/{$approvalId}/approve", [
            'token' => $token,
        ]);

        $approve->assertStatus(403)->assertJsonPath('error', 'forbidden');
    }

    public function test_approve_returns_403_when_approval_expired(): void
    {
        // Iteration 3 — `expires_at` in the past must 403 even if the
        // status column still says 'pending'.
        Queue::fake();

        $promote = $this->postJson('/api/kb/promotion/promote', [
            'project_key' => 'acme',
            'markdown' => $this->validDecisionMarkdown('dec-cache-v2'),
        ])->assertStatus(202);

        $approvalId = $promote->json('approval.approval_id');
        $token = $promote->json('approval.token');

        FlowApprovalRecord::query()->where('id', $approvalId)
            ->update(['expires_at' => Carbon::now()->subMinute()]);

        $approve = $this->postJson("/api/kb/promotion/{$approvalId}/approve", [
            'token' => $token,
        ]);

        $approve->assertStatus(403)->assertJsonPath('error', 'forbidden');
    }

    public function test_approve_returns_403_when_status_not_pending(): void
    {
        // Iteration 3 — status != 'pending' must 403.
        Queue::fake();

        $promote = $this->postJson('/api/kb/promotion/promote', [
            'project_key' => 'acme',
            'markdown' => $this->validDecisionMarkdown('dec-cache-v2'),
        ])->assertStatus(202);

        $approvalId = $promote->json('approval.approval_id');
        $token = $promote->json('approval.token');

        FlowApprovalRecord::query()->where('id', $approvalId)
            ->update(['status' => FlowApprovalRecord::STATUS_REJECTED]);

        $approve = $this->postJson("/api/kb/promotion/{$approvalId}/approve", [
            'token' => $token,
        ]);

        $approve->assertStatus(403)->assertJsonPath('error', 'forbidden');
    }

    public function test_reject_returns_403_when_approval_already_consumed(): void
    {
        // Iteration 3 — same gate applies symmetrically to the reject
        // endpoint (rejection of an already-consumed token must 403).
        Queue::fake();

        $promote = $this->postJson('/api/kb/promotion/promote', [
            'project_key' => 'acme',
            'markdown' => $this->validDecisionMarkdown('dec-cache-v2'),
        ])->assertStatus(202);

        $approvalId = $promote->json('approval.approval_id');
        $token = $promote->json('approval.token');

        FlowApprovalRecord::query()->where('id', $approvalId)
            ->update(['consumed_at' => Carbon::now()]);

        $reject = $this->postJson("/api/kb/promotion/{$approvalId}/reject", [
            'token' => $token,
        ]);

        $reject->assertStatus(403)->assertJsonPath('error', 'forbidden');
    }

    public function test_approve_returns_403_when_approval_belongs_to_another_tenant(): void
    {
        // Iteration 4 (PR #116) — R30 cross-tenant defence. An approval
        // row created under tenant-b MUST NOT be addressable by an
        // approve call running under tenant-a, even if the attacker
        // somehow obtained the plain-text token + approval id.
        Queue::fake();

        // Create the approval row directly under tenant-b. The
        // controller binds against TenantContext::current() which the
        // test boots as 'default'. We assert the lookup is filtered by
        // tenant_id (= 'default' in this test) and so the tenant-b row
        // is invisible regardless of token correctness.
        $rawToken = bin2hex(random_bytes(16));
        $approvalId = (string) Str::uuid();
        $runId = (string) Str::uuid();
        // Seed the parent flow_runs row first (FK).
        DB::table('flow_runs')->insert([
            'id' => $runId,
            'tenant_id' => 'tenant-b',
            'definition_name' => \App\Flow\Definitions\PromotionFlow::NAME,
            'status' => 'paused',
            'dry_run' => false,
            'started_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
        FlowApprovalRecord::query()->create([
            'id' => $approvalId,
            'tenant_id' => 'tenant-b',
            'run_id' => $runId,
            'step_name' => \App\Flow\Definitions\PromotionFlow::APPROVAL_STEP,
            'status' => FlowApprovalRecord::STATUS_PENDING,
            'token_hash' => \Padosoft\LaravelFlow\ApprovalTokenManager::hashToken($rawToken),
            'expires_at' => Carbon::now()->addHour(),
        ]);

        // Active tenant is 'default' (TenantContext defaults). Try to
        // approve the tenant-b row from this scope.
        $approve = $this->postJson("/api/kb/promotion/{$approvalId}/approve", [
            'token' => $rawToken,
        ]);

        $approve->assertStatus(403)->assertJsonPath('error', 'forbidden');

        // Token must remain unconsumed — nothing happened.
        $row = FlowApprovalRecord::query()->where('id', $approvalId)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->consumed_at);
        $this->assertSame(FlowApprovalRecord::STATUS_PENDING, (string) $row->status);
    }

    public function test_approve_returns_403_when_step_name_does_not_match(): void
    {
        // Iteration 4 (PR #116) — defence-in-depth pinning to
        // PromotionFlow::APPROVAL_STEP. A token from a DIFFERENT flow's
        // approval step (e.g. a future approval gate on a different
        // flow definition) must not be replayable on the kb.promote
        // endpoints.
        Queue::fake();

        $rawToken = bin2hex(random_bytes(16));
        $approvalId = (string) Str::uuid();
        $runId = (string) Str::uuid();
        $tenantId = app(TenantContext::class)->current();
        DB::table('flow_runs')->insert([
            'id' => $runId,
            'tenant_id' => $tenantId,
            'definition_name' => 'some.other.flow',
            'status' => 'paused',
            'dry_run' => false,
            'started_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
        FlowApprovalRecord::query()->create([
            'id' => $approvalId,
            'tenant_id' => $tenantId,
            'run_id' => $runId,
            'step_name' => 'some-other-flow-approval-step', // NOT promotion's step
            'status' => FlowApprovalRecord::STATUS_PENDING,
            'token_hash' => \Padosoft\LaravelFlow\ApprovalTokenManager::hashToken($rawToken),
            'expires_at' => Carbon::now()->addHour(),
        ]);

        $approve = $this->postJson("/api/kb/promotion/{$approvalId}/approve", [
            'token' => $rawToken,
        ]);

        $approve->assertStatus(403)->assertJsonPath('error', 'forbidden');
    }

    public function test_approve_returns_500_when_write_step_output_missing_relative_path(): void
    {
        // Iteration 4 (PR #116) — B.1 + R14. The saga reports SUCCEEDED
        // but write-markdown's output bag is missing relative_path.
        // Returning 200 with `path: null` would silently hand the
        // operator a useless success envelope; surface a 500 with a
        // correlation_id instead.
        Queue::fake();

        // First, run a real promote to get a valid pending approval +
        // token shape we can wire into the mocked Flow::resume() reply.
        $promote = $this->postJson('/api/kb/promotion/promote', [
            'project_key' => 'acme',
            'markdown' => $this->validDecisionMarkdown('dec-cache-v2'),
        ])->assertStatus(202);
        $approvalId = $promote->json('approval.approval_id');
        $token = $promote->json('approval.token');

        // Mock Flow::resume() to return a SUCCEEDED FlowRun whose
        // write-markdown step output has no `relative_path`.
        $fakeRun = new FlowRun(
            id: 'run-fake-1',
            definitionName: \App\Flow\Definitions\PromotionFlow::NAME,
            dryRun: false,
            startedAt: new \DateTimeImmutable(),
        );
        $fakeRun->markSucceeded(new \DateTimeImmutable());
        $fakeRun->recordStepResult(
            'write-markdown',
            FlowStepResult::success([
                // INTENTIONALLY missing `relative_path` — defensive contract test.
                'doc_id' => 'DEC-2026-0001',
            ]),
        );

        Flow::shouldReceive('resume')->once()->andReturn($fakeRun);

        $approve = $this->postJson("/api/kb/promotion/{$approvalId}/approve", [
            'token' => $token,
        ]);

        $approve->assertStatus(500)
            ->assertJsonPath('error', 'incomplete_promotion')
            ->assertJsonStructure(['correlation_id']);
    }

    public function test_promote_rejects_invalid_frontmatter_with_422(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/kb/promotion/promote', [
            'project_key' => 'acme',
            'markdown' => "---\ntype: decision\nstatus: accepted\n---\n\n# No slug",
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', 'invalid_frontmatter');

        Queue::assertNotPushed(IngestDocumentJob::class);
        Storage::disk('kb')->assertMissing('decisions/no-slug.md');
    }

    public function test_promote_returns_503_when_promotion_disabled(): void
    {
        config()->set('kb.promotion.enabled', false);

        $response = $this->postJson('/api/kb/promotion/promote', [
            'project_key' => 'acme',
            'markdown' => $this->validDecisionMarkdown('dec-x'),
        ]);

        $response->assertStatus(503)->assertJsonPath('error', 'promotion_disabled');
    }

    public function test_promote_validates_required_project_key(): void
    {
        $this->postJson('/api/kb/promotion/promote', [
            'markdown' => $this->validDecisionMarkdown('dec-x'),
        ])->assertStatus(422);
    }

    // -------------------------------------------------------------
    // /suggest — LLM extracts candidates
    // -------------------------------------------------------------

    public function test_suggest_proxies_to_promotion_service(): void
    {
        $this->mockAiManagerReturning(json_encode([
            'candidates' => [
                ['type' => 'decision', 'slug_proposal' => 'dec-a', 'title_proposal' => 'A', 'reason' => 'r', 'related' => []],
            ],
        ]));

        $response = $this->postJson('/api/kb/promotion/suggest', [
            'transcript' => 'We decided to A.',
            'project_key' => 'acme',
        ]);

        $response->assertOk()
            ->assertJsonCount(1, 'candidates')
            ->assertJsonPath('candidates.0.type', 'decision');
    }

    public function test_suggest_validates_request_body(): void
    {
        $this->postJson('/api/kb/promotion/suggest', [])
            ->assertStatus(422);
    }

    public function test_suggest_returns_503_when_promotion_disabled(): void
    {
        config()->set('kb.promotion.enabled', false);

        $this->postJson('/api/kb/promotion/suggest', ['transcript' => 't'])
            ->assertStatus(503)
            ->assertJsonPath('error', 'promotion_disabled');
    }

    // -------------------------------------------------------------
    // helpers
    // -------------------------------------------------------------

    private function validDecisionMarkdown(string $slug): string
    {
        return <<<MD
---
id: DEC-2026-0001
slug: {$slug}
type: decision
status: accepted
---

# Decision {$slug}

Body.
MD;
    }

    private function mockAiManagerReturning(string $content): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chat')->andReturn(new AiResponse(
            content: $content,
            provider: 'openai',
            model: 'gpt-4o-mini',
        ));
        $this->app->instance(AiManager::class, $ai);
    }
}
