<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AiActComplianceGatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_view_gate_allows_super_admin_admin_dpo_and_denies_editor_viewer_anonymous(): void
    {
        $superAdmin = $this->makeUser('super-admin');
        $admin = $this->makeUser('admin');
        $dpo = $this->makeUser('dpo');
        $editor = $this->makeUser('editor');
        $viewer = $this->makeUser('viewer');

        $this->assertTrue(Gate::forUser($superAdmin)->allows('viewAiActCompliance'));
        $this->assertTrue(Gate::forUser($admin)->allows('viewAiActCompliance'));
        $this->assertTrue(Gate::forUser($dpo)->allows('viewAiActCompliance'));
        $this->assertFalse(Gate::forUser($editor)->allows('viewAiActCompliance'));
        $this->assertFalse(Gate::forUser($viewer)->allows('viewAiActCompliance'));
        $this->assertFalse(Gate::allows('viewAiActCompliance'));
    }

    public function test_manage_gate_allows_super_admin_and_dpo_only(): void
    {
        $superAdmin = $this->makeUser('super-admin');
        $admin = $this->makeUser('admin');
        $dpo = $this->makeUser('dpo');
        $editor = $this->makeUser('editor');
        $viewer = $this->makeUser('viewer');

        $this->assertTrue(Gate::forUser($superAdmin)->allows('manageAiActCompliance'));
        $this->assertTrue(Gate::forUser($dpo)->allows('manageAiActCompliance'));
        $this->assertFalse(Gate::forUser($admin)->allows('manageAiActCompliance'));
        $this->assertFalse(Gate::forUser($editor)->allows('manageAiActCompliance'));
        $this->assertFalse(Gate::forUser($viewer)->allows('manageAiActCompliance'));
        $this->assertFalse(Gate::allows('manageAiActCompliance'));
    }

    private function makeUser(string $role): User
    {
        $user = User::create([
            'name' => "Test {$role}",
            'email' => $role.'-'.uniqid().'@example.test',
            'password' => Hash::make('secret-password'),
        ]);

        $user->assignRole($role);

        return $user->fresh();
    }
}
