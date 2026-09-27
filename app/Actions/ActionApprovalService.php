<?php

declare(strict_types=1);

namespace App\Actions;

use DateTimeImmutable;
use Padosoft\LaravelFlow\ApprovalTokenManager;
use Padosoft\LaravelFlow\IssuedApprovalToken;
use Padosoft\LaravelFlow\Models\FlowApprovalRecord;

/**
 * The common HITL/Flow boundary for executable ActionIntent values.
 *
 * The approval row contains digests and routing metadata only; the proposed
 * arguments themselves never enter the receipt or approval audit payload.
 */
final class ActionApprovalService
{
    public const APPROVAL_STEP = 'action-intent-approval';

    public function __construct(private readonly ApprovalTokenManager $tokens) {}

    public function request(ActionIntent $intent, string $runId): PendingActionApproval
    {
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
        if (! $pending instanceof FlowApprovalRecord || ! $this->matches($pending, $intent) || ! $currentAcl($intent)) {
            throw ActionApprovalException::invalid();
        }

        $approved = $this->tokens->approve($plainTextToken, $actor, array_merge(
            (array) $pending->payload,
            ['action_digest' => $this->intentDigest($intent)],
        ));
        if (! $approved instanceof FlowApprovalRecord || $approved->status !== FlowApprovalRecord::STATUS_APPROVED) {
            throw ActionApprovalException::invalid();
        }

        return $this->receipt($approved);
    }

    /** @param callable(ActionIntent): bool $currentAcl */
    public function verifyForExecution(ActionApprovalReceipt $receipt, ActionIntent $intent, callable $currentAcl): bool
    {
        $record = $this->tokens->findByHash($receipt->tokenHash);
        return $record instanceof FlowApprovalRecord
            && $record->status === FlowApprovalRecord::STATUS_APPROVED
            && $record->consumed_at !== null
            && $record->decided_at !== null
            && $this->matches($record, $intent)
            && hash_equals($receipt->executionId, (string) data_get($record->payload, 'execution_id'))
            && $currentAcl($intent);
    }

    private function matches(FlowApprovalRecord $record, ActionIntent $intent): bool
    {
        return hash_equals(
            (string) data_get($record->payload, 'action_digest'),
            $this->intentDigest($intent),
        ) && (string) data_get($record->payload, 'tenant_id') === $intent->tenantId
            && (string) data_get($record->payload, 'principal_id') === $intent->principalId;
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

    private function intentDigest(ActionIntent $intent): string
    {
        return hash('sha256', implode('|', [
            $intent->actionId,
            $intent->schemaVersion,
            $intent->tenantId,
            $intent->principalId,
            $intent->resource,
            $intent->effect,
            $intent->argsDigest,
            $intent->target ?? '',
            $intent->grant,
            $intent->assurance,
        ]));
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
