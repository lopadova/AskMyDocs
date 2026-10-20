<?php

declare(strict_types=1);

namespace App\Actions;

use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Padosoft\LaravelFlow\ApprovalTokenManager;
use Padosoft\LaravelFlow\Models\FlowApprovalRecord;
use Throwable;

/**
 * The common HITL/Flow boundary for executable ActionIntent values.
 *
 * The approval row contains digests and routing metadata only; the proposed
 * arguments themselves never enter the receipt or approval audit payload.
 */
final class ActionApprovalService
{
    public const APPROVAL_STEP = 'action-intent-approval';

    public function __construct(
        private readonly ApprovalTokenManager $tokens,
        private readonly TenantContext $tenant,
        private readonly ActionCanonicalizer $canonicalizer = new ActionCanonicalizer,
    ) {}

    public function request(ActionIntent $intent, string $runId): PendingActionApproval
    {
        if ($intent->tenantId !== $this->tenant->current() || ! $this->runBelongsToActiveTenant($runId)) {
            $this->auditDenial($intent);
            throw ActionApprovalException::invalid();
        }

        $executionId = $this->uuid();
        $issued = $this->tokens->issue($runId, self::APPROVAL_STEP, [
            'execution_id' => $executionId,
            'action_id' => $intent->actionId,
            'schema_version' => $intent->schemaVersion,
            'tenant_id' => $intent->tenantId,
            'principal_id' => $intent->principalId,
            'action_digest' => $this->intentDigest($intent),
            'target_digest' => $intent->target === null ? null : hash('sha256', $intent->target),
        ]);

        return new PendingActionApproval(
            approvalId: $issued->approvalId,
            executionId: $executionId,
            plainTextToken: $issued->plainTextToken,
            actionDigest: $this->intentDigest($intent),
            targetDigest: $intent->target === null ? null : hash('sha256', $intent->target),
            expiresAt: $this->date($issued->expiresAt),
        );
    }

    /** @param callable(ActionIntent): bool $currentAcl */
    public function approve(
        string $plainTextToken,
        ActionIntent $intent,
        callable $currentAcl,
        array $actor = [],
    ): ActionApprovalReceipt {
        $pending = $this->tokens->pending($plainTextToken);
        if (! $pending instanceof FlowApprovalRecord || ! $this->matches($pending, $intent)) {
            $this->auditDenial($intent, $pending instanceof FlowApprovalRecord ? $pending : null);
            throw ActionApprovalException::invalid();
        }

        try {
            $allowed = $currentAcl($intent);
        } catch (Throwable) {
            $this->auditDenial($intent, $pending, 'failed');
            throw ActionApprovalException::invalid();
        }
        if (! $allowed) {
            $this->auditDenial($intent, $pending);
            throw ActionApprovalException::invalid();
        }

        try {
            $approved = $this->tokens->approve($plainTextToken, $actor, array_merge(
                (array) $pending->payload,
                ['action_digest' => $this->intentDigest($intent)],
            ));
        } catch (Throwable) {
            $this->auditDenial($intent, $pending, 'failed');
            throw ActionApprovalException::invalid();
        }

        if (! $approved instanceof FlowApprovalRecord || $approved->status !== FlowApprovalRecord::STATUS_APPROVED) {
            $this->auditDenial($intent, $pending);
            throw ActionApprovalException::invalid();
        }

        return $this->receipt($approved);
    }

    /** @param callable(ActionIntent): bool $currentAcl */
    public function verifyForExecution(ActionApprovalReceipt $receipt, ActionIntent $intent, callable $currentAcl): bool
    {
        $record = $this->tokens->findByHash($receipt->tokenHash);
        $validReceipt = $record instanceof FlowApprovalRecord
            && $record->status === FlowApprovalRecord::STATUS_APPROVED
            && $record->consumed_at !== null
            && $record->decided_at !== null
            && ! $this->expired($record)
            && $this->matches($record, $intent)
            && (string) $record->id === $receipt->approvalId
            && hash_equals($receipt->executionId, (string) data_get($record->payload, 'execution_id'))
            && hash_equals($receipt->actionDigest, (string) data_get($record->payload, 'action_digest'));

        if (! $validReceipt) {
            $this->auditDenial($intent, $record instanceof FlowApprovalRecord ? $record : null);
            return false;
        }

        try {
            if (! $currentAcl($intent)) {
                $this->auditDenial($intent, $record);
                return false;
            }
        } catch (Throwable) {
            $this->auditDenial($intent, $record, 'failed');
            return false;
        }

        return true;
    }

