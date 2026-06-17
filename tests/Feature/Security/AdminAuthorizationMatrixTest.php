<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * R32 — the canonical RBAC access-control regression gate.
 *
 * One data-driven matrix asserting that EVERY protected admin surface is
 * reachable by EXACTLY the roles that should reach it — no more, no less —
 * across all five roles (super-admin / admin / dpo / editor / viewer) plus
 * the unauthenticated guest.
 *
 * Why a single matrix and not only per-controller tests: per-endpoint tests
 * each cover one route, often for one or two roles, so a NEW route that
 * forgets its `role:` / `can:` gate ships green. This matrix is the one
 * place that must grow whenever a protected route, screen, gate, or role is
 * added (see CLAUDE.md R32 + `.claude/skills/rbac-authorization-matrix`).
 *
 * Assertion model — authorization is isolated from business logic:
 *   - A role NOT in the allow-set must get EXACTLY 403 (the gate denied it).
 *   - A role IN the allow-set must get ANYTHING-BUT-403 (the gate let it
 *     through; the controller may then 200/404/422/500 on data, which is not
 *     an authorization concern).
 *   - A guest must get 401 (auth gate), never 200.
 *
 * Representative endpoints: one no-path-param GET per protected group is
 * enough to exercise that group's gate. When you add a route to an existing
 * group its gate is already covered; when you add a NEW group/gate/role, add
 * a row here.
 */
