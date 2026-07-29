<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Auth\UserTeamsResolver;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;
use Tests\TestCase;

final class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('kb.sources.disk', 'kb'));
    }

    public function test_it_seeds_only_operational_company_memberships(): void
    {
        $this->seed(DemoSeeder::class);

        $admin = User::query()->where('email', 'admin@demo.local')->sole();
        $viewer = User::query()->where('email', 'viewer@demo.local')->sole();
        $system = User::query()->where('email', 'system@demo.local')->sole();

        $this->assertDatabaseMissing('tenants', ['slug' => 'default']);
        $this->assertDatabaseHas('tenants', [
            'slug' => DemoSeeder::PRIMARY_TENANT,
            'status' => 'active',
            'is_system' => false,
        ]);
        $this->assertDatabaseHas('tenants', [
            'slug' => 'acme',
            'status' => 'active',
        ]);

        $this->assertSame(
            [DemoSeeder::PRIMARY_TENANT, 'acme'],
            array_column(app(UserTeamsResolver::class)->resolve($admin), 'tenant_id'),
        );
        $this->assertSame(
            [DemoSeeder::PRIMARY_TENANT],
            array_column(app(UserTeamsResolver::class)->resolve($viewer), 'tenant_id'),
        );
        $this->assertSame([], app(UserTeamsResolver::class)->resolve($system));

        $this->assertSame(
            0,
            ProjectMembership::query()->forTenant('default')->count(),
        );
        $this->assertSame(
            3,
            KnowledgeDocument::query()->forTenant(DemoSeeder::PRIMARY_TENANT)->count(),
        );
        $this->assertSame(
            0,
            KnowledgeDocument::query()->forTenant('default')->count(),
        );
    }

    public function test_it_is_idempotent_without_materialising_default(): void
    {
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->assertSame(
            2,
            Tenant::query()
                ->whereIn('slug', [DemoSeeder::PRIMARY_TENANT, 'acme'])
                ->count(),
        );
        $this->assertDatabaseMissing('tenants', ['slug' => 'default']);
        $this->assertSame(
            5,
            KnowledgeDocument::query()
                ->whereIn('tenant_id', [DemoSeeder::PRIMARY_TENANT, 'acme'])
                ->count(),
        );
    }
}
