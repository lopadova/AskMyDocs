<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\AppSetting;
use App\Services\Admin\AppSettingsResolver;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * v8.22 (Ciclo 3) — runtime configuration governance admin surface.
 *
 * Behind `auth:sanctum` + `tenant.authorize` + `role:super-admin` (config
 * governance changes AI provider / cadence / switches — super-admin only):
 *   GET /api/admin/app-settings[?project_key=]   → effective values + provenance
 *   PUT /api/admin/app-settings                  → set/clear one governable key
 *
 * Delegates to {@see AppSettingsResolver} (R44 one core; R30 tenant-scoped;
 * deploy-only keys rejected with 422).
 */
final class AppSettingsController extends Controller
{
    public function __construct(
        private readonly AppSettingsResolver $resolver,
        private readonly TenantContext $tenants,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // Normalise an absent/empty ?project_key= back to the wildcard so it is
        // never resolved as a literal empty project scope (but keep '0').
        $projectKey = AppSetting::normalizeProjectKey($request->query('project_key'));

        return response()->json([
            'data' => $this->resolver->all($this->tenants->current(), $projectKey),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:120'],
            'project_key' => ['sometimes', 'string', 'max:120'],
            // value is type-validated by the resolver against the registry; a
            // null clears the override (inherit the next level up).
            'value' => ['present', 'nullable'],
        ]);

        // A present-but-empty project_key means "tenant-wide" — normalise to the
        // wildcard rather than persisting an empty scope (but keep '0').
        $projectKey = AppSetting::normalizeProjectKey($validated['project_key'] ?? null);

        // Resolver throws ValidationException (→ 422) on unknown / deploy-only
        // key or an invalid value for its type.
        $this->resolver->set(
            (string) $validated['key'],
            $validated['value'] ?? null,
            $this->tenants->current(),
            $projectKey,
        );

        return response()->json([
            'data' => $this->resolver->all($this->tenants->current(), $projectKey),
        ]);
    }
}
