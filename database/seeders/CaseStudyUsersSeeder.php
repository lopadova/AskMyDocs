<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;

/**
 * Per-company users + projects for the documentation-isolation case study.
 *
 * For EACH of the 3 case-study companies it creates THREE accounts — a `viewer`,
 * an `admin` and a `super-admin` — so every permission tier can be exercised
 * per company (e.g. the super-admin is the one that can reach Admin → Connectors,
 * gated `manageConnectors` = super-admin only).
 *
 * ONE TENANT PER COMPANY: tenant_id = project_key (rotta-logistics, …). The tenant
 * is the platform's isolation primitive, so EVERY admin surface (connectors,
 * users, KB, …) is scoped per company — not just the documents. Each account's
 * membership is pinned to EXACTLY its own (tenant, project): the seeder deletes any
 * stray membership elsewhere (e.g. the all-projects backfill of {@see RbacSeeder}
 * on the default tenant) and (re)creates the single own membership. A user thus
 * only ever sees its own company's tenant after the team switcher resolves it.
 *
 * The membership reset applies to EVERY tier — viewer, admin AND super-admin —
 * not just the viewer: {@see seedAccount()} deletes any non-company membership
 * for each account regardless of role.
 *
 * Idempotent: firstOrCreate on the unique tuples; role assignment guarded by
 * hasRole; the membership reset is deterministic. **Run LAST** (after RbacSeeder,
 * which must exist for the roles), otherwise a later RbacSeeder backfill would
 * re-widen these memberships again (every tier, not only the viewer):
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
        // UN TENANT PER AZIENDA: tenant_id = project_key. Così l'isolamento vale
        // su TUTTE le superfici (connettori, utenti, KB, ...) via lo scope-tenant
        // della piattaforma, non solo sul project_key.
        $ctx = app(TenantContext::class);
        $previous = $ctx->current();

        try {
            foreach (self::COMPANIES as $projectKey => $meta) {
                $tenantId = $projectKey;
                // Pin il tenant dell'azienda così ogni riga tenant-aware
                // (Project, ProjectMembership) auto-fill il tenant giusto.
                $ctx->set($tenantId);

                // Riga di registry del tenant (label nello switcher team) — solo
                // se la tabella esiste (pacchetto AI-Act migrato).
                if (Schema::hasTable('tenants')) {
                    Tenant::firstOrCreate(['slug' => $tenantId], ['name' => $meta['name']]);
                }

                Project::updateOrCreate(
                    ['tenant_id' => $tenantId, 'project_key' => $projectKey],
                    ['name' => $meta['name'], 'description' => $meta['desc']],
                );

                foreach ($meta['accounts'] as $account) {
                    $this->seedAccount($tenantId, $projectKey, $account);
                }
            }
        } finally {
            $ctx->set($previous);
        }
    }

    /**
     * @param  array{email: string, user: string, role: string}  $account
     */
    private function seedAccount(string $tenantId, string $projectKey, array $account): void
    {
        $user = User::firstOrCreate(
            ['email' => $account['email']],
            ['name' => $account['user'], 'password' => Hash::make(self::PASSWORD)],
        );

        if (! $user->hasRole($account['role'])) {
            $user->assignRole($account['role']);
        }

        // L'account appartiene a UNA sola azienda → membership SOLO nel suo
        // tenant/progetto. Rimuove qualunque altra membership (es. backfill
        // all-projects di RbacSeeder sul tenant default) così, entrando, vede
        // esclusivamente la propria azienda. Va eseguito DOPO RbacSeeder.
        ProjectMembership::query()
            ->where('user_id', $user->id)
            ->where(function ($q) use ($tenantId, $projectKey): void {
                $q->where('tenant_id', '!=', $tenantId)
                    ->orWhere('project_key', '!=', $projectKey);
            })
            ->delete();

        ProjectMembership::firstOrCreate(
            ['tenant_id' => $tenantId, 'user_id' => $user->id, 'project_key' => $projectKey],
            ['role' => 'member', 'scope_allowlist' => null],
        );
    }
}
