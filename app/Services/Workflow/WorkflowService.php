<?php

declare(strict_types=1);

namespace App\Services\Workflow;

use App\Models\HiddenWorkflow;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowShare;
use App\Support\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * v4.7/W2 — Workflow CRUD + sharing + per-user hide service.
 *
 * Centralises the access control rules the controller would otherwise
 * scatter into multiple actions:
 *   - list:       own + shared-with-me + system − hidden
 *   - update:     owner OR shared-with-allow_edit OR super-admin
 *   - destroy:    owner OR super-admin; system rows never deletable
 *   - share:      owner only (or super-admin)
 *   - hide:       any user, scoped to themselves
 *
 * Every method is tenant-scoped via {@see TenantContext} (R30).
 */
final class WorkflowService
{
    public function __construct(
        private readonly TenantContext $ctx,
    ) {}

    /**
     * Return the workflows the user can SEE.
     *
     * Set composition:
     *   - workflows the user owns within the active tenant
     *   - + (when $includeShared) workflows shared with the user's email
     *     within the active tenant
     *   - + (when $includeShared) system workflows (is_system=true) within
     *     the active tenant — always visible to every user
     *   - − (unless $includeHidden) workflows the user has hidden
     */
    public function list(
        User $user,
        ?string $type = null,
        bool $includeShared = true,
        bool $includeHidden = false,
    ): Collection {
        $tenant = $this->ctx->current();

        $query = Workflow::query()->forTenant($tenant);
        if ($type !== null && $type !== '') {
            $query->where('type', $type);
        }

        $query->where(function ($q) use ($user, $includeShared): void {
            $q->where('user_id', $user->id);
            if ($includeShared) {
                $q->orWhere('is_system', true);
                $email = (string) $user->getAttribute('email');
                if ($email !== '') {
                    $q->orWhereIn('id', function ($sub) use ($email): void {
                        $sub->select('workflow_id')
                            ->from('workflow_shares')
                            ->where('shared_with_email', $email);
                    });
                }
            }
        });

        if (! $includeHidden) {
            $query->whereNotIn('id', function ($sub) use ($user, $tenant): void {
                $sub->select('workflow_id')
                    ->from('hidden_workflows')
                    ->where('tenant_id', $tenant)
                    ->where('user_id', $user->id);
            });
        }

        return $query->orderByDesc('id')->get();
    }

    /**
     * Create a workflow owned by $owner. Always scopes to the active
     * tenant.
     *
     * @param array<string, mixed> $attributes
     */
    public function create(User $owner, array $attributes): Workflow
    {
        $attributes['tenant_id'] = $this->ctx->current();
        $attributes['user_id'] = $owner->id;
        // is_system is reserved for the seeder; user-driven creates can
        // never mint a system row, regardless of FormRequest validation.
        $attributes['is_system'] = false;

        return Workflow::create($attributes);
    }

    /**
     * Update an existing workflow.
     *
     * Access policy:
     *   - owner can update everything except `is_system`
     *   - a recipient with `allow_edit=true` can update everything except
     *     `is_system` and ownership transfer (`user_id`)
     *   - super-admin can update everything (including system rows)
     *
     * @param array<string, mixed> $attributes
     */
    public function update(Workflow $workflow, User $user, array $attributes): Workflow
    {
        $this->assertSameTenant($workflow);
        $isSuperAdmin = method_exists($user, 'hasRole') && $user->hasRole('super-admin');

        if (! $isSuperAdmin && ! $this->userCanEdit($workflow, $user)) {
            throw new AccessDeniedHttpException('You cannot edit this workflow.');
        }

        unset($attributes['is_system'], $attributes['tenant_id']);
        if (! $isSuperAdmin) {
            unset($attributes['user_id']);
        }

        $workflow->fill($attributes);
        $workflow->save();

        return $workflow;
    }