    private function matches(FlowApprovalRecord $record, ActionIntent $intent): bool
    {
        $tenantId = $this->tenant->current();

        return $intent->tenantId === $tenantId
            && (string) $record->tenant_id === $tenantId
            && $record->step_name === self::APPROVAL_STEP
            && $this->runBelongsToActiveTenant((string) $record->run_id)
            && (string) data_get($record->payload, 'tenant_id') === $tenantId
            && hash_equals(
                (string) data_get($record->payload, 'action_digest'),
                $this->intentDigest($intent),
            )
            && (string) data_get($record->payload, 'principal_id') === $intent->principalId;
    }

    private function runBelongsToActiveTenant(string $runId): bool
    {
        return DB::table('flow_runs')
            ->where('id', $runId)
            ->where('tenant_id', $this->tenant->current())
            ->exists();
    }

    private function auditDenial(ActionIntent $intent, ?FlowApprovalRecord $record = null, string $outcome = 'denied'): void
    {
        $payload = (array) ($record?->payload ?? []);
        $actorId = Auth::id();
        $principalId = $intent->principalId;
        $actorId = is_scalar($actorId) ? substr((string) $actorId, 0, 128) : null;
        $executionId = $payload['execution_id'] ?? null;
        $correlationId = is_string($executionId) && preg_match('/^[a-f0-9-]{36}$/i', $executionId)
            ? $executionId
            : (is_string($record?->id) ? $record->id : $this->uuid());

        Log::warning('Action approval ' . $outcome, [
            'event' => 'action_approval_' . $outcome,
            'correlation_id' => $correlationId,
            'approval_id' => is_string($record?->id) ? $record->id : null,
            'tenant_id' => $this->tenant->current(),
            'principal_id' => substr($principalId, 0, 128),
            'actor_id' => $actorId,
        ]);

        $tenantId = $this->tenant->current();
        $runId = is_string($record?->run_id) ? $record->run_id : null;
        if (
            $runId !== null
            && (string) $record->tenant_id === $tenantId
            && $this->runBelongsToActiveTenant($runId)
        ) {
            $occurredAt = CarbonImmutable::now();
            DB::table('flow_audit')->insert([
                'tenant_id' => $tenantId,
                'run_id' => $runId,
                'step_name' => self::APPROVAL_STEP,
                'event' => 'action_approval_' . $outcome,
                'payload' => json_encode([
                    'correlation_id' => $correlationId,
                    'approval_id' => (string) $record->id,
                    'tenant_id' => $tenantId,
                    'principal_id' => substr($principalId, 0, 128),
                    'actor_id' => $actorId,
                    'outcome' => $outcome,
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => $occurredAt,
                'created_at' => $occurredAt,
            ]);
        }
    }

    private function receipt(FlowApprovalRecord $record): ActionApprovalReceipt
    {
        $payload = (array) $record->payload;
        return new ActionApprovalReceipt(
            approvalId: (string) $record->id,
            executionId: (string) ($payload['execution_id'] ?? ''),
            tokenHash: (string) $record->token_hash,
            actionDigest: (string) ($payload['action_digest'] ?? ''),
            targetDigest: isset($payload['target_digest']) ? (string) $payload['target_digest'] : null,
            expiresAt: $this->date($record->expires_at),
            approvedAt: $this->date($record->decided_at),
        );
    }

    /**
     * Flow only expires rows still pending, so an approved row stays readable
     * forever; the expiry carried by the receipt must be enforced here. A row
     * with no expiry is refused rather than treated as unbounded.
     */
    private function expired(FlowApprovalRecord $record): bool
    {
        return $record->expires_at === null
            || $this->date($record->expires_at) <= CarbonImmutable::now();
    }

    /**
     * Structured, not delimiter-joined: fields such as resource/effect/target
     * come from the model proposal, and a joined string lets a `|` shift
     * between neighbouring fields and reproduce another intent's digest.
     */
    private function intentDigest(ActionIntent $intent): string
    {
        return $this->canonicalizer->digest([
            'action_id' => $intent->actionId,
            'schema_version' => $intent->schemaVersion,
            'tenant_id' => $intent->tenantId,
            'principal_id' => $intent->principalId,
            'resource' => $intent->resource,
            'effect' => $intent->effect,
            'args_digest' => $this->canonicalizer->digest($intent->args),
            'target' => $intent->target,
            'grant' => $intent->grant,
            'assurance' => $intent->assurance,
        ]);
    }

    private function date(mixed $value): DateTimeImmutable
    {
        return $value instanceof DateTimeImmutable
            ? $value
            : DateTimeImmutable::createFromInterface($value);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
