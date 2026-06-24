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
            '/api/admin/projects' => ['admin', 'super-admin'],
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
            // v8.18/W4 — AI gamification insights (read). The POST regenerate is
            // super-admin only and is covered by a dedicated authz test (the
            // GET-based matrix would 405 a POST-only route).
            '/api/admin/engagement/insights' => ['admin', 'super-admin'],
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
            '/api/admin/kb/uploads' => ['admin', 'super-admin'],
            '/api/admin/commands/catalogue' => ['admin', 'super-admin'],
            '/api/admin/compliance/reports' => ['admin', 'super-admin'],
            '/api/admin/notifications/defaults' => ['admin', 'super-admin'],

            // ── Gate-based groups — Gate::define() in AppServiceProvider ──
            '/api/admin/connectors' => ['super-admin'],                 // manageConnectors
            '/api/admin/ingestion/queue' => ['super-admin'],            // manageConnectors (v8.21 Ciclo 2)
            '/api/admin/mcp-servers' => ['super-admin'],                // manageMcpTools
            '/api/admin/mcp/tokens' => ['super-admin'],                 // manageMcpTools
            '/api/admin/mcp-tool-call-audit' => ['admin', 'super-admin'], // viewMcpAudit
            '/api/admin/pii/strategy' => ['admin', 'dpo', 'super-admin'], // viewPiiRedactorAdmin
            '/api/admin/pii/policy' => ['admin', 'dpo', 'super-admin'], // viewPiiRedactorAdmin (GET); PUT adds manageKbPiiPolicy (see write-boundary test)
            '/api/admin/eval-harness/bootstrap-config' => ['admin', 'dpo', 'editor', 'super-admin'], // eval-harness.viewer
            '/api/admin/tabular-reviews' => ['admin', 'viewer', 'super-admin'], // viewTabularReviews
            '/api/admin/workflows' => ['admin', 'viewer', 'super-admin'], // viewWorkflows
            '/api/admin/ai-act-compliance/overview' => ['admin', 'dpo', 'super-admin'], // viewAiActCompliance
            '/api/admin/evidence-risk-review/reviews' => ['admin', 'dpo', 'super-admin'], // viewEvidenceRiskReview
            '/api/admin/ai-finops/settings' => ['admin', 'super-admin'], // FinOpsAuthorize: GET → viewAiFinOps
            '/api/admin/ai-guardrails/overview' => ['admin', 'super-admin'], // GuardrailsAuthorize: GET → viewAiGuardrails
            // padosoft/laravel-invitations admin surface — config('invitations.routes.admin_middleware')
            // = SPA-session + auth:sanctum + tenant.authorize + can:manageInvitations.
            '/api/admin/invitations/metrics' => ['admin', 'super-admin'], // manageInvitations

            // ── Role-middleware groups — `role:` middleware, not a Gate ──
            '/api/admin/app-settings' => ['super-admin'],               // role:super-admin (v8.22 Ciclo 3)

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

    /**
     * v8.23 (Ciclo 4) — the PII ingestion-policy API splits authorization by
     * HTTP method: GET rides `viewPiiRedactorAdmin` (admin / dpo / super-admin,
     * proved by the matrix above), but the PUT adds `can:manageKbPiiPolicy`
     * (dpo / super-admin ONLY). The GET-only matrix cannot catch an `admin`
     * accidentally gaining WRITE access to the privacy posture, so assert the
     * write boundary explicitly.
     */
    public function test_kb_pii_policy_write_requires_the_manage_gate(): void
    {
        $writeUri = '/api/admin/pii/policy';
        $body = ['project_key' => '*', 'redact_enabled' => true, 'strategy' => 'mask'];

        // admin passes the READ gate but must be DENIED (403) on a WRITE.
        $adminStatus = $this->actingAs($this->userWithRole('admin'))
            ->putJson($writeUri, $body)
            ->getStatusCode();
        $this->assertSame(
            403,
            $adminStatus,
            "Role [admin] must be DENIED (403) on write [{$writeUri}] (manageKbPiiPolicy = dpo/super-admin only) but got {$adminStatus}.",
        );

        // dpo + super-admin pass the manage gate; the controller may 200/422 on
        // the body — never 403.
        foreach (['dpo', 'super-admin'] as $role) {
            $status = $this->actingAs($this->userWithRole($role))
                ->putJson($writeUri, $body)
                ->getStatusCode();
            $this->assertNotSame(
                403,
                $status,
                "Role [{$role}] must pass the manage gate on write [{$writeUri}] but got 403.",
            );
        }
    }

    /**
     * v8.19 — the AI Guardrails API splits authorization by HTTP method via
     * GuardrailsAuthorize: safe methods → `viewAiGuardrails` (super-admin + admin),
     * mutating methods → `manageAiGuardrails` (super-admin ONLY). The GET-only
     * matrix above proves the read gate; this asserts the WRITE boundary so a
     * regression that let `admin` mutate the guardrail ruleset can't ship green.
     */
    public function test_guardrails_write_methods_require_the_manage_gate(): void
    {
        // PUT → the package SettingsController (overridable ruleset), inside the
        // guardrails.authorize group: a mutating method requires manageAiGuardrails.
        $writeUri = '/api/admin/ai-guardrails/settings';

        // admin passes the READ gate but must be DENIED (403) on a WRITE.
        $adminStatus = $this->actingAs($this->userWithRole('admin'))
            ->putJson($writeUri, [])
            ->getStatusCode();
        $this->assertSame(
            403,
            $adminStatus,
            "Role [admin] must be DENIED (403) on write [{$writeUri}] (manageAiGuardrails = super-admin only) but got {$adminStatus}.",
        );

        // super-admin passes the MANAGE gate; the controller may 200/422 on the
        // empty body — neither is an authz failure — but it must NOT be 403.
        $superStatus = $this->actingAs($this->userWithRole('super-admin'))
            ->putJson($writeUri, [])
            ->getStatusCode();
        $this->assertNotSame(
            403,
            $superStatus,
            "Role [super-admin] must pass the manage gate on write [{$writeUri}] but got 403.",
        );
    }

    /**
     * v8.17 — the credential-connector `configure` endpoint is POST-only (a GET
     * would 405, so it can't ride the GET matrix above). It sits in the same
     * `admin/connectors` group gated by `can:manageConnectors` (super-admin only),
     * so assert the write boundary explicitly: admin denied, super-admin passes.
     * (Guest → 401 is already covered for the whole `admin/connectors` group by
     * test_guests_are_rejected_with_401 on the GET `/api/admin/connectors` entry,
     * which shares this route's identical middleware stack.)
     */
    public function test_configure_connector_requires_the_manage_connectors_gate(): void
    {
        $writeUri = '/api/admin/connectors/imap/configure';

        // admin is NOT in the manageConnectors allow-set → 403.
        $adminStatus = $this->actingAs($this->userWithRole('admin'))
            ->postJson($writeUri, [])
            ->getStatusCode();
        $this->assertSame(
            403,
            $adminStatus,
            "Role [admin] must be DENIED (403) on [{$writeUri}] (manageConnectors = super-admin only) but got {$adminStatus}.",
        );

        // super-admin passes the gate; the controller/FormRequest may 422 on the
        // empty body — not an authz failure — but it must NOT be 403.
        $superStatus = $this->actingAs($this->userWithRole('super-admin'))
            ->postJson($writeUri, [])
            ->getStatusCode();
        $this->assertNotSame(
            403,
            $superStatus,
            "Role [super-admin] must pass the manageConnectors gate on [{$writeUri}] but got 403.",
        );
        // Also guard route wiring: a 404 would make "not 403" pass even if the
        // endpoint were unmounted. An empty body for a super-admin yields 422.
        $this->assertNotSame(
            404,
            $superStatus,
            "[{$writeUri}] must be MOUNTED (super-admin got 404 — route missing?).",
        );
    }

    /**
     * v8.20 — the multi-account `PATCH /api/admin/connectors/{installationId}`
     * metadata edit. PATCH-only (can't ride the GET matrix), same
     * `can:manageConnectors` (super-admin only) gate as the rest of the group.
     * Assert the write boundary explicitly: admin denied, super-admin passes
     * the gate (a non-existent id then 404s — not an authz failure).
     */
    public function test_update_connector_installation_requires_the_manage_connectors_gate(): void
    {
        $writeUri = '/api/admin/connectors/1';

        $adminStatus = $this->actingAs($this->userWithRole('admin'))
            ->patchJson($writeUri, ['label' => 'x'])
            ->getStatusCode();
        $this->assertSame(
            403,
            $adminStatus,
            "Role [admin] must be DENIED (403) on PATCH [{$writeUri}] (manageConnectors = super-admin only) but got {$adminStatus}.",
        );

        // super-admin passes the gate; no installation #1 exists → exactly 404
        // (not 403, and not a 500 that would also satisfy "not 403"). Asserting
        // the precise status keeps this a real route+gate regression check.
        $superStatus = $this->actingAs($this->userWithRole('super-admin'))
            ->patchJson($writeUri, ['label' => 'x'])
            ->getStatusCode();
        $this->assertSame(
            404,
            $superStatus,
            "Role [super-admin] must pass the manageConnectors gate and then 404 on the missing id for PATCH [{$writeUri}] but got {$superStatus}.",
        );
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
