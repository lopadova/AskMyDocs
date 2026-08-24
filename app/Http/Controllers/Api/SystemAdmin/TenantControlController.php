<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\SystemAdmin;

use App\Services\Admin\Exceptions\ExistingUserRoleMismatchException;
use App\Services\Admin\Exceptions\TeamRegistryUnavailableException;
use App\Services\Admin\SystemAdminTenantService;
use App\Services\Admin\TenantProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * System-wide tenant control plane. Route middleware restricts every method
 * to authenticated users with `platform.admin`; this controller never trusts the
 * active tenant header for authorization or scoping.
 */
final class TenantControlController extends Controller
{
    public function __construct(
        private readonly SystemAdminTenantService $tenants,
        private readonly TenantProvisioningService $provisioning,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            return response()->json($this->tenants->paginate(
                (string) $request->query('search', ''),
                (string) $request->query('status', ''),
                $request->integer('page', 1),
                $request->integer('per_page', 25),
            ));
        } catch (TeamRegistryUnavailableException $e) {
            return $this->unavailable($e);
        }
    }

    public function availability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_name' => ['required', 'string', 'max:200'],
            'tenant_slug' => ['nullable', 'string', 'max:50', 'regex:/^[a-z0-9_-]{1,50}$/'],
            'user_email' => ['required', 'email', 'max:255'],
            'role' => ['nullable', 'string', Rule::in(['super-admin', 'admin', 'editor', 'viewer'])],
        ]);

        try {
            return response()->json([
                'data' => $this->provisioning->availability(
                    (string) $validated['tenant_name'],
                    isset($validated['tenant_slug']) ? (string) $validated['tenant_slug'] : null,
                    (string) $validated['user_email'],
                    isset($validated['role']) ? (string) $validated['role'] : 'admin',
                ),
            ]);
        } catch (TeamRegistryUnavailableException $e) {
            return $this->unavailable($e);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_name' => ['required', 'string', 'max:200'],
            'tenant_slug' => ['nullable', 'string', 'max:50', 'regex:/^[a-z0-9_-]{1,50}$/'],
            'user_email' => ['required', 'email', 'max:255'],
            'user_name' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', Password::defaults()],
            'role' => ['nullable', 'string', Rule::in(['super-admin', 'admin', 'editor', 'viewer'])],
            'attach_existing' => ['required', 'boolean'],
            'project_key' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/'],
        ]);

        try {
            return response()->json([
                'data' => $this->provisioning->provision($validated, $request->user()),
            ], 201);
        } catch (TeamRegistryUnavailableException $e) {
            return $this->unavailable($e);
        } catch (ExistingUserRoleMismatchException $e) {
            return response()->json([
                'error' => 'role_global_mismatch',
                'message' => $e->getMessage(),
                'details' => [
                    'requested_role' => $e->requestedRole,
                    'effective_role' => $e->effectiveRole,
                ],
            ], 422);
        }
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->tenants->detail(
                    $slug,
                    $request->integer('page', 1),
                    $request->integer('per_page', 25),
                ),
            ]);
        } catch (TeamRegistryUnavailableException $e) {
            return $this->unavailable($e);
        }
    }

    public function update(Request $request, string $slug): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'status' => ['sometimes', 'required', 'string'],
            'confirm_token' => ['sometimes', 'required', 'string'],
        ]);
        $confirmToken = isset($validated['confirm_token']) ? (string) $validated['confirm_token'] : null;
        unset($validated['confirm_token']);

        try {
            return response()->json([
                'data' => $this->tenants->update($slug, $validated, $request->user(), $confirmToken),
            ]);
        } catch (TeamRegistryUnavailableException $e) {
            return $this->unavailable($e);
        }
    }

    public function lifecyclePreview(Request $request, string $slug): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(['active', 'suspended', 'archived'])],
        ]);

        try {
            return response()->json([
                'data' => $this->tenants->previewLifecycle(
                    $slug,
                    (string) $validated['status'],
                    $request->user(),
                ),
            ]);
        } catch (TeamRegistryUnavailableException $e) {
            return $this->unavailable($e);
        }
    }

    private function unavailable(TeamRegistryUnavailableException $e): JsonResponse
    {
        return response()->json([
            'error' => 'tenant_registry_unavailable',
            'message' => $e->getMessage(),
        ], 503);
    }
}
