<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Models\HiddenWorkflow;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowShare;
use App\Services\Workflow\WorkflowService;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * v4.7/W2 — WorkflowService behaviour tests.
 *
 * Drives the service directly (no HTTP) so we cover the access control
 * + tenant isolation + idempotency rules without the FormRequest +
 * Gate noise.
 */
final class WorkflowServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Copilot iter 9: flush the cache before seeding so Spatie's
        // permission cache from a previous RefreshDatabase rollback
        // does not survive into this suite under Testbench.
        Cache::flush();
        // Copilot iter 12: reset the TenantContext singleton so an
        // earlier test that switched tenants (the cross-tenant test
        // below sets `acme`) cannot leak into a later test that
        // relies on `default`.
        app(TenantContext::class)->set('default');
        $this->seed(RbacSeeder::class);
    }

    public function test_list_returns_own_shared_system_minus_hidden(): void
    {
        $owner = $this->makeUser('admin', 'owner@example.com');
        $other = $this->makeUser('admin', 'other@example.com');

        $own = $this->makeWorkflow($owner, ['title' => 'Mine']);
        $shared = $this->makeWorkflow($other, ['title' => 'Shared with me']);
        WorkflowShare::create([
            'workflow_id' => $shared->id,
            'shared_by_user_id' => $other->id,
            'shared_with_email' => 'owner@example.com',
            'allow_edit' => false,
        ]);

        $system = $this->makeWorkflow(null, [
            'title' => 'System template',
            'is_system' => true,
            'user_id' => null,
        ]);

        $hidden = $this->makeWorkflow($other, ['title' => 'Hidden one']);
        WorkflowShare::create([
            'workflow_id' => $hidden->id,
            'shared_by_user_id' => $other->id,
            'shared_with_email' => 'owner@example.com',
            'allow_edit' => false,
        ]);
        // owner hides it
        HiddenWorkflow::create([
            'tenant_id' => 'default',
            'user_id' => $owner->id,
            'workflow_id' => $hidden->id,
            'hidden_at' => now(),
        ]);

        $unrelated = $this->makeWorkflow($other, ['title' => 'Not shared']);

        $service = app(WorkflowService::class);
        $listed = $service->list($owner)->pluck('title')->all();

        $this->assertContains('Mine', $listed);
        $this->assertContains('Shared with me', $listed);
        $this->assertContains('System template', $listed);
        $this->assertNotContains('Hidden one', $listed);
        $this->assertNotContains('Not shared', $listed);
        $this->assertSame(3, count($listed));
    }

    public function test_list_includes_hidden_when_flag_is_true(): void
    {
        $user = $this->makeUser('admin', 'u@example.com');
        $other = $this->makeUser('admin', 'o@example.com');

        $w = $this->makeWorkflow($other, ['title' => 'Shared+Hidden']);
        WorkflowShare::create([
            'workflow_id' => $w->id,
            'shared_by_user_id' => $other->id,
            'shared_with_email' => 'u@example.com',
            'allow_edit' => false,
        ]);
        HiddenWorkflow::create([
            'tenant_id' => 'default',
            'user_id' => $user->id,
            'workflow_id' => $w->id,
            'hidden_at' => now(),
        ]);

        $service = app(WorkflowService::class);
        $listed = $service->list($user, null, true, true)->pluck('title')->all();

        $this->assertContains('Shared+Hidden', $listed);
    }

    public function test_list_filters_by_type(): void
    {
        $user = $this->makeUser('admin', 't@example.com');
        $this->makeWorkflow($user, ['title' => 'A', 'type' => 'assistant']);
        $this->makeWorkflow($user, ['title' => 'B', 'type' => 'tabular']);

        $service = app(WorkflowService::class);
        $listed = $service->list($user, 'tabular')->pluck('title')->all();

        $this->assertSame(['B'], $listed);
    }

    public function test_list_isolates_tenants(): void
    {
        $user = $this->makeUser('admin', 'crosstenant@example.com');

        // Default tenant — owned by user
        app(TenantContext::class)->set('default');
        $this->makeWorkflow($user, ['title' => 'Default tenant']);

        // Switch tenant
        app(TenantContext::class)->set('acme');
        $this->makeWorkflow($user, ['title' => 'Acme tenant', 'tenant_id' => 'acme']);

        $service = app(WorkflowService::class);
        $listed = $service->list($user)->pluck('title')->all();

        $this->assertSame(['Acme tenant'], $listed);

        app(TenantContext::class)->set('default');
        $listed = $service->list($user)->pluck('title')->all();
        $this->assertSame(['Default tenant'], $listed);
    }

    public function test_share_is_idempotent(): void
    {
        $owner = $this->makeUser('admin', 'idem-owner@example.com');
        $w = $this->makeWorkflow($owner);

        $service = app(WorkflowService::class);
        $first = $service->share($w, $owner, 'rec@example.com', false);
        $second = $service->share($w, $owner, 'rec@example.com', true);

        $this->assertSame($first->id, $second->id);
        $this->assertTrue($second->fresh()->allow_edit);
        $this->assertSame(1, WorkflowShare::where('workflow_id', $w->id)->count());
    }

    public function test_system_workflow_cannot_be_deleted(): void
    {
        $admin = $this->makeUser('admin', 'a@example.com');
        $w = $this->makeWorkflow(null, [
            'title' => 'Sys',
            'is_system' => true,
            'user_id' => null,
        ]);

        $this->expectException(AccessDeniedHttpException::class);
        app(WorkflowService::class)->delete($w, $admin);
    }

    public function test_non_owner_cannot_update(): void
    {
        $owner = $this->makeUser('admin', 'owner-x@example.com');
        $other = $this->makeUser('admin', 'thief@example.com');
        $w = $this->makeWorkflow($owner);

        $this->expectException(AccessDeniedHttpException::class);
        app(WorkflowService::class)->update($w, $other, ['title' => 'Hijacked']);
    }

    public function test_shared_with_allow_edit_can_update(): void
    {
        $owner = $this->makeUser('admin', 'o2@example.com');
        $collab = $this->makeUser('admin', 'collab@example.com');
        $w = $this->makeWorkflow($owner);
        WorkflowShare::create([
            'workflow_id' => $w->id,
            'shared_by_user_id' => $owner->id,
            'shared_with_email' => 'collab@example.com',
            'allow_edit' => true,
        ]);

        $updated = app(WorkflowService::class)->update($w, $collab, ['title' => 'Co-edited']);
        $this->assertSame('Co-edited', $updated->fresh()->title);
    }

    public function test_assert_same_tenant_404s_cross_tenant_workflow(): void
    {
        app(TenantContext::class)->set('acme');
        $owner = $this->makeUser('admin', 'tenant-test@example.com');
        $w = $this->makeWorkflow($owner, ['tenant_id' => 'acme']);

        app(TenantContext::class)->set('default');
        $this->expectException(NotFoundHttpException::class);
        app(WorkflowService::class)->update($w, $owner, ['title' => 'X']);
    }

    public function test_hide_and_unhide_are_idempotent(): void
    {
        $user = $this->makeUser('admin', 'hide@example.com');
        $other = $this->makeUser('admin', 'other2@example.com');
        $w = $this->makeWorkflow($other);

        $service = app(WorkflowService::class);
        $row1 = $service->hide($w, $user);
        $row2 = $service->hide($w, $user);
        $this->assertSame($row1->id, $row2->id);

        $removed1 = $service->unhide($w, $user);
        $removed2 = $service->unhide($w, $user);
        $this->assertTrue($removed1);
        $this->assertFalse($removed2);
    }

    private function makeUser(string $role, string $email): User
    {
        $u = User::create([
            'name' => 'U',
            'email' => $email,
            'password' => Hash::make('secret'),
        ]);
        $u->assignRole($role);
        return $u;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeWorkflow(?User $owner, array $overrides = []): Workflow
    {
        $tenant = $overrides['tenant_id'] ?? app(TenantContext::class)->current();

        $attrs = array_merge([
            'tenant_id' => $tenant,
            'user_id' => $owner?->id,
            'title' => 'T-'.uniqid(),
            'type' => 'assistant',
            'prompt_md' => 'do something',
            'practice' => 'generic',
            'is_system' => false,
        ], $overrides);

        return Workflow::create($attrs);
    }
}
