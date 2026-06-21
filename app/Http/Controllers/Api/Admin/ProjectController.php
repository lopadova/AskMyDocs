<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\Project;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Admin RESTful CRUD on `projects` — the first-class project registry.
 *
 * A project is identified by `project_key` (the stable join key used by
 * documents, memberships, chat logs, …) and carries a human `name` +
 * optional `description`. The key is auto-slugged from the name on
 * create and is IMMUTABLE afterwards (changing it would orphan every
 * row that references it), so `update` only touches name/description and
 * rejects a key change with 422.
 *
 * Soft registry (no hard FK): deleting a project row is blocked with 422
 * while any knowledge_document or project_membership still references the
 * key, so the registry can never drift out of sync with real content by
 * silently removing an in-use project.
 *
 * Auth: `auth:sanctum` + `tenant.authorize` + `role:admin|super-admin`
 * (route group). R30 — every read/write is tenant-scoped via
 * `forTenant($this->tenant->current())`; cross-tenant access is
 * structurally impossible and two teams may share the same key (R28).
 *
 * R44 — DELIBERATE single-surface exception: the registry CRUD is an admin
 * SPA governance affordance, not an agent-facing capability. A `project_key`
 * is already usable across ALL surfaces (CLI `kb:ingest-folder`, HTTP ingest,
 * MCP retrieval/`KbSearchByProjectTool`) WITHOUT a registry row — the row only
 * adds a human name/description + delete-guard for the admin UI. There is no
 * agent workflow that needs to create/rename/delete a registry entry, so this
 * ships HTTP-only (no Artisan command, no MCP tool) on purpose. Should
 * agent-driven project governance ever be needed, add a tool over the same
 * model rather than a parallel implementation.
 */
final class ProjectController extends Controller
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * GET /api/admin/projects
     *
     * Lists the active team's projects with live document + membership
     * counts (computed per the project's tenant), newest activity first.
     */
    public function index(): JsonResponse
    {
        $tenantId = $this->tenant->current();

        $projects = Project::query()
            ->forTenant($tenantId)
            ->orderBy('name')
            ->get(['id', 'project_key', 'name', 'description', 'created_at', 'updated_at']);

        $docCounts = $this->countsByKey('knowledge_documents', $tenantId, withTrashedGuard: true);
        $memberCounts = $this->countsByKey('project_memberships', $tenantId);

        $data = $projects->map(fn (Project $p) => [
            'id' => $p->id,
            'project_key' => $p->project_key,
            'name' => $p->name,
            'description' => $p->description,
            'document_count' => (int) ($docCounts[$p->project_key] ?? 0),
            'member_count' => (int) ($memberCounts[$p->project_key] ?? 0),
            'created_at' => $p->created_at,
            'updated_at' => $p->updated_at,
        ])->all();

        return response()->json(['data' => $data]);
    }

    /**
     * POST /api/admin/projects
     *
     * `name` is required; `project_key` is optional — when omitted it is
     * slugged from the name. The key is validated for slug shape and
     * per-(tenant) uniqueness, so the user gets a 422 instead of a raw DB
     * integrity error.
     */
    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'project_key' => $this->resolveKey(
                (string) $request->input('project_key', ''),
                (string) $request->input('name', ''),
            ),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'project_key' => [
                'required',
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('projects', 'project_key')
                    ->where(fn ($q) => $q->where('tenant_id', $this->tenant->current())),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $project = Project::create($validated);

        return response()->json([
            'data' => $project->only(['id', 'project_key', 'name', 'description', 'created_at', 'updated_at']),
        ], 201);
    }

    /**
     * PATCH /api/admin/projects/{id}
     *
     * Updates name + description only. `project_key` is the immutable join
     * key — a change is rejected with 422 (delete + recreate is the
     * honest path, and even that is blocked while content references it).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $project = $this->findOr404($id);

        if ($request->has('project_key') && $request->input('project_key') !== $project->project_key) {
            throw ValidationException::withMessages([
                'project_key' => ['The project key is immutable. Create a new project instead.'],
            ]);
        }

        $validated = $request->validate([
            // `required` under `sometimes` fires only when the key is present and
            // forbids an empty/null value — mirrors store()'s non-empty contract
            // so a PATCH can't blank a name that creation would have rejected.
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $project->update($validated);

        return response()->json([
            'data' => $project->only(['id', 'project_key', 'name', 'description', 'created_at', 'updated_at']),
        ]);
    }

    /**
     * DELETE /api/admin/projects/{id}
     *
     * Blocked with 422 while any document or membership references the
     * key — deleting would orphan those rows from the registry. The admin
     * must move/remove the content first.
     */
    public function destroy(int $id): JsonResponse
    {
        $project = $this->findOr404($id);

        $docs = $this->keyUsageCount('knowledge_documents', $project->tenant_id, $project->project_key, withTrashedGuard: true);
        $members = $this->keyUsageCount('project_memberships', $project->tenant_id, $project->project_key);

        if ($docs > 0 || $members > 0) {
            throw ValidationException::withMessages([
                'project_key' => [sprintf(
                    'Cannot delete: %d document(s) and %d membership(s) still use this project. Reassign or remove them first.',
                    $docs,
                    $members,
                )],
            ]);
        }

        $project->delete();

        return response()->json(null, 204);
    }

    /**
     * Resolve the project key: use the explicit one if given, else slug
     * the name. Empty result is left to validation to reject.
     */
    private function resolveKey(string $key, string $name): string
    {
        $key = trim($key);
        if ($key !== '') {
            return Str::slug($key);
        }

        return Str::slug($name);
    }

    /**
     * @return array<string,int> project_key => count, for the tenant.
     */
    private function countsByKey(string $table, string $tenantId, bool $withTrashedGuard = false): array
    {
        $query = DB::table($table)
            ->select('project_key', DB::raw('COUNT(*) as c'))
            ->where('tenant_id', $tenantId)
            ->whereNotNull('project_key');

        if ($withTrashedGuard) {
            $query->whereNull('deleted_at');
        }

        return $query->groupBy('project_key')->pluck('c', 'project_key')->all();
    }

    private function keyUsageCount(string $table, string $tenantId, string $key, bool $withTrashedGuard = false): int
    {
        $query = DB::table($table)
            ->where('tenant_id', $tenantId)
            ->where('project_key', $key);

        if ($withTrashedGuard) {
            $query->whereNull('deleted_at');
        }

        return (int) $query->count();
    }

    /**
     * R30 — scope by tenant so an admin in tenant A cannot read/update/
     * delete a project of tenant B by guessing its id (IDOR). A miss is a
     * 404, not a 403, so the row's existence stays hidden.
     */
    private function findOr404(int $id): Project
    {
        $project = Project::query()->forTenant($this->tenant->current())->find($id);
        if ($project === null) {
            throw new NotFoundHttpException('Project not found.');
        }

        return $project;
    }
}
