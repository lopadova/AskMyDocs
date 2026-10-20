<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\ActionApprovalException;
use App\Actions\ActionApprovalService;
use App\Actions\ActionIntent;
use App\Flow\Definitions\PromotionFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

    private function pausedRun(): object
    {
        return Flow::execute(
            PromotionFlow::NAME,
            [
                'tenant_id' => 'test-tenant',
                'project_key' => 'acme',
                'markdown' => "---\nid: DEC-0001\nslug: approval-test\ntype: decision\nstatus: accepted\n---\n\n# Approval test\n\nBody.",
            ],
            FlowExecutionOptions::make(correlationId: 'test-tenant'),
        );
    }
}
