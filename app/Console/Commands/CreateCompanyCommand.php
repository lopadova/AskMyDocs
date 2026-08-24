<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Admin\TenantProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Operator bootstrap: create a NEW company (tenant) and its admin user in one
 * non-interactive command — company name + admin email + password, all inline.
 *
 * Mirrors the proven ordering of {@see DemoSeedUserCommand}
 * (tenant → project → user → role → membership; the membership is what makes
 * the tenant show up as a team in the SPA switcher, read by UserTeamsResolver),
 * but for REAL companies rather than the demo user:
 *   - the company name is a distinct input from the admin's display name;
 *   - "create new" semantics — it FAILS if the tenant slug or the email already
 *     exists, instead of silently updating (no accidental password reset, no
 *     merge into an existing company);
 *   - the writes run inside a DB transaction, so a mid-failure never leaves a
 *     half-created company.
 *
 * The write core is shared with the authenticated super-admin HTTP control
 * plane. This command remains intentionally absent from the generic admin
 * command-runner allowlist (config/admin.php): unauthenticated bootstrap is an
 * operator concern, while browser provisioning is protected by its dedicated
 * `role:super-admin` route.
 *
 *   php artisan company:create --company="Acme Corp" --email=admin@acme.com --password=secret123
 *
 * To avoid the password appearing in the process list as an argv flag, prefer
 * the COMPANY_ADMIN_PASSWORD environment variable instead (avoid typing secrets if your shell persists history):
 *   COMPANY_ADMIN_PASSWORD=secret123 php artisan company:create --company="Acme Corp" --email=admin@acme.com
 */
class CreateCompanyCommand extends Command
{
    protected $signature = 'company:create
        {--company= : Company display name (required), e.g. "Acme Corp"}
        {--email= : Admin email (required)}
        {--password= : Admin password (min 8); prefer the COMPANY_ADMIN_PASSWORD env var to avoid argv exposure in the process list}
        {--slug= : Tenant slug (default: slug of --company; a-z0-9_- , max 50)}
        {--name= : Admin display name (default: the part before @ in --email)}
        {--project= : Initial project key (default: the tenant slug)}
        {--role=admin : Spatie role for the admin on the web guard}';

    protected $description = 'Create a new company (tenant) + its admin user (tenant → project → user → role → membership), non-interactive.';

    public function handle(TenantProvisioningService $provisioning): int
    {
        $company = trim((string) $this->option('company'));
        $email = trim((string) $this->option('email'));
        // Support a non-argv path (env var) so the password isn't passed as an argv flag (process list).
        // Precedence: COMPANY_ADMIN_PASSWORD env var > --password option.
        $password = (string) (env('COMPANY_ADMIN_PASSWORD') ?: $this->option('password'));
        $role = trim((string) $this->option('role')) ?: 'admin';

        // 1) Required inputs.
        if ($company === '' || $email === '' || $password === '') {
            $this->error('--company and --email are required, and you must provide an admin password via --password or COMPANY_ADMIN_PASSWORD.');

            return self::FAILURE;
        }

        // 2) Slug: explicit --slug or derived from the company name. Lower-cased
        //    and validated against the same shape TenantContext enforces, so a
        //    bad value fails here with a clear message rather than mid-write.
        $slug = Str::lower(trim((string) $this->option('slug')) ?: Str::slug($company));
        if (! preg_match('/^[a-z0-9_-]{1,50}$/', $slug)) {
            $this->error("Invalid tenant slug '{$slug}' — must match /^[a-z0-9_-]{1,50}$/. Pass an explicit --slug.");

            return self::FAILURE;
        }

        // 3) Email + password format.
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid email format: {$email}");

            return self::FAILURE;
        }
        if (mb_strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');

            return self::FAILURE;
        }

        // 4) Role must exist on the 'web' guard (seeded by RbacSeeder). User
        //    pins HasRoles to 'web', so the lookup must match that guard.
        $roleExists = Role::query()
            ->where('name', $role)
            ->where('guard_name', 'web')
            ->exists();
        if (! $roleExists) {
            $this->error("Role '{$role}' not found on guard 'web' — run `php artisan db:seed --class=RbacSeeder` first.");

            return self::FAILURE;
        }

        $name = trim((string) $this->option('name')) ?: Str::before($email, '@');
        $projectKey = trim((string) $this->option('project')) ?: $slug;
        if ($projectKey === '' || mb_strlen($projectKey) > 120) {
            $this->error('Invalid project key — must be a non-empty string up to 120 characters.');

            return self::FAILURE;
        }
        try {
            $result = $provisioning->provision([
                'tenant_name' => $company,
                'tenant_slug' => $slug,
                'user_email' => $email,
                'user_name' => $name,
                'password' => $password,
                'role' => $role,
                'attach_existing' => false,
                'project_key' => $projectKey,
                // Preserve the historical CLI membership contract.
                'membership_role' => 'member',
            ], allowedRoles: null, requireRegistry: false);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $errors = $e->errors();
            if (isset($errors['tenant_slug']) || isset($errors['slug'])) {
                $this->error("Company '{$slug}' already exists.");
            } elseif (isset($errors['user_email']) || isset($errors['email'])) {
                $this->error("Email already in use: {$email}");
            } else {
                $this->error(collect($errors)->flatten()->first() ?? 'Invalid company details.');
            }

            return self::FAILURE;
        } catch (\Throwable $e) {
            report($e);
            $this->error('Failed to create company due to an unexpected database error.');

            return self::FAILURE;
        }

        if (! $result['registry_created']) {
            $this->warn("'tenants' table absent (AI-Act package not migrated) — created the user + membership; the switcher will show the humanised slug.");
        }

        $user = $result['user'];
        $this->info("Company '{$company}' created.");
        $this->table(['Field', 'Value'], [
            ['Company', $company],
            ['Tenant slug', $slug],
            ['Project', $projectKey],
            ['Admin', "{$name} <{$email}> (#{$user['id']})"],
            ['Role', $role],
        ]);

        return self::SUCCESS;
    }
}
