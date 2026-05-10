<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v4.2/W4 sub-PR 5 — Gate matrix for the PII Redactor admin SPA.
 *
 * Three Gates × four representative roles = 12 explicit assertions, plus
 * the anonymous-deny path for each Gate. Each Gate is wired in
 * `AppServiceProvider::registerPiiRedactorAdminGates()` and consumed by
 * the package routes (outer fence) AND by the package controllers
 * (inner fence on detokenise + raw samples).
 */
class PiiRedactorAdminGatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_view_gate_allows_super_admin_dpo_admin_and_denies_editor_viewer_anonymous(): void
    {
        $superAdmin = $this->makeUser('super-admin');
        $dpo = $this->makeUser('dpo');
        $admin = $this->makeUser('admin');
        $editor = $this->makeUser('editor');
        $viewer = $this->makeUser('viewer');

        $this->assertTrue(Gate::forUser($superAdmin)->allows('viewPiiRedactorAdmin'));
        $this->assertTrue(Gate::forUser($dpo)->allows('viewPiiRedactorAdmin'));
        $this->assertTrue(Gate::forUser($admin)->allows('viewPiiRedactorAdmin'));

        $this->assertFalse(Gate::forUser($editor)->allows('viewPiiRedactorAdmin'));
        $this->assertFalse(Gate::forUser($viewer)->allows('viewPiiRedactorAdmin'));
        $this->assertFalse(Gate::allows('viewPiiRedactorAdmin')); // anonymous
    }

    public function test_detokenise_gate_allows_super_admin_dpo_only(): void
    {
        $superAdmin = $this->makeUser('super-admin');
        $dpo = $this->makeUser('dpo');
        $admin = $this->makeUser('admin');
        $editor = $this->makeUser('editor');
        $viewer = $this->makeUser('viewer');

        $this->assertTrue(Gate::forUser($superAdmin)->allows('detokenisePiiRedactor'));
        $this->assertTrue(Gate::forUser($dpo)->allows('detokenisePiiRedactor'));

        // admin can VIEW the console but cannot detokenise — that's the
        // privacy-vs-system-administration boundary the dpo role exists
        // to honour.
        $this->assertFalse(Gate::forUser($admin)->allows('detokenisePiiRedactor'));
        $this->assertFalse(Gate::forUser($editor)->allows('detokenisePiiRedactor'));
        $this->assertFalse(Gate::forUser($viewer)->allows('detokenisePiiRedactor'));
        $this->assertFalse(Gate::allows('detokenisePiiRedactor'));
    }

    public function test_raw_samples_gate_allows_super_admin_only(): void
    {
        $superAdmin = $this->makeUser('super-admin');
        $dpo = $this->makeUser('dpo');
        $admin = $this->makeUser('admin');
        $editor = $this->makeUser('editor');
        $viewer = $this->makeUser('viewer');

        $this->assertTrue(Gate::forUser($superAdmin)->allows('viewPiiRedactorRawSamples'));

        // Even the DPO does not see raw scan samples — the hash-only
        // audit trail is the only evidence they ever need.
        $this->assertFalse(Gate::forUser($dpo)->allows('viewPiiRedactorRawSamples'));
        $this->assertFalse(Gate::forUser($admin)->allows('viewPiiRedactorRawSamples'));
        $this->assertFalse(Gate::forUser($editor)->allows('viewPiiRedactorRawSamples'));
        $this->assertFalse(Gate::forUser($viewer)->allows('viewPiiRedactorRawSamples'));
        $this->assertFalse(Gate::allows('viewPiiRedactorRawSamples'));
    }

    public function test_dpo_role_carries_pii_detokenize_permission(): void
    {
        // The Gate uses `hasAnyRole`, but downstream tooling that
        // prefers `$user->can()` (e.g. policies, future export
        // endpoints) must also see the dpo role granted the
        // permission. RbacSeeder syncs it explicitly in v4.2/W4.
        $dpo = $this->makeUser('dpo');

        $this->assertTrue($dpo->can('pii.detokenize'));
    }

    private function makeUser(string $role): User
    {
        $user = User::create([
            'name' => "Test {$role}",
            // Unique email per role so RefreshDatabase + sequential
            // role iteration doesn't trip the users.email UNIQUE.
            'email' => $role.'-'.uniqid().'@example.test',
            'password' => Hash::make('secret-password'),
        ]);

        $user->assignRole($role);

        return $user->fresh();
    }
}
