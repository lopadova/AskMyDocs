<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\MembershipStoreRequest;
use App\Http\Requests\Admin\MembershipUpdateRequest;
use App\Http\Resources\Admin\MembershipResource;
use App\Models\ProjectMembership;
use App\Models\User;
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

        $membership = ProjectMembership::updateOrCreate(
            [
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
