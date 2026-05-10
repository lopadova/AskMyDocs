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
 * v4.2/W4 sub-PR 7 — Gate matrix for the Eval Harness UI SPA.
 *
 * Single Gate × five representative roles + anonymous = 6 explicit
 * assertions. The Gate is wired in
 * `AppServiceProvider::registerEvalHarnessUiGates()` and consumed by
 * the package routes through `config/eval-harness-ui.php::route_middleware`
 * (the `can:eval-harness.viewer` entry).
 *
 * Allowlist: super-admin, admin, dpo, editor.
 * Denylist: viewer, anonymous.
 *
 * Why editor is allowed: the eval dashboard is the closing-of-the-loop
 * for canonical compilation work. Editors who edit canonical docs must
 * see eval reports to verify their edits did not regress factuality /
 * accuracy. This is the only admin SPA in the W4 set where editor is
 * granted access (PII Redactor and Flow Admin keep it dpo-and-up).
 *
 * Why viewer is denied: viewer is a content-only role; infrastructure
 * dashboards (eval, PII, flow, KB graph) are out of scope.
 */
class EvalHarnessUiGatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_viewer_gate_allows_super_admin_admin_dpo_editor_and_denies_viewer_anonymous(): void
    {
        $superAdmin = $this->makeUser('super-admin');
        $admin = $this->makeUser('admin');
        $dpo = $this->makeUser('dpo');
        $editor = $this->makeUser('editor');
        $viewer = $this->makeUser('viewer');

        $this->assertTrue(Gate::forUser($superAdmin)->allows('eval-harness.viewer'));
        $this->assertTrue(Gate::forUser($admin)->allows('eval-harness.viewer'));
        $this->assertTrue(Gate::forUser($dpo)->allows('eval-harness.viewer'));
        $this->assertTrue(
            Gate::forUser($editor)->allows('eval-harness.viewer'),
            'Editor must access eval reports to verify canonical edits do not regress factuality.',
        );

        $this->assertFalse(
            Gate::forUser($viewer)->allows('eval-harness.viewer'),
            'Viewer is content-only; infrastructure dashboards are out of scope.',
        );
        $this->assertFalse(
            Gate::allows('eval-harness.viewer'),
            'Anonymous requests must never see eval reports.',
        );
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
