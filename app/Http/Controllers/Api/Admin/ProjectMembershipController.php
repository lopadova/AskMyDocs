<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\MembershipStoreRequest;
use App\Http\Requests\Admin\MembershipUpdateRequest;
use App\Http\Resources\Admin\MembershipResource;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin project_memberships CRUD.
 *
 * `(user_id, project_key)` is uniq — re-POSTing the same pair is a no-op
 * that returns the existing row (upsert). Moving a user across projects
 * is a delete + create on the client.
 *
 * scope_allowlist shape is validated by MembershipStoreRequest /
 * MembershipUpdateRequest (see those files for the JSON schema).
 */
class ProjectMembershipController extends Controller
{
    public function index(User $user): AnonymousResourceCollection
    {
        $memberships = $user->projectMemberships()
            ->orderBy('project_key')
            ->paginate(100);

        return MembershipResource::collection($memberships);
    }

    public function store(MembershipStoreRequest $request, User $user): JsonResponse
    {
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
        $membership->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
