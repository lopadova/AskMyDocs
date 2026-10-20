<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\ActionApprovalException;
use App\Actions\ActionApprovalReceipt;
use App\Actions\ActionApprovalService;
use App\Actions\ActionIntent;
use App\Flow\Definitions\PromotionFlow;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Padosoft\LaravelFlow\ApprovalTokenManager;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Tests\TestCase;

final class ActionApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('kb');
        $this->app->make(TenantContext::class)->set('test-tenant');
    }

    public function test_each_pending_confirmation_has_a_distinct_execution_identity(): void
    {
        $run = $this->pausedRun();
        $service = $this->app->make(ActionApprovalService::class);
        $intent = $this->intent();

        $first = $service->request($intent, $run->id);
        $second = $service->request($intent, $run->id);

        $this->assertNotSame($first->executionId, $second->executionId);
        $this->assertCount(2, DB::table('flow_approvals')->where('step_name', ActionApprovalService::APPROVAL_STEP)->get());
        $payloads = DB::table('flow_approvals')->where('step_name', ActionApprovalService::APPROVAL_STEP)->pluck('payload');
        foreach ($payloads as $payload) {
            $this->assertArrayNotHasKey('args', (array) json_decode((string) $payload, true));
        }
    }

    public function test_digest_change_or_replay_invalidates_approval(): void
    {
        $run = $this->pausedRun();
        $service = $this->app->make(ActionApprovalService::class);
        $intent = $this->intent();
        $pending = $service->request($intent, $run->id);

        $receipt = $service->approve($pending->plainTextToken, $intent, static fn (ActionIntent $value): bool => true);
        $this->assertSame($pending->executionId, $receipt->executionId);
        $this->assertTrue($service->verifyForExecution($receipt, $intent, static fn (ActionIntent $value): bool => true));

        $changed = ActionIntent::fromModelProposal(
            actionId: $intent->actionId,
            tenantId: $intent->tenantId,
            principalId: $intent->principalId,
            proposal: [
                'resource' => $intent->resource,
                'effect' => $intent->effect,
                'target' => 'resource/changed',
                'args' => $intent->args,
            ],
            grant: $intent->grant,
            assurance: $intent->assurance,
        );
        $this->assertFalse($service->verifyForExecution($receipt, $changed, static fn (ActionIntent $value): bool => true));
        $this->expectException(ActionApprovalException::class);
        $service->approve($pending->plainTextToken, $intent, static fn (ActionIntent $value): bool => true);
    }

    public function test_acl_revocation_after_approval_denies_before_effect(): void
    {
        $run = $this->pausedRun();
        $service = $this->app->make(ActionApprovalService::class);
        $intent = $this->intent();
        $pending = $service->request($intent, $run->id);
        $receipt = $service->approve($pending->plainTextToken, $intent, static fn (ActionIntent $value): bool => true);

        $this->assertFalse($service->verifyForExecution($receipt, $intent, static fn (ActionIntent $value): bool => false));
    }

    public function test_shifting_a_delimiter_between_fields_does_not_reuse_an_approval(): void
    {
        $run = $this->pausedRun();
        $service = $this->app->make(ActionApprovalService::class);
        $approved = $this->intent(resource: 'a|b', effect: 'c');
        $shifted = $this->intent(resource: 'a', effect: 'b|c');
        $pending = $service->request($approved, $run->id);
        $receipt = $service->approve($pending->plainTextToken, $approved, static fn (ActionIntent $value): bool => true);

        $this->assertTrue($service->verifyForExecution($receipt, $approved, static fn (ActionIntent $value): bool => true));
        $this->assertFalse($service->verifyForExecution($receipt, $shifted, static fn (ActionIntent $value): bool => true));
    }

    public function test_receipt_is_refused_once_the_approval_has_expired(): void
    {
        $run = $this->pausedRun();
        $service = $this->app->make(ActionApprovalService::class);
        $intent = $this->intent();
        $pending = $service->request($intent, $run->id);
        $receipt = $service->approve($pending->plainTextToken, $intent, static fn (ActionIntent $value): bool => true);
        $this->assertTrue($service->verifyForExecution($receipt, $intent, static fn (ActionIntent $value): bool => true));

        $this->travelTo($receipt->expiresAt->modify('+1 second'));

        $this->assertFalse($service->verifyForExecution($receipt, $intent, static fn (ActionIntent $value): bool => true));
    }

    public function test_acl_denial_at_approval_time_refuses_without_spending_the_token(): void
    {
        $run = $this->pausedRun();
        $service = $this->app->make(ActionApprovalService::class);
        $intent = $this->intent();
        $pending = $service->request($intent, $run->id);

        try {
            $service->approve($pending->plainTextToken, $intent, static fn (ActionIntent $value): bool => false);
            $this->fail('An approval that the current ACL denies must be refused.');
        } catch (ActionApprovalException) {
            $this->assertSame('pending', DB::table('flow_approvals')->where('step_name', ActionApprovalService::APPROVAL_STEP)->value('status'));
        }

        $receipt = $service->approve($pending->plainTextToken, $intent, static fn (ActionIntent $value): bool => true);
        $this->assertSame($pending->executionId, $receipt->executionId);
    }

    public function test_another_tenant_or_principal_cannot_redeem_the_approval(): void
    {
        $run = $this->pausedRun();
        $service = $this->app->make(ActionApprovalService::class);
        $intent = $this->intent();
        $pending = $service->request($intent, $run->id);

        foreach ([
            $this->intent(tenantId: 'other-tenant'),
            $this->intent(principalId: 'user-2'),
        ] as $foreign) {
            try {
                $service->approve($pending->plainTextToken, $foreign, static fn (ActionIntent $value): bool => true);
                $this->fail('An approval bound to another tenant or principal must not be redeemable.');
            } catch (ActionApprovalException) {
                $this->assertSame('pending', DB::table('flow_approvals')->where('step_name', ActionApprovalService::APPROVAL_STEP)->value('status'));
            }
        }

        $receipt = $service->approve($pending->plainTextToken, $intent, static fn (ActionIntent $value): bool => true);
        $this->assertFalse($service->verifyForExecution($receipt, $this->intent(tenantId: 'other-tenant'), static fn (ActionIntent $value): bool => true));
        $this->assertFalse($service->verifyForExecution($receipt, $this->intent(principalId: 'user-2'), static fn (ActionIntent $value): bool => true));
    }

    public function test_changed_arguments_with_the_original_digest_are_not_approved(): void
    {
        $run = $this->pausedRun();
        $service = $this->app->make(ActionApprovalService::class);
        $intent = $this->intent();
        $pending = $service->request($intent, $run->id);
        $receipt = $service->approve($pending->plainTextToken, $intent, static fn (ActionIntent $value): bool => true);

        $tampered = $this->withArgs($intent, ['query' => 'something the approver never saw']);
        $this->assertSame($intent->argsDigest, $tampered->argsDigest);

        $this->assertFalse($service->verifyForExecution($receipt, $tampered, static fn (ActionIntent $value): bool => true));

        $stillPending = $service->request($intent, $run->id);
        $this->denied(fn () => $service->approve($stillPending->plainTextToken, $tampered, static fn (ActionIntent $value): bool => true));
        $this->assertSame(1, DB::table('flow_approvals')->where('step_name', ActionApprovalService::APPROVAL_STEP)->where('status', 'pending')->count());
    }

    public function test_a_truthy_acl_result_that_is_not_true_is_not_an_allow(): void
    {
        $run = $this->pausedRun();
        $service = $this->app->make(ActionApprovalService::class);
        $intent = $this->intent();
        $pending = $service->request($intent, $run->id);

        $this->denied(fn () => $service->approve($pending->plainTextToken, $intent, static fn (ActionIntent $value): mixed => 'denied: not your project'));
        $receipt = $service->approve($pending->plainTextToken, $intent, static fn (ActionIntent $value): bool => true);

        $this->assertFalse($service->verifyForExecution($receipt, $intent, static fn (ActionIntent $value): mixed => 1));
    }

    public function test_verifying_from_another_ambient_tenant_is_refused_and_audited_on_the_owning_tenant(): void
    {
        $run = $this->pausedRun();
        $service = $this->app->make(ActionApprovalService::class);
        $intent = $this->intent();
        $pending = $service->request($intent, $run->id);
        $receipt = $service->approve($pending->plainTextToken, $intent, static fn (ActionIntent $value): bool => true);

        $this->app->make(TenantContext::class)->set('other-tenant');

        $this->assertFalse($service->verifyForExecution($receipt, $intent, static fn (ActionIntent $value): bool => true));
        $row = DB::table('flow_audit')->where('event', ActionApprovalService::DENIED_EVENT)->first();
        $this->assertNotNull($row);
        $this->assertSame('test-tenant', $row->tenant_id);
        $this->assertSame('binding_mismatch', json_decode((string) $row->payload, true)['reason']);
    }

    public function test_pending_forged_and_lapsed_approvals_are_each_refused_with_their_own_reason(): void
    {
        $run = $this->pausedRun();
        $service = $this->app->make(ActionApprovalService::class);
        $intent = $this->intent();
        $allow = static fn (ActionIntent $value): bool => true;

        $pending = $service->request($intent, $run->id);
        $notYetApproved = new ActionApprovalReceipt(
            approvalId: $pending->approvalId,
            executionId: $pending->executionId,
            tokenHash: ApprovalTokenManager::hashToken($pending->plainTextToken),
            actionDigest: $pending->actionDigest,
            targetDigest: $pending->targetDigest,
            expiresAt: $pending->expiresAt,
            approvedAt: $pending->expiresAt,
        );
        $this->assertFalse($service->verifyForExecution($notYetApproved, $intent, $allow));

        $approved = $service->request($intent, $run->id);
        $real = $service->approve($approved->plainTextToken, $intent, $allow);
        $forged = new ActionApprovalReceipt(
            approvalId: $real->approvalId,
            executionId: 'forged-execution-identity',
            tokenHash: $real->tokenHash,
            actionDigest: $real->actionDigest,
            targetDigest: $real->targetDigest,
            expiresAt: $real->expiresAt,
            approvedAt: $real->approvedAt,
        );
        $this->assertFalse($service->verifyForExecution($forged, $intent, $allow));

        $lapsing = $service->request($intent, $run->id);
        $this->travelTo($lapsing->expiresAt->modify('+1 second'));
        $this->denied(fn () => $service->approve($lapsing->plainTextToken, $intent, $allow));

        $reasons = DB::table('flow_audit')->where('event', ActionApprovalService::DENIED_EVENT)->orderBy('id')->get()
            ->map(fn (object $row): string => (string) json_decode((string) $row->payload, true)['reason'])->all();
        $this->assertSame(['not_approved', 'execution_mismatch', 'not_pending'], $reasons);
    }

    public function test_an_approval_cannot_be_issued_against_another_tenants_run(): void
    {
        $foreignRun = $this->pausedRunFor('other-tenant');
        $service = $this->app->make(ActionApprovalService::class);

        $this->expectException(ActionApprovalException::class);
        try {
            $service->request($this->intent(), $foreignRun->id);
        } finally {
            $this->assertSame(0, DB::table('flow_approvals')->where('step_name', ActionApprovalService::APPROVAL_STEP)->count());
        }
    }

    public function test_an_approval_cannot_be_issued_for_an_intent_outside_the_active_tenant(): void
    {
        $run = $this->pausedRun();
        $service = $this->app->make(ActionApprovalService::class);
        $this->app->make(TenantContext::class)->set('other-tenant');

        $this->expectException(ActionApprovalException::class);
        try {
            $service->request($this->intent(), $run->id);
        } finally {
            $this->assertSame(0, DB::table('flow_approvals')->where('step_name', ActionApprovalService::APPROVAL_STEP)->count());
        }
    }

    public function test_a_row_owned_by_another_tenant_is_not_redeemable_even_with_a_matching_payload(): void
    {
        $run = $this->pausedRun();
        $service = $this->app->make(ActionApprovalService::class);
        $intent = $this->intent();
        $pending = $service->request($intent, $run->id);
        DB::table('flow_approvals')->where('step_name', ActionApprovalService::APPROVAL_STEP)->update(['tenant_id' => 'other-tenant']);

        $this->expectException(ActionApprovalException::class);
        $service->approve($pending->plainTextToken, $intent, static fn (ActionIntent $value): bool => true);
    }

    public function test_every_attributable_denial_is_audited_without_arguments_or_tokens(): void
    {
        $run = $this->pausedRun();
        $service = $this->app->make(ActionApprovalService::class);
        $intent = $this->intent();
        $allow = static fn (ActionIntent $value): bool => true;
        $deny = static fn (ActionIntent $value): bool => false;

        $pending = $service->request($intent, $run->id);
        $this->denied(fn () => $service->approve($pending->plainTextToken, $intent, $deny));
        $this->denied(fn () => $service->approve($pending->plainTextToken, $this->intent(effect: 'write'), $allow));
        $receipt = $service->approve($pending->plainTextToken, $intent, $allow);
        $this->denied(fn () => $service->approve($pending->plainTextToken, $intent, $allow));
        $this->assertFalse($service->verifyForExecution($receipt, $intent, $deny));
        $this->assertFalse($service->verifyForExecution($receipt, $this->intent(effect: 'write'), $allow));
        $this->travelTo($receipt->expiresAt->modify('+1 second'));
        $this->assertFalse($service->verifyForExecution($receipt, $intent, $allow));

        $rows = DB::table('flow_audit')->where('event', ActionApprovalService::DENIED_EVENT)->orderBy('id')->get();
        $this->assertSame(
            ['acl_denied', 'binding_mismatch', 'replayed', 'acl_denied', 'binding_mismatch', 'expired'],
            $rows->map(fn (object $row): string => (string) json_decode((string) $row->payload, true)['reason'])->all(),
        );
        foreach ($rows as $row) {
            $this->assertSame($run->id, $row->run_id);
            $this->assertSame('test-tenant', $row->tenant_id);
            $payload = (string) $row->payload;
            $this->assertStringNotContainsString($pending->plainTextToken, $payload);
            $this->assertStringNotContainsString('retention policy', $payload);
            $this->assertSame($pending->executionId, json_decode($payload, true)['execution_id']);
            $this->assertSame('user-1', json_decode($payload, true)['principal_id']);
        }
    }

    public function test_an_unknown_token_is_refused_without_an_audit_row(): void
    {
        $run = $this->pausedRun();
        $service = $this->app->make(ActionApprovalService::class);
        $service->request($this->intent(), $run->id);

        $this->denied(fn () => $service->approve('not-a-real-token', $this->intent(), static fn (ActionIntent $value): bool => true));

        $this->assertSame(0, DB::table('flow_audit')->where('event', ActionApprovalService::DENIED_EVENT)->count());
    }

    public function test_an_acl_callback_that_throws_is_a_refusal_and_is_audited(): void
    {
        $run = $this->pausedRun();
        $service = $this->app->make(ActionApprovalService::class);
        $intent = $this->intent();
        $explode = static function (ActionIntent $value): bool {
            throw new \RuntimeException('policy backend unavailable');
        };
        $pending = $service->request($intent, $run->id);

        $this->denied(fn () => $service->approve($pending->plainTextToken, $intent, $explode));
        $this->assertSame('pending', DB::table('flow_approvals')->where('step_name', ActionApprovalService::APPROVAL_STEP)->value('status'));

        $receipt = $service->approve($pending->plainTextToken, $intent, static fn (ActionIntent $value): bool => true);
        $this->assertFalse($service->verifyForExecution($receipt, $intent, $explode));

        $reasons = DB::table('flow_audit')->where('event', ActionApprovalService::DENIED_EVENT)->orderBy('id')->get()
            ->map(fn (object $row): string => (string) json_decode((string) $row->payload, true)['reason'])->all();
        $this->assertSame(['acl_error', 'acl_error'], $reasons);
    }

    public function test_a_receipt_that_does_not_describe_its_own_approval_is_refused(): void
    {
        $run = $this->pausedRun();
        $service = $this->app->make(ActionApprovalService::class);
        $intent = $this->intent();
        $allow = static fn (ActionIntent $value): bool => true;
        $pending = $service->request($intent, $run->id);
        $real = $service->approve($pending->plainTextToken, $intent, $allow);
        $this->assertTrue($service->verifyForExecution($real, $intent, $allow));

        $wrongApproval = new ActionApprovalReceipt(
            approvalId: 'another-approval-id',
            executionId: $real->executionId,
            tokenHash: $real->tokenHash,
            actionDigest: $real->actionDigest,
            targetDigest: $real->targetDigest,
            expiresAt: $real->expiresAt,
            approvedAt: $real->approvedAt,
        );
        $wrongDigest = new ActionApprovalReceipt(
            approvalId: $real->approvalId,
            executionId: $real->executionId,
            tokenHash: $real->tokenHash,
            actionDigest: str_repeat('0', 64),
            targetDigest: $real->targetDigest,
            expiresAt: $real->expiresAt,
            approvedAt: $real->approvedAt,
        );

        $this->assertFalse($service->verifyForExecution($wrongApproval, $intent, $allow));
        $this->assertFalse($service->verifyForExecution($wrongDigest, $intent, $allow));
        $reasons = DB::table('flow_audit')->where('event', ActionApprovalService::DENIED_EVENT)->orderBy('id')->get()
            ->map(fn (object $row): string => (string) json_decode((string) $row->payload, true)['reason'])->all();
        $this->assertSame(['receipt_mismatch', 'receipt_mismatch'], $reasons);
    }

    /** @param callable(): mixed $attempt */
    private function denied(callable $attempt): void
    {
        try {
            $attempt();
        } catch (ActionApprovalException) {
            return;
        }
        $this->fail('The attempt must be refused with an ActionApprovalException.');
    }

    /** @param array<string, mixed> $args */
    private function withArgs(ActionIntent $intent, array $args): ActionIntent
    {
        return new ActionIntent(
            actionId: $intent->actionId,
            schemaVersion: $intent->schemaVersion,
            tenantId: $intent->tenantId,
            principalId: $intent->principalId,
            resource: $intent->resource,
            effect: $intent->effect,
            args: $args,
            target: $intent->target,
            grant: $intent->grant,
            assurance: $intent->assurance,
            evidenceRefs: $intent->evidenceRefs,
            reservationRef: $intent->reservationRef,
            argsDigest: $intent->argsDigest,
        );
    }

    private function pausedRunFor(string $tenantId): object
    {
        $context = $this->app->make(TenantContext::class);
        $previous = $context->current();
        $context->set($tenantId);
        try {
            return $this->pausedRun($tenantId);
        } finally {
            $context->set($previous);
        }
    }

    private function intent(
        string $resource = 'knowledge_document',
        string $effect = 'read',
        string $tenantId = 'test-tenant',
        string $principalId = 'user-1',
    ): ActionIntent {
        return ActionIntent::fromModelProposal(
            actionId: 'search_knowledge_base',
            tenantId: $tenantId,
            principalId: $principalId,
            proposal: [
                'resource' => $resource,
                'effect' => $effect,
                'target' => 'project/acme',
                'args' => ['query' => 'retention policy'],
            ],
            grant: 'kb.read',
            assurance: 'mfa',
        );
    }

    private function pausedRun(string $tenantId = 'test-tenant'): object
    {
        return Flow::execute(
            PromotionFlow::NAME,
            [
                'tenant_id' => $tenantId,
                'project_key' => 'acme',
                'markdown' => "---\nid: DEC-0001\nslug: approval-test\ntype: decision\nstatus: accepted\n---\n\n# Approval test\n\nBody.",
            ],
            FlowExecutionOptions::make(correlationId: $tenantId),
        );
    }
}