    /**
     * Delete a workflow. Only the owner can delete; system rows are
     * undeletable from the API regardless of role.
     */
    public function delete(Workflow $workflow, User $user): bool
    {
        $this->assertSameTenant($workflow);

        if ($workflow->is_system) {
            throw new AccessDeniedHttpException('System workflows cannot be deleted.');
        }

        $isSuperAdmin = method_exists($user, 'hasRole') && $user->hasRole('super-admin');
        if (! $isSuperAdmin && (int) $workflow->user_id !== (int) $user->id) {
            throw new AccessDeniedHttpException('Only the workflow owner can delete it.');
        }

        return (bool) $workflow->delete();
    }

    /**
     * Idempotently share a workflow with an email.
     *
     * Wrapped in a transaction so two concurrent shares of the same
     * (workflow_id, email) pair settle on a single row rather than
     * tripping the composite unique.
     */
    public function share(
        Workflow $workflow,
        User $owner,
        string $email,
        bool $allowEdit,
    ): WorkflowShare {
        $this->assertSameTenant($workflow);
        $this->assertOwnerOrSuperAdmin($workflow, $owner);

        $email = mb_strtolower(trim($email));
        if ($email === '') {
            throw new \InvalidArgumentException('shared_with_email is required.');
        }

        return DB::transaction(function () use ($workflow, $owner, $email, $allowEdit): WorkflowShare {
            $existing = WorkflowShare::query()
                ->where('workflow_id', $workflow->id)
                ->where('shared_with_email', $email)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $existing->update([
                    'allow_edit' => $allowEdit,
                    'shared_by_user_id' => $owner->id,
                ]);

                return $existing;
            }

            return WorkflowShare::create([
                'workflow_id' => $workflow->id,
                'shared_by_user_id' => $owner->id,
                'shared_with_email' => $email,
                'allow_edit' => $allowEdit,
            ]);
        });
    }

    public function unshare(Workflow $workflow, User $owner, string $email): bool
    {
        $this->assertSameTenant($workflow);
        $this->assertOwnerOrSuperAdmin($workflow, $owner);

        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return false;
        }

        $deleted = WorkflowShare::query()
            ->where('workflow_id', $workflow->id)
            ->where('shared_with_email', $email)
            ->delete();

        return $deleted > 0;
    }

    public function hide(Workflow $workflow, User $user): HiddenWorkflow
    {
        $this->assertSameTenant($workflow);

        $tenant = $this->ctx->current();

        $row = HiddenWorkflow::query()
            ->where('tenant_id', $tenant)
            ->where('user_id', $user->id)
            ->where('workflow_id', $workflow->id)
            ->first();

        if ($row !== null) {
            return $row;
        }

        return HiddenWorkflow::create([
            'tenant_id' => $tenant,
            'user_id' => $user->id,
            'workflow_id' => $workflow->id,
            'hidden_at' => now(),
        ]);
    }

    public function unhide(Workflow $workflow, User $user): bool
    {
        $this->assertSameTenant($workflow);

        $deleted = HiddenWorkflow::query()
            ->where('tenant_id', $this->ctx->current())
            ->where('user_id', $user->id)
            ->where('workflow_id', $workflow->id)
            ->delete();

        return $deleted > 0;
    }

    private function userCanEdit(Workflow $workflow, User $user): bool
    {
        if ((int) $workflow->user_id === (int) $user->id) {
            return true;
        }

        $email = mb_strtolower((string) $user->getAttribute('email'));
        if ($email === '') {
            return false;
        }

        return WorkflowShare::query()
            ->where('workflow_id', $workflow->id)
            ->where('shared_with_email', $email)
            ->where('allow_edit', true)
            ->exists();
    }

    private function assertSameTenant(Workflow $workflow): void
    {
        if ($workflow->tenant_id !== $this->ctx->current()) {
            throw new NotFoundHttpException('Workflow not found.');
        }
    }

    private function assertOwnerOrSuperAdmin(Workflow $workflow, User $user): void
    {
        $isSuperAdmin = method_exists($user, 'hasRole') && $user->hasRole('super-admin');
        if (! $isSuperAdmin && (int) $workflow->user_id !== (int) $user->id) {
            throw new AccessDeniedHttpException('Only the workflow owner can manage shares.');
        }
    }
}
