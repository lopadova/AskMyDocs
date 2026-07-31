<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Deterministic fixtures for the Laravel Cloud environment tracking develop.
 *
 * This seeder is intentionally unavailable in production. It creates two
 * operational companies with separate identities and memberships so tenant
 * isolation, project scoping and the role gradient can be exercised manually.
 */
final class DevelopSeeder extends Seeder
{
    public const SYSTEM_EMAIL = 'system@develop.test';

    /**
     * @var array<string, array{name: string, email_domain: string, project_prefix: string}>
     */
    private const TENANTS = [
        'develop-acme' => [
            'name' => 'Acme Development',
            'email_domain' => 'acme.develop.test',
            'project_prefix' => 'acme',
        ],
        'develop-globex' => [
            'name' => 'Globex Development',
            'email_domain' => 'globex.develop.test',
            'project_prefix' => 'globex',
        ],
    ];

    /**
     * @var array<string, array{role: string, membership_role: string, all_projects: bool}>
     */
    private const IDENTITIES = [
        'owner' => [
            'role' => 'super-admin',
            'membership_role' => 'owner',
            'all_projects' => true,
        ],
        'admin' => [
            'role' => 'admin',
            'membership_role' => 'admin',
            'all_projects' => true,
        ],
        'viewer' => [
            'role' => 'viewer',
            'membership_role' => 'member',
            'all_projects' => false,
        ],
    ];

    public function run(): void
    {
        $this->assertAllowed();
        $password = $this->seedPassword();

        $this->call([
            RbacSeeder::class,
            SystemTenantSeeder::class,
        ]);

        $system = $this->upsertIdentity(
            self::SYSTEM_EMAIL,
            'Develop System Administrator',
            $password,
        );
        $system->syncRoles(['system-admin', 'super-admin']);

        foreach (self::TENANTS as $tenantId => $definition) {
            $this->seedTenant($tenantId, $definition, $password);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function assertAllowed(): void
    {
        $environment = (string) app()->environment();
        $allowedEnvironments = (array) config('develop-deploy.allowed_environments', []);

        if (
            ! (bool) config('develop-deploy.enabled', false)
            || ! in_array($environment, $allowedEnvironments, true)
        ) {
            throw new LogicException(sprintf(
                'DevelopSeeder is disabled for APP_ENV=%s. Enable DEVELOP_DEPLOY_ENABLED only in the develop test environment.',
                $environment,
            ));
        }
    }

    private function seedPassword(): string
    {
        $password = (string) config('develop-deploy.seed.password', '');

        if (mb_strlen($password) < 12) {
            throw new LogicException(
                'DEVELOP_SEED_PASSWORD must contain at least 12 characters before DevelopSeeder can run.',
            );
        }

        return $password;
    }

    /**
     * @param  array{name: string, email_domain: string, project_prefix: string}  $definition
     */
    private function seedTenant(string $tenantId, array $definition, string $password): void
    {
        $existingTenant = Tenant::query()->where('slug', $tenantId)->first();
        if ($existingTenant !== null && (bool) $existingTenant->is_system) {
            throw new LogicException("Refusing to convert system tenant [{$tenantId}] into a develop fixture.");
        }

        Tenant::query()->updateOrCreate(
            ['slug' => $tenantId],
            [
                'name' => $definition['name'],
                'status' => 'active',
                'is_system' => false,
            ],
        );

        $projectKeys = [
            $definition['project_prefix'].'-kb',
            $definition['project_prefix'].'-operations',
        ];

        $context = app(TenantContext::class);
        $previousTenant = $context->current();
        $context->set($tenantId);

        try {
            foreach ($projectKeys as $projectKey) {
                Project::query()
                    ->forTenant($tenantId)
                    ->updateOrCreate(
                        ['project_key' => $projectKey],
                        [
                            'tenant_id' => $tenantId,
                            'name' => str_ends_with($projectKey, '-kb')
                                ? $definition['name'].' Knowledge Base'
                                : $definition['name'].' Operations',
                            'description' => 'Deterministic fixture for the Laravel Cloud develop environment.',
                        ],
                    );
            }

            foreach (self::IDENTITIES as $identity => $access) {
                $user = $this->upsertIdentity(
                    $identity.'@'.$definition['email_domain'],
                    $definition['name'].' '.ucfirst($identity),
                    $password,
                );
                $user->syncRoles([$access['role']]);

                $assignedProjects = $access['all_projects']
                    ? $projectKeys
                    : [$projectKeys[0]];

                foreach ($assignedProjects as $projectKey) {
                    ProjectMembership::query()
                        ->forTenant($tenantId)
                        ->updateOrCreate(
                            [
                                'user_id' => $user->id,
                                'project_key' => $projectKey,
                            ],
                            [
                                'tenant_id' => $tenantId,
                                'role' => $access['membership_role'],
                                'scope_allowlist' => null,
                            ],
                        );
                }
            }
        } finally {
            $context->set($previousTenant);
        }
    }

    private function upsertIdentity(string $email, string $name, string $password): User
    {
        $user = User::query()
            ->withTrashed()
            ->whereEmailIdentity($email)
            ->first();

        if ($user === null) {
            $user = new User;
            $user->email = $email;
        } elseif ($user->trashed() && ! $user->restore()) {
            throw new RuntimeException("Unable to restore develop identity [{$email}].");
        }

        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'is_active' => true,
            'email_verified_at' => now(),
            'registration_completed_at' => now(),
        ]);

        if (! $user->save()) {
            throw new RuntimeException("Unable to persist develop identity [{$email}].");
        }

        return $user;
    }
}
