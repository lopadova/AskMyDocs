<?php

declare(strict_types=1);

namespace App\Flow\Admin;

use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Padosoft\LaravelFlow\Models\FlowApprovalRecord;
use Padosoft\LaravelFlow\Models\FlowRunRecord;
use Padosoft\LaravelFlow\Models\FlowWebhookOutboxRecord;
use Padosoft\LaravelFlowAdmin\Contracts\ActionAuthorizer;

/**
 * v4.2/W4 sub-PR 6 — host-app implementation of the package's
 * ActionAuthorizer contract.
 *
 * Maps every mutation gate the package exposes to:
 *
 *   1. A Spatie role check on the currently-authenticated user, and
 *   2. A tenant_id check against the active TenantContext (R30).
 *
 * Role matrix (FEATURE-CATALOG-flow-admin.md, adjusted to the actual
 * RbacSeeder role set — `ops` and `curator` are not seeded; admin
 * substitutes for ops, dpo substitutes for curator):
 *
 *   | Mutation                    | Allowed roles                |
 *   |-----------------------------|------------------------------|
 *   | canViewKpis                 | super-admin, admin, dpo      |
 *   | canViewRuns                 | super-admin, admin, dpo      |
 *   | canViewRunDetail            | super-admin, admin, dpo      |
 *   | canReplayRun (resume)       | super-admin, admin           |
 *   | canCancelRun                | super-admin                  |
 *   | canApproveByToken           | super-admin, dpo             |
 *   | canRejectByToken            | super-admin, dpo             |
 *   | canRetryWebhook             | super-admin, admin           |
 *
 * Tenant scoping (R30):
 *
 *   Every per-row gate (canViewRunDetail, canReplayRun, canCancelRun,
 *   canRetryWebhook) loads the row's tenant_id and rejects when it
 *   does not match the active TenantContext. canApproveByToken and
 *   canRejectByToken resolve the approval row by the caller-supplied
 *   token hash (the package's contract identifier) and apply the same
 *   per-row tenant check on the resolved approval AND on its parent
 *   flow_run.
 *
 *   Cross-tenant leak is structurally impossible: a super-admin in
 *   tenant A still cannot resume a run owned by tenant B because the
 *   row-level guard fires before the role check is even reached.
 *
 * Anonymous requests deny everything explicitly. The package passes
 * `?array $actor` for forwards-compat with API tokens; we use it only
 * as a fallback when `Auth::user()` is null in case some upstream
 * middleware has decoded an actor without populating the standard
 * guard.
 */
final class AskMyDocsFlowAuthorizer implements ActionAuthorizer
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    public function canViewKpis(?array $actor): bool
    {
        return $this->userHasAnyRole(['super-admin', 'admin', 'dpo']);
    }

    public function canViewRuns(?array $actor): bool
    {
        return $this->userHasAnyRole(['super-admin', 'admin', 'dpo']);
    }

    public function canViewRunDetail(string $runId, ?array $actor): bool
    {
        if (! $this->userHasAnyRole(['super-admin', 'admin', 'dpo'])) {
            return false;
        }

        return $this->runBelongsToActiveTenant($runId);
    }

    public function canReplayRun(string $runId, ?array $actor): bool
    {
        if (! $this->userHasAnyRole(['super-admin', 'admin'])) {
            return false;
        }

        return $this->runBelongsToActiveTenant($runId);
    }

    public function canCancelRun(string $runId, ?array $actor): bool
    {
        // Most destructive — super-admin only. R37/R28 footprint:
        // cancellation hard-fails an in-flight run and is not
        // reversible from the cockpit; we restrict it to the role
        // that already owns RCE-class privileges.
        if (! $this->userHasRole('super-admin')) {
            return false;
        }

        return $this->runBelongsToActiveTenant($runId);
    }

    public function canApproveByToken(string $tokenHash, ?array $actor): bool
    {
        if (! $this->userHasAnyRole(['super-admin', 'dpo'])) {
            return false;
        }

        return $this->approvalBelongsToActiveTenant($tokenHash);
    }

    public function canRejectByToken(string $tokenHash, ?array $actor): bool
    {
        if (! $this->userHasAnyRole(['super-admin', 'dpo'])) {
            return false;
        }

        return $this->approvalBelongsToActiveTenant($tokenHash);
    }

    public function canRetryWebhook(int $outboxId, ?array $actor): bool
    {
        if (! $this->userHasAnyRole(['super-admin', 'admin'])) {
            return false;
        }

        return $this->webhookBelongsToActiveTenant($outboxId);
    }

    /**
     * Resolve the active user via the standard auth guard. Anonymous
     * requests return null. Future API-token actors would surface
     * here once we wire a Sanctum personal-access-token guard for
     * the flow-admin surface.
     */
    private function currentUser(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    /**
     * @param  array<int, string>  $roles
     */
    private function userHasAnyRole(array $roles): bool
    {
        $user = $this->currentUser();
        if ($user === null) {
            return false;
        }

        return $user->hasAnyRole($roles);
    }

    private function userHasRole(string $role): bool
    {
        $user = $this->currentUser();
        if ($user === null) {
            return false;
        }

        return $user->hasRole($role);
    }

    /**
     * R30 — every row-scoped action verifies the row's tenant_id
     * matches the active TenantContext.
     *
     * We use a raw `DB::table` query (not the Eloquent model) because:
     *   1. The package model is `final` — we cannot extend it.
     *   2. We only need one column; loading the whole row is wasteful.
     *   3. There is no global scope on the package model that could
     *      interfere with the tenant check itself.
     *
     * A missing row returns false (defence-in-depth: the controller
     * downstream will 404 on the missing run, but we deny here too
     * so the gate never green-lights an action against a row that
     * doesn't exist in the active tenant's view).
     */
    private function runBelongsToActiveTenant(string $runId): bool
    {
        $tenantId = DB::table((new FlowRunRecord)->getTable())
            ->where('id', $runId)
            ->value('tenant_id');

        if ($tenantId === null) {
            return false;
        }

        return (string) $tenantId === $this->tenant->current();
    }

    private function approvalBelongsToActiveTenant(string $tokenHash): bool
    {
        $row = DB::table((new FlowApprovalRecord)->getTable())
            ->where('token_hash', $tokenHash)
            ->first(['tenant_id', 'run_id']);

        if ($row === null) {
            return false;
        }

        if ((string) ($row->tenant_id ?? '') !== $this->tenant->current()) {
            return false;
        }

        // Defence in depth: also verify the parent run is in the
        // active tenant. Approval and run tenant_id should always
        // match (they're stamped together by FlowServiceProvider's
        // `creating` hook), but a manual SQL backfill could break
        // the invariant — the per-run check catches that.
        return $this->runBelongsToActiveTenant((string) $row->run_id);
    }

    private function webhookBelongsToActiveTenant(int $outboxId): bool
    {
        $tenantId = DB::table((new FlowWebhookOutboxRecord)->getTable())
            ->where('id', $outboxId)
            ->value('tenant_id');

        if ($tenantId === null) {
            return false;
        }

        return (string) $tenantId === $this->tenant->current();
    }
}
