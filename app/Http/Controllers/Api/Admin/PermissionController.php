<?php

namespace App\Http\Controllers\Api\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Spatie\Permission\Models\Permission;

/**
 * Read-only permission catalogue.
 *
 * Groups Spatie permissions by their dotted domain prefix so the React
 * permission-matrix UI can render one section per domain (users.*, kb.*,
 * commands.*, logs.*, insights.*, roles.*, permissions.*, admin.*).
 */
class PermissionController extends Controller
{
    public function index(): JsonResponse
    {
        $permissions = Permission::query()
            ->orderBy('name')
            ->get(['id', 'name', 'guard_name']);

        $grouped = [];
        foreach ($permissions as $permission) {
            $domain = strtok($permission->name, '.') ?: $permission->name;
            $grouped[$domain] ??= [];
            $grouped[$domain][] = [
                'id' => $permission->id,
                'name' => $permission->name,
                'guard_name' => $permission->guard_name,
            ];
        }

        ksort($grouped);

        return response()->json([
            'data' => $permissions->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'guard_name' => $p->guard_name,
            ])->values(),
            'grouped' => $grouped,
        ]);
    }
}
