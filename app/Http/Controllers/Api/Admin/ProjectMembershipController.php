<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\MembershipStoreRequest;
use App\Http\Requests\Admin\MembershipUpdateRequest;
use App\Http\Resources\Admin\MembershipResource;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\PlatformAccess;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin project_memberships CRUD.
 *
 * `(tenant_id, user_id, project_key)` is unique — re-POSTing the same tuple is
 * a no-op that returns the existing row (upsert). Moving a user across
 * projects is a delete + create on the client. Deletion refuses to remove the
 * final membership of the tenant's final Super Admin.
 *
 * scope_allowlist shape is validated by MembershipStoreRequest /
 * MembershipUpdateRequest (see those files for the JSON schema).
 */
class ProjectMembershipController extends Controller
{
    public function index(User $user): AnonymousResourceCollection
    {
        $this->assertUserInActiveTenant($user);

        // R30 — list ONLY the active team's memberships. Without the scope
        // the Users screen showed (and let admins edit) rows belonging to
        // every tenant the target user is a member of.
        $memberships = $user->projectMemberships()
            ->forTenant(app(TenantContext::class)->current())
            ->orderBy('project_key')
            ->paginate(100);

        return MembershipResource::collection($memberships);
    }

    public function store(MembershipStoreRequest $request, User $user): JsonResponse
    {
        $this->assertUserInActiveTenant($user);

        $data = $request->validated();

        // R30/R31 — the upsert match keys MUST include tenant_id; otherwise
        // an upsert in tenant A for (user_id, project_key) overwrites the
        // tenant-B row that shares the same pair. BelongsToTenant auto-fills
        // tenant_id only on insert, so it cannot rescue the match clause.
        $membership = ProjectMembership::updateOrCreate(
            [
                'tenant_id' => app(TenantContext::class)->current(),
                'user_id' => $user->id,
                'project_key' => $data['project_key'],
            ],
            [
                'role' => $data['role'] ?? 'member',
                'scope_allowlist' => $data['scope_allowlist'] ?? null,
            ],
        );

        $status = $membership->wasRecentlyCreated
            ? Response::HTTP_CREATED
            : Response::HTTP_OK;

        return (new MembershipResource($membership))->response()->setStatusCode($status);
    }

    public function update(MembershipUpdateRequest $request, ProjectMembership $membership): MembershipResource
    {
        $this->assertActiveTenant($membership);

        $data = $request->validated();

        if (array_key_exists('role', $data)) {
            $membership->role = $data['role'];
        }

        if (array_key_exists('scope_allowlist', $data)) {
            $membership->scope_allowlist = $data['scope_allowlist'];
        }

        $membership->save();

        return new MembershipResource($membership->fresh());
    }

    public function destroy(ProjectMembership $membership): JsonResponse
    {
        $this->assertActiveTenant($membership);

        DB::transaction(function () use ($membership): void {
            $target = ProjectMembership::query()
                ->whereKey($membership->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertActiveTenant($target);

            if ($this->wouldRemoveLastTenantSuperAdmin($target)) {
                abort(
                    Response::HTTP_CONFLICT,
                    'Cannot remove the last super-admin membership for this tenant.',
                );
            }

            $target->delete();
        });

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * R30 — the implicit binding resolves by id with no tenant scope, so an
     * admin operating in tenant A could mutate tenant B's membership rows
     * by guessing ids. 404 (not 403) hides the row's existence, matching
     * the v8.9.0 cross-tenant-membership posture.
     */
    private function assertActiveTenant(ProjectMembership $membership): void
    {
        abort_unless(
            $membership->tenant_id === app(TenantContext::class)->current(),
            Response::HTTP_NOT_FOUND,
        );
    }

    /**
     * Nested membership routes receive a globally bound User model. Require an
     * existing membership in the active tenant before exposing or mutating the
     * target, matching UserController's IDOR-safe identity boundary.
     */
    private function assertUserInActiveTenant(User $user): void
    {
        $tenantId = app(TenantContext::class)->current();

        abort_unless(
            ProjectMembership::query()
                ->forTenant($tenantId)
                ->where('user_id', $user->id)
                ->exists(),
            Response::HTTP_NOT_FOUND,
        );
    }

    /**
     * True when deleting this row would leave the active tenant without any
     * user who both has the global companion role and a real membership.
     *
     * The shared role-row lock serializes deletion of different memberships
     * (including two project rows owned by the same user), so concurrent
     * requests cannot both observe a safe pre-delete state and remove the last
     * tenant Super Admin.
     */
    private function wouldRemoveLastTenantSuperAdmin(ProjectMembership $membership): bool
    {
        $user = User::query()->find($membership->user_id);
        if ($user === null || ! $user->hasRole(PlatformAccess::TENANT_SUPER_ADMIN_ROLE, 'web')) {
            return false;
        }

        $role = Role::query()
            ->where('name', PlatformAccess::TENANT_SUPER_ADMIN_ROLE)
            ->where('guard_name', 'web')
            ->lockForUpdate()
            ->first();
        if ($role === null) {
            return false;
        }

        $hasAnotherMembership = ProjectMembership::query()
            ->forTenant((string) $membership->tenant_id)
            ->where('user_id', $membership->user_id)
            ->whereKeyNot($membership->getKey())
            ->exists();
        if ($hasAnotherMembership) {
            return false;
        }

        return $role->users()
            ->where('users.id', '!=', $membership->user_id)
            ->whereHas(
                'projectMemberships',
                fn ($memberships) => $memberships->where('tenant_id', $membership->tenant_id),
            )
            ->count() === 0;
    }
}
