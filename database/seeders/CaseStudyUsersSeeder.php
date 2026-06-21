<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Per-company users + projects for the documentation-isolation case study.
 *
 * `docs/case-studies/ingest.sh` deliberately grants EVERY existing user a
 * membership on ALL THREE case-study projects so the topbar Project Switcher
 * can demo cross-project browsing. That is the OPPOSITE of what the isolation
 * objective ("the user of company X must only ever see X's documents") needs.
 *
 * This seeder creates the per-user axis instead: one `viewer` account per
 * company, each a member of ONLY its own project. Combined with
 * `KB_PROJECT_ISOLATION_ENABLED=true` (config `kb.project_isolation.enabled`),
 * a logged-in company user reads exclusively their own project's documents and
 * citations — the membership set, not `kb.read.any`, becomes the lever
 * (see {@see \Database\Seeders\RbacSeeder} for the permission split and
 * `tests/Feature/Rbac/CaseStudyProjectIsolationTest` for the assertion).
 *
 * Idempotent: firstOrCreate on the unique (tenant_id, project_key) /
 * (tenant_id, user_id, project_key) tuples, role assignment guarded by
 * hasRole. Run explicitly — it is intentionally NOT auto-wired into any
 * seeder (a case-study fixture, like the dataset itself):
 *
 *   php artisan db:seed --class=Database\\Seeders\\CaseStudyUsersSeeder
 *
 * Prerequisite: roles must exist (run RbacSeeder first, or DemoSeeder which
 * seeds it). The accounts all use the demo password `password`.
 */
class CaseStudyUsersSeeder extends Seeder
{
    private const PASSWORD = 'password';

    private const ROLE = 'viewer';

    /**
     * project_key => [display name, description, account email, account name].
     * The project keys match `docs/case-studies/data/<key>/` one-for-one — the
     * single source of truth gated by tests/Unit/CaseStudies/CaseStudyDatasetTest.
     *
     * @var array<string, array{name: string, desc: string, email: string, user: string}>
     */
    private const COMPANIES = [
        'rotta-logistics' => [
            'name' => 'Rotta Sicura Logistics',
            'desc' => 'Logistica e spedizioni (case study isolamento).',
            'email' => 'rotta@case-study.local',
            'user' => 'Rotta Logistics User',
        ],
        'prometeo-antincendio' => [
            'name' => 'Prometeo Sicurezza Antincendio',
            'desc' => 'Normativa antincendio / Vigili del Fuoco (case study isolamento).',
            'email' => 'prometeo@case-study.local',
            'user' => 'Prometeo Antincendio User',
        ],
        'passolibero-calzature' => [
            'name' => 'PassoLibero Calzature',
            'desc' => 'Vendita scarpe e-commerce (case study isolamento).',
            'email' => 'passolibero@case-study.local',
            'user' => 'PassoLibero Calzature User',
        ],
    ];

    public function run(): void
    {
        // All three companies live in the `default` tenant — isolation here is
        // logical (per project_key + membership), exactly as the case-study
        // README describes the dev environment. Pin the context so every
        // tenant-aware row (Project, ProjectMembership) auto-fills tenant_id.
        $ctx = app(TenantContext::class);
        $previous = $ctx->current();
        $ctx->set('default');

        try {
            foreach (self::COMPANIES as $projectKey => $meta) {
                $this->seedCompany($projectKey, $meta);
            }
        } finally {
            $ctx->set($previous);
        }
    }

    /**
     * @param  array{name: string, desc: string, email: string, user: string}  $meta
     */
    private function seedCompany(string $projectKey, array $meta): void
    {
        Project::updateOrCreate(
            ['tenant_id' => 'default', 'project_key' => $projectKey],
            ['name' => $meta['name'], 'description' => $meta['desc']],
        );

        $user = User::firstOrCreate(
            ['email' => $meta['email']],
            ['name' => $meta['user'], 'password' => Hash::make(self::PASSWORD)],
        );

        if (! $user->hasRole(self::ROLE)) {
            $user->assignRole(self::ROLE);
        }

        // Membership to ONLY this company's project — the whole point of the
        // per-user isolation axis. firstOrCreate keyed on the tenant-scoped
        // (tenant_id, user_id, project_key) tuple so re-running never widens
        // the user's reach.
        ProjectMembership::firstOrCreate(
            ['tenant_id' => 'default', 'user_id' => $user->id, 'project_key' => $projectKey],
            ['role' => 'member', 'scope_allowlist' => null],
        );
    }
}
