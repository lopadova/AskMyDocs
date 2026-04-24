<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\RoleStoreRequest;
use App\Http\Requests\Admin\RoleUpdateRequest;
use App\Http\Resources\Admin\RoleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin roles CRUD (Spatie-backed).
 *
 * System roles (`super-admin`, `admin`) are PROTECTED — any destructive
 * touch (delete, rename) returns 409. This matches the UX expectation in
 * the React RolesView where those two rows render as read-only.
 */
class RoleController extends Controller
{
    private const PROTECTED_ROLES = ['super-admin', 'admin'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = max(1, min(200, (int) $request->query('per_page', 50)));

        $roles = Role::query()
            ->withCount('users')
            ->with('permissions:id,name')
            ->orderBy('name')
            ->paginate($perPage);

        return RoleResource::collection($roles);
    }

    public function show(Role $role): RoleResource
    {
        $role->loadCount('users');
        $role->loadMissing('permissions');

        return new RoleResource($role);
    }

    public function store(RoleStoreRequest $request): JsonResponse
    {
        $data = $request->validated();

        $role = DB::transaction(function () use ($data) {
            $role = Role::create([
                'name' => $data['name'],
                'guard_name' => 'web',
            ]);

            $role->syncPermissions($data['permissions'] ?? []);

            return $role;
        });

        $role->loadMissing('permissions');
        $role->loadCount('users');

        return (new RoleResource($role))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(RoleUpdateRequest $request, Role $role): JsonResponse
    {
        if (in_array($role->name, self::PROTECTED_ROLES, true) && $request->has('name') && $request->input('name') !== $role->name) {
            return response()->json(
                ['message' => sprintf('Cannot rename the system role "%s".', $role->name)],
                Response::HTTP_CONFLICT,
            );
        }

        $data = $request->validated();

        DB::transaction(function () use ($data, $role) {
            if (array_key_exists('name', $data)) {
                $role->name = $data['name'];
                $role->save();
            }

            if (array_key_exists('permissions', $data)) {
                $role->syncPermissions($data['permissions']);
            }
        });

        $role->refresh()->loadMissing('permissions');
        $role->loadCount('users');

        return (new RoleResource($role))->response();
    }

    public function destroy(Role $role): JsonResponse
    {
        if (in_array($role->name, self::PROTECTED_ROLES, true)) {
            return response()->json(
                ['message' => sprintf('Cannot delete the system role "%s".', $role->name)],
                Response::HTTP_CONFLICT,
            );
        }

        $role->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
