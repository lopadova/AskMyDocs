<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Services\Admin\Exceptions\TeamRegistryUnavailableException;
use App\Services\Admin\TeamRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Admin RESTful surface for team (= tenant) management — create a team and
 * rename a team. A team is a `tenant_id`/`slug`; its editable display name
 * lives on the vendor `tenants` row read by the topbar switcher
 * (`UserTeamsResolver`). This controller is a THIN adapter over
 * {@see TeamRegistryService}: all validation, authorization and the write
 * ordering live in the service, shared verbatim with the `team:create` /
 * `team:rename` Artisan commands (R44).
 *
 * Auth: `auth:sanctum` + `tenant.authorize` + `role:admin|super-admin`
 * (route group). Rename additionally authorizes the TARGET team inside the
 * service (membership OR `tenant.cross-access`), independently of the
 * request's `X-Tenant-Id` — team management is cross-tenant, so the header
 * scope does not authorize it. A team the actor may not administer 404s.
 *
 * R44 — DELIBERATE surface scope: like {@see ProjectController}, team
 * management is an admin GOVERNANCE affordance, not an agent-facing
 * capability. A `tenant_id` is already usable across every surface (CLI,
 * HTTP, MCP retrieval) WITHOUT a registry row — the row only adds a human
 * name for the switcher. There is no agent workflow that should mint or
 * rename a tenant, and a cross-tenant WRITE tool conflicts with the
 * propose-only MCP posture, so this ships HTTP + Artisan only (no MCP tool)
 * on purpose. Should agent-driven team governance ever be needed, add a
 * tool over TeamRegistryService rather than a parallel implementation.
 *
 * OFF-path (R14/R43): when the `tenants` table is absent, create/rename
 * return HTTP 503 with a clear body — never a 500 or a silent success.
 */
final class TeamController extends Controller
{
    public function __construct(private readonly TeamRegistryService $teams) {}

    /**
     * GET /api/admin/teams
     *
     * Teams the authenticated user may see/administer (their memberships +
     * every active team when they hold `tenant.cross-access`, plus the
     * read-only `default`), with tenant-wide project/member counts and a
     * per-row `can_manage` flag.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->teams->manageableTeams($request->user()),
        ]);
    }

    /**
     * POST /api/admin/teams
     *
     * `name` is required; `slug` is optional (slugged from the name when
     * omitted, validated for shape + global uniqueness, reserved `default`
     * rejected). Creates the registry row + an initial project + a
     * membership for the acting user so the team is immediately usable and
     * appears in their switcher.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $team = $this->teams->create(
                $request->input('slug') !== null ? (string) $request->input('slug') : null,
                (string) $request->input('name', ''),
                $request->user(),
            );
        } catch (TeamRegistryUnavailableException $e) {
            return $this->unavailable($e);
        }

        return response()->json(['data' => $team], 201);
    }

    /**
     * PATCH /api/admin/teams/{slug}
     *
     * Renames the team — updates `tenants.name`. `slug` is the immutable
     * route key (it is the `tenant_id` every tenant-aware row references).
     */
    public function update(Request $request, string $slug): JsonResponse
    {
        try {
            $team = $this->teams->rename($slug, (string) $request->input('name', ''), $request->user());
        } catch (TeamRegistryUnavailableException $e) {
            return $this->unavailable($e);
        }

        return response()->json(['data' => $team]);
    }

    private function unavailable(TeamRegistryUnavailableException $e): JsonResponse
    {
        return response()->json([
            'error' => 'team_registry_unavailable',
            'message' => $e->getMessage(),
        ], 503);
    }
}
