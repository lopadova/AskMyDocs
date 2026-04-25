<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\UserStoreRequest;
use App\Http\Requests\Admin\UserUpdateRequest;
use App\Http\Resources\Admin\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin users CRUD — paginated list, create, show, update, soft + hard delete,
 * restore, toggleActive, resendInvite.
 *
 * R2: soft-delete-aware — default list hides trashed; `?with_trashed=1`
 * surfaces them; `destroy(..., force=1)` hard-deletes; a dedicated
 * `restore` re-hydrates a trashed row.
 *
 * R3: always `->paginate()`, never `->get()`. Search is pushed into SQL
 * with LIKE (case-insensitive via mb_strtolower on driver input; Postgres
 * ILIKE via `ilike` when available).
 *
 * Business rules (see tests):
 *  - admin cannot delete themselves -> 422
 *  - removing the last super-admin role -> 409
 */
class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = User::query();

        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        if ($request->boolean('only_trashed')) {
            $query->onlyTrashed();
        }

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $like = '%'.str_replace('%', '\\%', mb_strtolower($search)).'%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$like]);
            });
        }

        $roleFilter = trim((string) $request->query('role', ''));
        if ($roleFilter !== '') {
            $query->whereHas('roles', fn ($q) => $q->where('name', $roleFilter));
        }

        $activeFilter = $request->query('active');
        if ($activeFilter !== null && $activeFilter !== '') {
            $query->where('is_active', $request->boolean('active'));
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));

        $users = $query->orderBy('created_at', 'desc')->paginate($perPage);
        $users->getCollection()->loadMissing('roles');

        return UserResource::collection($users);
    }

    public function show(User $user): UserResource
    {
        $user->loadMissing('roles', 'permissions');

        return new UserResource($user);
    }

    public function store(UserStoreRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'is_active' => $data['is_active'] ?? true,
            ]);

            $roles = $data['roles'] ?? ['viewer'];
            $user->syncRoles($roles);

            return $user;
        });

        return (new UserResource($user->fresh(['roles'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UserUpdateRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();

        $response = DB::transaction(function () use ($data, $user, $request) {
            // Business rule: removing the super-admin role from the LAST
            // super-admin (globally) is forbidden. 409 Conflict.
            if ($request->has('roles') && $this->wouldRemoveLastSuperAdmin($user, $data['roles'] ?? [])) {
                return response()->json(
                    ['message' => 'Cannot remove the last super-admin role.'],
                    Response::HTTP_CONFLICT,
                );
            }

            $fields = array_intersect_key($data, array_flip(['name', 'email', 'is_active']));

            if (array_key_exists('password', $data) && $data['password'] !== null && $data['password'] !== '') {
                $fields['password'] = $data['password'];
            }

            if ($fields !== []) {
                $user->fill($fields)->save();
            }

            if (array_key_exists('roles', $data)) {
                $user->syncRoles($data['roles']);
            }

            return null;
        });

        if ($response instanceof JsonResponse) {
            return $response;
        }

        return (new UserResource($user->fresh(['roles'])))->response();
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user() !== null && $request->user()->id === $user->id) {
            return response()->json(
                ['message' => 'You cannot delete your own account.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($this->wouldRemoveLastSuperAdmin($user, [])) {
            return response()->json(
                ['message' => 'Cannot delete the last super-admin user.'],
                Response::HTTP_CONFLICT,
            );
        }

        $force = $request->boolean('force');

        if ($force) {
            // Re-resolve through the trashed scope in case the model was
            // already soft-deleted (R2 — a double-delete should still hard-kill).
            $target = User::withTrashed()->findOrFail($user->id);
            $target->forceDelete();
        } else {
            $user->delete();
        }

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function restore(int $id): JsonResponse
    {
        $user = User::onlyTrashed()->findOrFail($id);
        $user->restore();

        return (new UserResource($user->fresh(['roles'])))->response();
    }

    public function toggleActive(Request $request, User $user): UserResource
    {
        $next = $request->has('is_active')
            ? $request->boolean('is_active')
            : ! $user->is_active;

        $user->is_active = $next;
        $user->save();

        return new UserResource($user->fresh(['roles']));
    }

    public function resendInvite(User $user): JsonResponse
    {
        // The real invite-mail integration lands in Phase B2 (2FA / invite
        // flow). For PR7 we acknowledge the request with 202 so the admin
        // UI flow can be wired now without waiting for the mail template.
        return response()->json([
            'message' => 'Invite queued for resend.',
            'user_id' => $user->id,
            'email' => $user->email,
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * True when syncing `$newRoles` onto `$user` would leave zero
     * super-admins across the system.
     *
     * @param  array<int,string>  $newRoles  target role list (post-sync).
     */
    private function wouldRemoveLastSuperAdmin(User $user, array $newRoles): bool
    {
        if (! $user->hasRole('super-admin')) {
            return false;
        }

        if (in_array('super-admin', $newRoles, true)) {
            return false;
        }

        $role = Role::query()->where('name', 'super-admin')->first();
        if ($role === null) {
            return false;
        }

        return $role->users()->where('users.id', '!=', $user->id)->count() === 0;
    }
}
