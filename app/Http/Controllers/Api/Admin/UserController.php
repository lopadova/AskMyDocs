<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\UserStoreRequest;
use App\Http\Requests\Admin\UserUpdateRequest;
use App\Http\Resources\Admin\UserResource;
use App\Models\User;
use App\Notifications\NotificationPreferencesInitializer;
use App\Support\LikeEscaper;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            // R19 — escape ALL LIKE meta-chars (%, _, ~) + explicit ESCAPE.
            // The escape char is `~`, not `\`: a backslash ESCAPE clause
            // triggers SQLSTATE[HY093] on Postgres+PDO (see LikeEscaper).
            $like = LikeEscaper::contains(mb_strtolower($search));
            $query->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(name) LIKE ? '.LikeEscaper::ESCAPE_SQL, [$like])
                    ->orWhereRaw('LOWER(email) LIKE ? '.LikeEscaper::ESCAPE_SQL, [$like]);
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

    public function store(
        UserStoreRequest $request,
        NotificationPreferencesInitializer $notifInitializer,
        TenantContext $tenants,
    ): JsonResponse
    {
        $data = $request->validated();

        $user = DB::transaction(function () use ($data, $notifInitializer, $tenants) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'is_active' => $data['is_active'] ?? true,
            ]);

            $roles = $data['roles'] ?? ['viewer'];
            $user->syncRoles($roles);

            // v8.0/W2.3 — initialise `notification_preferences` from the
            // active tenant's baseline so the dispatcher has rows to
            // consult on the user's very first event. Idempotent at
            // the DB level (composite unique on the prefs table); the
            // explicit tenant_id avoids R30 ambiguity for User (cross-
            // tenant identity).
            $notifInitializer->seedFromTenantDefaults($user->id, $tenants->current());

            return $user;
        });

        return (new UserResource($user->fresh(['roles'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UserUpdateRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();

        // M2 (R21) — the last-super-admin guard + the role mutation run in
        // ONE transaction with a row lock, so two concurrent demotions
        // cannot both pass the check and leave zero super-admins. L2 — the
        // guard aborts with 409 instead of returning a JsonResponse from
        // inside the closure (which mixed HTTP concerns into the txn).
        DB::transaction(function () use ($data, $user, $request) {
            if ($request->has('roles') && $this->wouldRemoveLastSuperAdmin($user, $data['roles'] ?? [])) {
                abort(Response::HTTP_CONFLICT, 'Cannot remove the last super-admin role.');
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
        });

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

        $force = $request->boolean('force');

        // M2 (R21) — guard + delete inside one locked transaction so two
        // concurrent deletes of different super-admins can't both pass.
        DB::transaction(function () use ($user, $force) {
            if ($this->wouldRemoveLastSuperAdmin($user, [])) {
                abort(Response::HTTP_CONFLICT, 'Cannot delete the last super-admin user.');
            }

            if ($force) {
                // Re-resolve through the trashed scope in case the model was
                // already soft-deleted (R2 — a double-delete should still hard-kill).
                $target = User::withTrashed()->findOrFail($user->id);
                $target->forceDelete();
            } else {
                $user->delete();
            }
        });

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
        // M1 — invite-mail delivery is NOT implemented yet (roadmap: admin
        // invite resend email). Do not pretend an email went out: log a
        // warning so operators can see the no-op, and return an honest
        // message. L7 — the user's email is dropped from the response (PII
        // the caller already has + would otherwise land in access logs).
        Log::warning('resendInvite acknowledged but no invite email was sent — mail delivery is not yet enabled on this deployment.', [
            'user_id' => $user->id,
        ]);

        return response()->json([
            'message' => 'Invite resend acknowledged. Email delivery is not yet enabled on this deployment.',
            'user_id' => $user->id,
            'email_sent' => false,
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

        // R21 — lockForUpdate so a concurrent demotion/delete of another
        // super-admin is serialized against this check (callers run this
        // inside a DB::transaction). On SQLite this is a no-op (whole-DB
        // lock); on Postgres/MySQL it holds the row locks for the txn.
        //
        // NOTE: must NOT combine FOR UPDATE with an aggregate — PostgreSQL
        // rejects `SELECT count(*) ... FOR UPDATE` ("FOR UPDATE is not
        // allowed with aggregate functions"). So we lock the actual rows
        // (pluck ids under lockForUpdate) and count in PHP.
        $otherSuperAdminIds = $role->users()
            ->where('users.id', '!=', $user->id)
            ->lockForUpdate()
            ->pluck('users.id');

        return $otherSuperAdminIds->isEmpty();
    }
}
