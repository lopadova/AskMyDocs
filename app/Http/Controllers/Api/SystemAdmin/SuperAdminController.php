<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\SystemAdmin;

use App\Models\User;
use App\Services\Admin\SystemAdminSuperAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Read-only global Super Admin roster.
 *
 * Route middleware provides authentication + `platform.admin`. Tenant
 * authorization and X-Tenant-Id are deliberately absent from this surface.
 */
final class SuperAdminController extends Controller
{
    public function __construct(
        private readonly SystemAdminSuperAdminService $superAdmins,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->superAdmins->paginate(
            (string) $request->query('search', ''),
            (string) $request->query('status', ''),
            $request->integer('page', 1),
            $request->integer('per_page', 25),
        ));
    }

    public function tenants(Request $request, int $user): JsonResponse
    {
        $target = User::withTrashed()->findOrFail($user);

        return response()->json($this->superAdmins->tenants(
            $target,
            $request->integer('page', 1),
            $request->integer('per_page', 25),
        ));
    }
}
