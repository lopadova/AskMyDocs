<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class MaterializeDefaultTenantMembershipsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_default_access_becomes_explicit_idempotent_and_audited(): void
    {
        Project::create([
            'tenant_id' => 'default',
            'project_key' => 'legacy-project',
            'name' => 'Legacy project',
        ]);
        $user = User::create([
            'name' => 'Legacy user',
            'email' => 'legacy-default@example.test',
            'password' => Hash::make('secret-password'),
        ]);

        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_07_29_000001_materialize_default_tenant_memberships.php';
        $migration->up();
        $migration->up();

        $this->assertDatabaseCount('project_memberships', 1);
        $this->assertDatabaseHas('project_memberships', [
            'tenant_id' => 'default',
            'user_id' => $user->id,
            'project_key' => 'legacy-project',
            'role' => 'member',
        ]);
        $this->assertDatabaseHas('admin_command_audit', [
            'command' => 'tenant:materialize-default-memberships',
            'status' => 'completed',
            'tenant_id' => 'default',
        ]);
        $this->assertSame(
            1,
            DB::table('admin_command_audit')
                ->where('command', 'tenant:materialize-default-memberships')
                ->count(),
        );
    }
}
