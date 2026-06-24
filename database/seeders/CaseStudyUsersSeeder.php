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
 * For EACH of the 3 case-study companies it creates THREE accounts — a `viewer`,
 * an `admin` and a `super-admin` — so every permission tier can be exercised
 * per company (e.g. the super-admin is the one that can reach Admin → Connectors,
 * gated `manageConnectors` = super-admin only).
 *
 * Isolation ("the user of company X must only ever see X's documents") is driven
 * by `project_memberships`, not by the role: this seeder pins every account's
 * memberships to EXACTLY its own company — it deletes any stray memberships on
 * other projects (e.g. the all-projects backfill of {@see RbacSeeder}, which
 * grants every existing user a membership on every project_key that already has
 * documents) and (re)creates the single own-project membership. So a viewer
 * reads exclusively its own project (with `KB_PROJECT_ISOLATION_ENABLED=true`).
 * NB: by role design `admin` (kb.read.all_projects) and `super-admin` still see
 * across companies — only the `viewer` tier is membership-isolated.
 *
 * Idempotent: firstOrCreate on the unique tuples; role assignment guarded by
 * hasRole; the membership reset is deterministic. **Run LAST** (after RbacSeeder,
 * which must exist for the roles), otherwise a later RbacSeeder backfill would
 * re-widen the viewers:
 *
 *   php artisan db:seed --class=Database\\Seeders\\RbacSeeder
 *   php artisan db:seed --class=Database\\Seeders\\CaseStudyUsersSeeder
 *
 * The project keys match `docs/case-studies/data/<key>/` one-for-one (gated by
 * tests/Unit/CaseStudies/CaseStudyDatasetTest). All accounts use the demo
 * password `password`.
 */
class CaseStudyUsersSeeder extends Seeder
{
    private const PASSWORD = 'password';

    /**
     * project_key => [display name, description, accounts[]]. Each account is
     * [email, display name, Spatie role]. The `viewer` email is the historical
     * one referenced by tests/docs — do not rename it.
     *
     * @var array<string, array{name: string, desc: string, accounts: list<array{email: string, user: string, role: string}>}>
     */
    private const COMPANIES = [
        'rotta-logistics' => [
            'name' => 'Rotta Sicura Logistics',
            'desc' => 'Logistica e spedizioni (case study isolamento).',
            'accounts' => [
                ['email' => 'rotta@case-study.local', 'user' => 'Rotta Logistics — Viewer', 'role' => 'viewer'],
                ['email' => 'rotta.admin@case-study.local', 'user' => 'Rotta Logistics — Admin', 'role' => 'admin'],
                ['email' => 'rotta.super@case-study.local', 'user' => 'Rotta Logistics — Super Admin', 'role' => 'super-admin'],
            ],
        ],
        'prometeo-antincendio' => [
            'name' => 'Prometeo Sicurezza Antincendio',
            'desc' => 'Normativa antincendio / Vigili del Fuoco (case study isolamento).',
            'accounts' => [
                ['email' => 'prometeo@case-study.local', 'user' => 'Prometeo Antincendio — Viewer', 'role' => 'viewer'],
                ['email' => 'prometeo.admin@case-study.local', 'user' => 'Prometeo Antincendio — Admin', 'role' => 'admin'],
                ['email' => 'prometeo.super@case-study.local', 'user' => 'Prometeo Antincendio — Super Admin', 'role' => 'super-admin'],
            ],
        ],
        'passolibero-calzature' => [
            'name' => 'PassoLibero Calzature',
            'desc' => 'Vendita scarpe e-commerce (case study isolamento).',
            'accounts' => [
                ['email' => 'passolibero@case-study.local', 'user' => 'PassoLibero Calzature — Viewer', 'role' => 'viewer'],
                ['email' => 'passolibero.admin@case-study.local', 'user' => 'PassoLibero Calzature — Admin', 'role' => 'admin'],
                ['email' => 'passolibero.super@case-study.local', 'user' => 'PassoLibero Calzature — Super Admin', 'role' => 'super-admin'],
            ],
        ],
    ];

    public function run(): void
    {
        // All three companies live in the `default` tenant — isolation here is
        // logical (per project_key + membership). Pin the context so every
        // tenant-aware row (Project, ProjectMembership) auto-fills tenant_id.
        $ctx = app(TenantContext::class);
        $previous = $ctx->current();
        $ctx->set('default');

        try {
            foreach (self::COMPANIES as $projectKey => $meta) {
                Project::updateOrCreate(
                    ['tenant_id' => 'default', 'project_key' => $projectKey],
                    ['name' => $meta['name'], 'description' => $meta['desc']],
                );

                foreach ($meta['accounts'] as $account) {
                    $this->seedAccount($projectKey, $account);
                }
            }
        } finally {
            $ctx->set($previous);
        }
    }

    /**
     * @param  array{email: string, user: string, role: string}  $account
     */
    private function seedAccount(string $projectKey, array $account): void
    {
        $user = User::firstOrCreate(
            ['email' => $account['email']],
            ['name' => $account['user'], 'password' => Hash::make(self::PASSWORD)],
        );

        if (! $user->hasRole($account['role'])) {
            $user->assignRole($account['role']);
        }

        // Isolamento "per bene": le membership di questo account = SOLO la sua
        // azienda. Rimuove eventuali membership su altri progetti (es. il
        // backfill all-projects di RbacSeeder) così il viewer non vede altre
        // aziende. Va eseguito DOPO RbacSeeder perché annulli quel backfill.
        ProjectMembership::query()
            ->where('tenant_id', 'default')
            ->where('user_id', $user->id)
            ->where('project_key', '!=', $projectKey)
            ->delete();

        ProjectMembership::firstOrCreate(
            ['tenant_id' => 'default', 'user_id' => $user->id, 'project_key' => $projectKey],
            ['role' => 'member', 'scope_allowlist' => null],
        );
    }
}
