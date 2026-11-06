<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\Kb\PathContextUpsertRequest;
use App\Models\KbPathContext;
use App\Services\Kb\PathContext\PathContextService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * v8.44/W4 (ADR 0035 §D6.4) — HTTP surface of path contexts (R44). Thin:
 * validate → {@see PathContextService} → JSON. Tenant-scoped (R30) via the
 * active {@see TenantContext}.
 *
 * Auth: `auth:sanctum` + `tenant.authorize` + `role:admin|super-admin` (route
 * group). R32 — covered by the AdminAuthorizationMatrix
 * (`/api/admin/kb/path-contexts`). Flag-gated (R43): with
 * `KB_PATH_CONTEXTS_ENABLED=false` EVERY action answers a clean 404
 * `path_contexts_disabled`, never a 500.
 */
final class KbPathContextController extends Controller
{
    public function __construct(
        private readonly PathContextService $paths,
        private readonly TenantContext $tenant,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! $this->paths->enabled()) {
            return $this->disabled();
        }

        $project = $request->query('project_key');
        $rows = $this->paths->list($this->tenant->current(), is_string($project) && $project !== '' ? $project : null);

        return response()->json(['data' => $rows->map(fn (KbPathContext $c): array => $this->present($c))->values()->all()]);
    }

    public function upsert(PathContextUpsertRequest $request): JsonResponse
    {
        if (! $this->paths->enabled()) {
            return $this->disabled();
        }

        $v = $request->validated();
        $row = $this->paths->upsert(
            $this->tenant->current(),
            (string) $v['project_key'],
            (string) $v['path_prefix'],
            (string) $v['description'],
            $request->user()?->id,
        );

        return response()->json(['data' => $this->present($row)]);
    }

    public function destroy(Request $request, int $id): JsonResponse|Response
    {
        if (! $this->paths->enabled()) {
            return $this->disabled();
        }

        if (! $this->paths->delete($this->tenant->current(), $id, $request->user()?->id)) {
            // IDOR-safe: an id from another tenant is indistinguishable from a missing one.
            return response()->json(['message' => 'Path context not found.', 'code' => 'path_context_not_found'], 404);
        }

        return response()->noContent();
    }

    /** @return array<string,mixed> */
    private function present(KbPathContext $c): array
    {
        return [
            'id' => $c->id,
            'project_key' => $c->project_key,
            'path_prefix' => $c->path_prefix,
            'description' => $c->description,
            'updated_at' => $c->updated_at?->toIso8601String(),
        ];
    }

    private function disabled(): JsonResponse
    {
        return response()->json([
            'code' => 'path_contexts_disabled',
            'message' => 'Path contexts are disabled on this deployment.',
        ], 404);
    }
}
