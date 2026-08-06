<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Auth\UserTeamsResolver;
use Database\Seeders\DevelopSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;
use Tests\TestCase;

final class DevelopSeederTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'DevelopOnly!2026';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('develop-deploy.enabled', true);
        config()->set('develop-deploy.environment', null);
        config()->set('develop-deploy.allowed_environments', ['testing']);
        config()->set('develop-deploy.seed.password', self::PASSWORD);
    }

    public function test_it_seeds_two_isolated_companies_with_three_users_each(): void
    {
        $this->seed(DevelopSeeder::class);

        foreach ([
            'develop-acme' => 'acme.develop.test',
            'develop-globex' => 'globex.develop.test',
        ] as $tenantId => $emailDomain) {
            $this->assertDatabaseHas('tenants', [
                'slug' => $tenantId,
                'status' => 'active',
                'is_system' => false,
            ]);
            $this->assertSame(2, Project::query()->forTenant($tenantId)->count());
            $this->assertSame(
                3,
                ProjectMembership::query()
                    ->forTenant($tenantId)
                    ->distinct()
                    ->count('user_id'),
            );
            $this->assertSame(5, ProjectMembership::query()->forTenant($tenantId)->count());

            foreach ([
                'owner' => 'super-admin',
                'admin' => 'admin',
                'viewer' => 'viewer',
            ] as $identity => $role) {
                $user = User::query()
                    ->where('email', "{$identity}@{$emailDomain}")
                    ->sole();

                $this->assertSame([$role], $user->getRoleNames()->all());
                $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
                $this->assertNotNull($user->email_verified_at);
                $this->assertNotNull($user->registration_completed_at);
                $this->assertSame(
                    [$tenantId],
                    array_column(app(UserTeamsResolver::class)->resolve($user), 'tenant_id'),
                );
            }
        }

        $system = User::query()->where('email', DevelopSeeder::SYSTEM_EMAIL)->sole();
        $this->assertEqualsCanonicalizing(
            ['system-admin', 'super-admin'],
            $system->getRoleNames()->all(),
        );
        $this->assertSame([], app(UserTeamsResolver::class)->resolve($system));
    }

    public function test_it_is_idempotent(): void
    {
        $this->seed(DevelopSeeder::class);
        $this->seed(DevelopSeeder::class);

        $this->assertSame(
            2,
            Tenant::query()
                ->whereIn('slug', ['develop-acme', 'develop-globex'])
                ->count(),
        );
        $this->assertSame(
            7,
            User::query()
                ->where(function ($query): void {
                    $query
                        ->where('email', DevelopSeeder::SYSTEM_EMAIL)
                        ->orWhere('email', 'like', '%@acme.develop.test')
                        ->orWhere('email', 'like', '%@globex.develop.test');
                })
                ->count(),
        );
        $this->assertSame(
            4,
            Project::query()
                ->whereIn('tenant_id', ['develop-acme', 'develop-globex'])
                ->count(),
        );
        $this->assertSame(
            10,
            ProjectMembership::query()
                ->whereIn('tenant_id', ['develop-acme', 'develop-globex'])
                ->count(),
        );
    }

    public function test_it_refuses_to_run_without_the_explicit_develop_gate(): void
    {
        config()->set('develop-deploy.enabled', false);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('DevelopSeeder is disabled');

        $this->seed(DevelopSeeder::class);
    }

    public function test_it_refuses_a_missing_or_short_password(): void
    {
        config()->set('develop-deploy.seed.password', 'short');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('at least 12 characters');

        $this->seed(DevelopSeeder::class);
    }

    public function test_it_accepts_an_explicit_develop_environment_when_app_env_is_production(): void
    {
        app()->detectEnvironment(static fn (): string => 'production');
        config()->set('develop-deploy.environment', 'develop');
        config()->set('develop-deploy.allowed_environments', ['develop']);

        app(DevelopSeeder::class)->run();

        $this->assertSame(
            7,
            User::query()
                ->where(function ($query): void {
                    $query
                        ->where('email', DevelopSeeder::SYSTEM_EMAIL)
                        ->orWhere('email', 'like', '%.develop.test');
                })
                ->count(),
        );
    }
}