final class AdminAuthorizationMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** Every role the RBAC system defines. Keep in sync with RbacSeeder::ROLES. */
    private const ALL_ROLES = ['super-admin', 'admin', 'dpo', 'editor', 'viewer'];

    /**
     * The authorization contract: representative protected endpoint → the
     * EXACT set of roles allowed to pass its gate. Derived from the
     * `role:admin|super-admin` group middleware (routes/api.php) and the
     * Gate::define() role-sets in AppServiceProvider. Adding a protected
     * route group here is mandatory per R32.
     *
     * @return array<string, list<string>>
     */
    private function matrix(): array
    {
        return [
            // ── Core admin API group — middleware role:admin|super-admin ──
            '/api/admin/metrics/overview' => ['admin', 'super-admin'],
            '/api/admin/users' => ['admin', 'super-admin'],
            '/api/admin/logs/chat' => ['admin', 'super-admin'],
            '/api/admin/insights/latest' => ['admin', 'super-admin'],
            '/api/admin/kb/tree' => ['admin', 'super-admin'],
            '/api/admin/kb/health' => ['admin', 'super-admin'],
            // v8.15/W1 — engagement analytics.
            '/api/admin/engagement/summary' => ['admin', 'super-admin'],
            // v8.15/W4 — engagement leaderboard.
            '/api/admin/engagement/leaderboard' => ['admin', 'super-admin'],
            // v8.15/W4 — engagement trend series.
            '/api/admin/engagement/series' => ['admin', 'super-admin'],
            // v8.15/W2 — digest preview.
            '/api/admin/digest/preview' => ['admin', 'super-admin'],
            '/api/admin/kb/tags' => ['admin', 'super-admin'],
            '/api/admin/kb/synonyms' => ['admin', 'super-admin'],
            '/api/admin/kb/analyses' => ['admin', 'super-admin'],
            '/api/admin/kb/analysis-settings' => ['admin', 'super-admin'],
            '/api/admin/kb/autowiki-settings' => ['admin', 'super-admin'],
            '/api/admin/kb/content-gaps' => ['admin', 'super-admin'],
            '/api/admin/kb/evidence-tiers' => ['admin', 'super-admin'],
            '/api/admin/kb/wiki-index' => ['admin', 'super-admin'],
            '/api/admin/kb/wiki-operations' => ['admin', 'super-admin'],
            '/api/admin/kb/wiki-lint' => ['admin', 'super-admin'],
            '/api/admin/kb/wiki-pages' => ['admin', 'super-admin'],
            '/api/admin/kb/documents/1/versions' => ['admin', 'super-admin'],
            '/api/admin/kb/collections' => ['admin', 'super-admin'],
            '/api/admin/kb/projects' => ['admin', 'super-admin'],
            '/api/admin/commands/catalogue' => ['admin', 'super-admin'],
            '/api/admin/compliance/reports' => ['admin', 'super-admin'],
            '/api/admin/notifications/defaults' => ['admin', 'super-admin'],

            // ── Gate-based groups — Gate::define() in AppServiceProvider ──
            '/api/admin/connectors' => ['super-admin'],                 // manageConnectors
            '/api/admin/mcp-servers' => ['super-admin'],                // manageMcpTools
            '/api/admin/mcp/tokens' => ['super-admin'],                 // manageMcpTools
            '/api/admin/mcp-tool-call-audit' => ['admin', 'super-admin'], // viewMcpAudit
            '/api/admin/pii/strategy' => ['admin', 'dpo', 'super-admin'], // viewPiiRedactorAdmin
            '/api/admin/eval-harness/bootstrap-config' => ['admin', 'dpo', 'editor', 'super-admin'], // eval-harness.viewer
            '/api/admin/tabular-reviews' => ['admin', 'viewer', 'super-admin'], // viewTabularReviews
            '/api/admin/workflows' => ['admin', 'viewer', 'super-admin'], // viewWorkflows
            '/api/admin/ai-act-compliance/overview' => ['admin', 'dpo', 'super-admin'], // viewAiActCompliance
            '/api/admin/evidence-risk-review/reviews' => ['admin', 'dpo', 'super-admin'], // viewEvidenceRiskReview
            '/api/admin/ai-finops/settings' => ['admin', 'super-admin'], // FinOpsAuthorize: GET → viewAiFinOps

            // ── Widget admin (M6) — Gate::define() in AppServiceProvider ──
            '/api/admin/widget-keys' => ['super-admin'],                     // manageWidgetKeys
            '/api/admin/widget-sessions' => ['admin', 'super-admin'],        // viewWidgetSessions
        ];
    }

    /**
     * Mount routes/api.php under the api stack so Sanctum stateful + the
     * Spatie `role:` alias both resolve (mirrors the admin controller tests).
     */
    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
    }

    public function test_roles_not_in_the_allow_set_are_denied_with_403(): void
    {
        foreach ($this->matrix() as $uri => $allowed) {
            foreach (array_diff(self::ALL_ROLES, $allowed) as $role) {
                $status = $this->actingAs($this->userWithRole($role))
                    ->getJson($uri)
                    ->getStatusCode();
                $this->assertSame(
                    403,
                    $status,
                    "Role [{$role}] must be DENIED (403) on [{$uri}] but got {$status}.",
                );
            }
        }
    }

    public function test_roles_in_the_allow_set_pass_authorization(): void
    {
        foreach ($this->matrix() as $uri => $allowed) {
            foreach ($allowed as $role) {
                $status = $this->actingAs($this->userWithRole($role))
                    ->getJson($uri)
                    ->getStatusCode();

                // Authorization passed: the gate let the role through. The
                // controller may answer 200/404/422/500 on data — none of
                // which is an authz failure — but it must NOT be 403.
                $this->assertNotSame(
                    403,
                    $status,
                    "Role [{$role}] should pass the gate for [{$uri}] but got 403.",
                );
            }
        }
    }

    public function test_guests_are_rejected_with_401_on_every_protected_endpoint(): void
    {
        foreach (array_keys($this->matrix()) as $uri) {
            $status = $this->getJson($uri)->getStatusCode();
            $this->assertSame(401, $status, "Guest must be rejected (401) on [{$uri}] but got {$status}.");
        }
    }

    /**
     * The matrix above asserts authorization with GET only. FinOps is special:
     * `FinOpsAuthorize` is METHOD-AWARE — safe verbs (GET/HEAD) require
     * `viewAiFinOps` (admin + super-admin), but mutating verbs require
     * `manageAiFinOps` (super-admin ONLY). A GET-only matrix cannot catch an
     * `admin` accidentally gaining WRITE access, so assert the write boundary
     * explicitly on a representative mutating endpoint.
     */
    public function test_finops_write_methods_require_the_manage_gate(): void
    {
        // POST → SettingsController::setKillSwitch, inside the finops auth_middleware group.
        $writeUri = '/api/admin/ai-finops/settings/kill-switch';

        // admin passes the READ gate but must be DENIED (403) on a WRITE.
        $adminStatus = $this->actingAs($this->userWithRole('admin'))
            ->postJson($writeUri, [])
            ->getStatusCode();
        $this->assertSame(
            403,
            $adminStatus,
            "Role [admin] must be DENIED (403) on write [{$writeUri}] (manageAiFinOps = super-admin only) but got {$adminStatus}.",
        );

        // super-admin passes the MANAGE gate; the controller may 200/422/500 on the
        // empty body — none of which is an authz failure — but it must NOT be 403.
        $superStatus = $this->actingAs($this->userWithRole('super-admin'))
            ->postJson($writeUri, [])
            ->getStatusCode();
        $this->assertNotSame(
            403,
            $superStatus,
            "Role [super-admin] must pass the manage gate on write [{$writeUri}] but got 403.",
        );

        // (Guest auth on finops routes is already covered by
        // test_guests_are_rejected_with_401_on_every_protected_endpoint — this
        // method's job is the admin-vs-super-admin WRITE boundary specifically.)
    }

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role),
            'email' => $role.'-'.uniqid().'@demo.local',
            'password' => Hash::make('secret-password'),
        ]);
        $user->assignRole($role);

        return $user;
    }
}
