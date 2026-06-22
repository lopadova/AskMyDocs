<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;
use Spatie\Permission\Models\Role;

/**
 * Operator command: create (or refresh) a demo user inside a tenant, in
 * the correct order — tenant first, then the project, then the user, the
 * RBAC role, and finally the membership that makes the tenant show up as
 * a team in the SPA switcher (UserTeamsResolver reads project_memberships).
 *
 * Exists because `tinker` is NOT installed in production (`--no-dev`), so
 * the equivalent REPL snippet cannot run on a deployed box. This is plain
 * app code, always available.
 *
 * Idempotent — safe to re-run:
 *   - Tenant / Project / ProjectMembership via firstOrCreate.
 *   - User via updateOrCreate (re-applies the password every run).
 *   - assignRole() no-ops when the user already has the role.
 *
 *   php artisan demo:seed-user
 *   php artisan demo:seed-user --email=alice@acme.com --tenant=acme --role=editor
 */
class DemoSeedUserCommand extends Command
{
    protected $signature = 'demo:seed-user
        {--email=demo@askmydoc.com : User email}
        {--password=askmypassword : Plain password (stored hashed)}
        {--name=Demo : Display name}
        {--tenant=demo : Tenant slug (a-z0-9_- , max 50)}
        {--project=demo : Project key within the tenant}
        {--role=admin,super-admin : Comma-separated Spatie roles to assign (empty to skip)}';

    protected $description = 'Create/refresh a demo user inside a tenant (tenant → project → user → role → membership).';

    public function handle(TenantContext $tenant): int
    {
        $email = (string) $this->option('email');
        $password = (string) $this->option('password');
        $name = (string) $this->option('name');
        $tenantId = (string) $this->option('tenant');
        $projectKey = (string) $this->option('project');
        $roleNames = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->option('role')),
        )));

        // 0) Active tenant = the target, so every BelongsToTenant auto-fill
        //    (Project, ProjectMembership) lands in the right tenant. set()
        //    also validates the slug format and throws on a bad value.
        try {
            $tenant->set($tenantId);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // 1) TENANT — the package registry row (display label + switcher).
        //    Optional: the table only exists when the AI-Act package is
        //    migrated. Without it the user still works; the team just shows
        //    the humanised slug instead of a stored name.
        if (Schema::hasTable('tenants')) {
            Tenant::firstOrCreate(['slug' => $tenantId], ['name' => $name]);
            $this->line("tenant  : {$tenantId}");
        } else {
            $this->warn("tenant  : 'tenants' table absent (AI-Act package not migrated) — skipped registry row.");
        }

        // 2) PROJECT — soft registry row for the project_key in this tenant.
        Project::firstOrCreate(
            ['tenant_id' => $tenantId, 'project_key' => $projectKey],
            ['name' => $name, 'description' => "{$name} project"],
        );
        $this->line("project : {$projectKey}");

        // 3) USER — cross-tenant identity; the model's 'hashed' cast hashes
        //    the password on assignment.
        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => $password, 'is_active' => true],
        );
        $this->line("user    : {$email} (#{$user->id})");

        // 4) ROLES — Spatie roles on the 'web' guard. Requires RbacSeeder to
        //    have run so the roles (and their permissions) exist. Multiple
        //    roles are additive (assignRole no-ops on duplicates).
        foreach ($roleNames as $roleName) {
            $this->assignRole($user, $roleName);
        }

        // 5) MEMBERSHIP — links user → tenant → project. THIS is what makes
        //    the tenant appear as a selectable team for the user.
        ProjectMembership::firstOrCreate(
            ['tenant_id' => $tenantId, 'user_id' => $user->id, 'project_key' => $projectKey],
            ['role' => 'member', 'scope_allowlist' => null],
        );
        $this->line("member  : {$tenantId}/{$projectKey}");

        $this->info("OK — {$email} ready in tenant '{$tenantId}'.");

        return self::SUCCESS;
    }

    private function assignRole(User $user, string $roleName): void
    {
        $exists = Role::where('name', $roleName)
            ->where('guard_name', 'web')
            ->exists();

        if (! $exists) {
            $this->warn("role    : '{$roleName}' not found on guard 'web' — run `php artisan db:seed --class=RbacSeeder` first. Skipped.");

            return;
        }

        $user->assignRole($roleName);
        $this->line("role    : {$roleName}");
    }
}
