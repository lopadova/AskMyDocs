<?php

declare(strict_types=1);

namespace App\Actions;

use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Padosoft\LaravelFlow\ApprovalTokenManager;
use Padosoft\LaravelFlow\Models\FlowApprovalRecord;
use Padosoft\LaravelFlow\Models\FlowAuditRecord;
use Padosoft\LaravelFlow\Models\FlowRunRecord;
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

    public const DENIED_EVENT = 'action_approval.denied';

    private const AUDIT_FIELD_LENGTH = 128;

    private const ACL_ALLOW = 'allow';

    private const ACL_DENY = 'deny';

    private const ACL_ERROR = 'error';

    public function __construct(
        private readonly ApprovalTokenManager $tokens,
        private readonly TenantContext $tenant,
        private readonly ActionCanonicalizer $canonicalizer = new ActionCanonicalizer,
    ) {}

    public function request(ActionIntent $intent, string $runId): PendingActionApproval
    {
        // Flow stamps the approval row from the ambient tenant while the intent
        // carries its own; the two must agree and the run must belong to it, or
        // a row owned by one tenant could carry a payload bound to another.
        if ($this->tenant->current() !== $intent->tenantId || ! $this->runBelongsToTenant($runId, $intent->tenantId)) {
            throw ActionApprovalException::contextMismatch();
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
        #[\SensitiveParameter] string $plainTextToken,
        ActionIntent $intent,
        callable $currentAcl,
        array $actor = [],
    ): ActionApprovalReceipt {
        $pending = $this->tokens->pending($plainTextToken);
        if (! $pending instanceof FlowApprovalRecord) {
            // An unknown token has no run to attribute a denial to; a consumed
            // or lapsed one does.
            $this->auditUnusableToken($this->tokens->find($plainTextToken), $intent);
            throw ActionApprovalException::invalid();
        }

        if (! $this->matches($pending, $intent)) {
            $this->auditDenial('binding_mismatch', $pending, $intent);
            throw ActionApprovalException::invalid();
        }

        $decision = $this->aclDecision($currentAcl, $intent);
        if ($decision !== self::ACL_ALLOW) {
            $this->auditDenial($decision === self::ACL_ERROR ? 'acl_error' : 'acl_denied', $pending, $intent);
            throw ActionApprovalException::invalid();
        }

        $approved = $this->tokens->approve($plainTextToken, $actor, array_merge(
            (array) $pending->payload,
            ['action_digest' => $this->intentDigest($intent)],
        ));
        if (! $approved instanceof FlowApprovalRecord || $approved->status !== FlowApprovalRecord::STATUS_APPROVED) {
            // Lost the atomic consume to a concurrent approval of the same token.
            $this->auditDenial('replayed', $pending, $intent);
            throw ActionApprovalException::invalid();
        }

        return $this->receipt($approved);
    }

    /** @param callable(ActionIntent): bool $currentAcl */
    public function verifyForExecution(ActionApprovalReceipt $receipt, ActionIntent $intent, callable $currentAcl): bool
    {
        $record = $this->tokens->findByHash($receipt->tokenHash);
        if (! $record instanceof FlowApprovalRecord) {
            return false;
        }

        $reason = $this->executionDenial($record, $receipt, $intent, $currentAcl);
        if ($reason === null) {
            return true;
        }

        $this->auditDenial($reason, $record, $intent);

        return false;
    }

    /**
     * @param callable(ActionIntent): bool $currentAcl
     * @return string|null the denial reason, or null when the effect may run
     */
    private function executionDenial(
        FlowApprovalRecord $record,
        ActionApprovalReceipt $receipt,
        ActionIntent $intent,
        callable $currentAcl,
    ): ?string {
        if ($record->status !== FlowApprovalRecord::STATUS_APPROVED || $record->consumed_at === null || $record->decided_at === null) {
            return 'not_approved';
        }

        if ($this->expired($record)) {
            return 'expired';
        }

        if (! $this->matches($record, $intent)) {
            return 'binding_mismatch';
        }

        if (! hash_equals($receipt->executionId, (string) data_get($record->payload, 'execution_id'))) {
            return 'execution_mismatch';
        }

        // The receipt is a value object the caller holds; it must describe the
        // row its token hash resolves to, not merely carry a valid hash.
        if ((string) $record->id !== $receipt->approvalId
            || ! hash_equals($receipt->actionDigest, (string) data_get($record->payload, 'action_digest'))) {
            return 'receipt_mismatch';
        }

        return match ($this->aclDecision($currentAcl, $intent)) {
            self::ACL_ALLOW => null,
            self::ACL_ERROR => 'acl_error',
            default => 'acl_denied',
        };
    }

    /**
     * Only an exact `true` allows: a denied `Response` object or a message
     * string is truthy. A callback that throws is a refusal too, reported for
     * the operator and audited as its own reason rather than propagated as an
     * unaudited failure.
     *
     * @param callable(ActionIntent): bool $currentAcl
     */
    private function aclDecision(callable $currentAcl, ActionIntent $intent): string
    {
        try {
            return $currentAcl($intent) === true ? self::ACL_ALLOW : self::ACL_DENY;
        } catch (Throwable $exception) {
            report($exception);

            return self::ACL_ERROR;
        }
    }

    private function matches(FlowApprovalRecord $record, ActionIntent $intent): bool
    {
        return $record->step_name === self::APPROVAL_STEP
            && $this->tenant->current() === $intent->tenantId
            && hash_equals(
                (string) data_get($record->payload, 'action_digest'),
                $this->intentDigest($intent),
            ) && (string) data_get($record->payload, 'tenant_id') === $intent->tenantId
            && (string) $record->tenant_id === $intent->tenantId
            && (string) data_get($record->payload, 'principal_id') === $intent->principalId;
    }

    private function runBelongsToTenant(string $runId, string $tenantId): bool
    {
        return FlowRunRecord::on($this->flowConnection())
            ->whereKey($runId)
            ->where('tenant_id', $tenantId)
            ->exists();
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
     * Flow only expires rows that are still pending, so an approved row stays
     * readable forever; the expiry carried by the receipt must be enforced
     * here. A row with no expiry is refused rather than treated as unbounded.
     */
    private function expired(FlowApprovalRecord $record): bool
    {
        return $record->expires_at === null
            || $this->date($record->expires_at) <= CarbonImmutable::now();
    }

    /**
     * Structured, not delimiter-joined: fields such as resource/effect/target
     * come from the model proposal, and a joined string lets a `|` shift
     * between neighbouring fields and reproduce another intent's digest. The
     * arguments are digested from `args` themselves: `argsDigest` is a public
     * constructor field and is not trusted to describe them.
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

    private function auditUnusableToken(?FlowApprovalRecord $record, ActionIntent $intent): void
    {
        if (! $record instanceof FlowApprovalRecord) {
            return;
        }

        $this->auditDenial(
            $record->status === FlowApprovalRecord::STATUS_APPROVED ? 'replayed' : 'not_pending',
            $record,
            $intent,
        );
    }

    /**
     * Append-only, attributed to the approval row's own run and tenant. The
     * payload holds reason codes, identifiers and digests only: never the
     * token, the arguments or free text from the proposal. A failed write
     * propagates, so a denial that cannot be recorded is not silently dropped.
     */
    private function auditDenial(string $reason, FlowApprovalRecord $record, ActionIntent $intent): void
    {
        FlowAuditRecord::on($this->flowConnection())->create([
            'run_id' => (string) $record->run_id,
            'step_name' => self::APPROVAL_STEP,
            'event' => self::DENIED_EVENT,
            'tenant_id' => (string) $record->tenant_id,
            'payload' => [
                'reason' => $reason,
                'approval_id' => (string) $record->id,
                'execution_id' => $this->bounded((string) data_get($record->payload, 'execution_id')),
                'action_id' => $this->bounded($intent->actionId),
                'principal_id' => $this->bounded($intent->principalId),
                'attempt_tenant_id' => $this->bounded($intent->tenantId),
                'action_digest' => $this->intentDigest($intent),
            ],
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }

    private function flowConnection(): ?string
    {
        $connection = config('laravel-flow.default_storage');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    private function bounded(string $value): string
    {
        return mb_substr($value, 0, self::AUDIT_FIELD_LENGTH);
    }

    private function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        throw ActionApprovalException::invalid();
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
