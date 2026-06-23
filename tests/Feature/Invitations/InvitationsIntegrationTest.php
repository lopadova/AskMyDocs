<?php

declare(strict_types=1);

namespace Tests\Feature\Invitations;

use App\Invitations\ProjectMembershipProvisioner;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\Invitations\Contracts\InvitedAccount;
use Padosoft\Invitations\Contracts\Provisioner;
use Padosoft\Invitations\Contracts\TenantResolver;
use Padosoft\Invitations\Provisioning\SpatiePermissionProvisioner;
use Padosoft\Invitations\Services\AccountProvisioningService;
use Padosoft\Invitations\Support\TenantGrant;
use Tests\TestCase;

/**
 * Host-integration coverage for padosoft/laravel-invitations. The package's own
 * engine (atomic redemption, anti-abuse, code generation) is covered by its own
 * CI; these tests assert the AskMyDocs WIRING: the seam bindings, the
 * provisioner contract, and the R43 OFF-by-default signup gate.
 */
final class InvitationsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_model_satisfies_the_invited_account_contract(): void
    {
        $user = User::create([
            'name' => 'Invitee',
            'email' => 'invitee@demo.local',
            'password' => 'secret-password',
        ]);

        $this->assertInstanceOf(InvitedAccount::class, $user);
        $this->assertSame('invitee@demo.local', $user->getInviteEmail());
        // The model pins HasRoles to the `web` guard; the invite engine must
        // grant roles against the same guard RbacSeeder seeds.
        $this->assertSame('web', $user->getInviteGuardName());
    }

    public function test_tenant_resolver_is_bound_to_the_host_tenant_context(): void
    {
        app(TenantContext::class)->set('acme');

        $resolver = app(TenantResolver::class);

        $this->assertSame('acme', $resolver->current());
    }

    public function test_provisioner_tag_includes_both_the_package_default_and_the_host_provisioner(): void
    {
        $provisioners = iterator_to_array(app()->tagged('invitations.provisioners'));

        $classes = array_map(static fn (Provisioner $p): string => $p::class, $provisioners);

        $this->assertContains(SpatiePermissionProvisioner::class, $classes);
        $this->assertContains(ProjectMembershipProvisioner::class, $classes);
    }

    public function test_project_membership_provisioner_grants_membership_in_the_grant_tenant(): void
    {
        // CROSS-TENANT: the active request tenant is deliberately DIFFERENT from
        // the grant's tenant — one code can provision across several tenants, so
        // the rows must land in the GRANT's tenant ('acme'), never the active
        // one ('other'). This proves the withoutGlobalScopes() lookup + the
        // explicit tenant_id INSERT, not an accident of the active context.
        app(TenantContext::class)->set('other');

        $user = User::create([
            'name' => 'Invitee',
            'email' => 'grant@demo.local',
            'password' => 'secret-password',
        ]);

        $grant = new TenantGrant(
            tenantId: 'acme',
            role: 'editor',
            projects: ['hr-portal', 'engineering'],
            projectRole: 'member',
            scopeAllowlist: ['folder_globs' => ['hr/*']],
        );

        app(ProjectMembershipProvisioner::class)->provision($user, $grant);

        $this->assertDatabaseHas('project_memberships', [
            'tenant_id' => 'acme',
            'user_id' => $user->getKey(),
            'project_key' => 'hr-portal',
            'role' => 'member',
        ]);
        $this->assertDatabaseHas('project_memberships', [
            'tenant_id' => 'acme',
            'user_id' => $user->getKey(),
            'project_key' => 'engineering',
            // Assert the projectRole landed (not just the row's presence) so a
            // wrong default role on the second project would fail the test (R16).
            'role' => 'member',
        ]);
        // And NOTHING leaked into the active tenant 'other'.
        $this->assertDatabaseMissing('project_memberships', [
            'tenant_id' => 'other',
            'user_id' => $user->getKey(),
        ]);
    }

    public function test_project_membership_provisioner_is_grant_never_revoke(): void
    {
        $user = User::create([
            'name' => 'Owner',
            'email' => 'owner@demo.local',
            'password' => 'secret-password',
        ]);

        // Pre-existing membership at a higher role than the grant carries.
        ProjectMembership::create([
            'tenant_id' => 'acme',
            'user_id' => $user->getKey(),
            'project_key' => 'hr-portal',
            'role' => 'owner',
        ]);

        $grant = new TenantGrant(
            tenantId: 'acme',
            projects: ['hr-portal'],
            projectRole: 'member',
        );

        app(ProjectMembershipProvisioner::class)->provision($user, $grant);

        // The existing 'owner' row is NEVER downgraded to 'member', and no
        // duplicate row is created (firstOrCreate on the natural key). The
        // tenant_id is asserted so the row can't match another tenant's (R30).
        $this->assertDatabaseHas('project_memberships', [
            'tenant_id' => 'acme',
            'user_id' => $user->getKey(),
            'project_key' => 'hr-portal',
            'role' => 'owner',
        ]);
        $this->assertSame(
            1,
            ProjectMembership::query()
                ->where('tenant_id', 'acme')
                ->where('user_id', $user->getKey())
                ->where('project_key', 'hr-portal')
                ->count(),
        );
    }

    public function test_provisioning_service_resolves_with_the_full_tagged_provisioner_set(): void
    {
        // The package wires AccountProvisioningService with
        // ->giveTagged('invitations.provisioners'); assert the host's extra
        // provisioner is actually DELIVERED to the resolved service (not just
        // present in the tag) — i.e. the host boot()-time tag addition is
        // visible to the package's contextual binding at resolution time.
        $service = app(AccountProvisioningService::class);
        $this->assertInstanceOf(AccountProvisioningService::class, $service);

        $property = (new \ReflectionClass($service))->getProperty('provisioners');
        $property->setAccessible(true);
        $resolved = $property->getValue($service);

        $classes = array_map(
            static fn (Provisioner $p): string => $p::class,
            is_array($resolved) ? $resolved : iterator_to_array($resolved),
        );

        $this->assertContains(SpatiePermissionProvisioner::class, $classes);
        $this->assertContains(ProjectMembershipProvisioner::class, $classes);
    }

    public function test_invitation_required_signup_gate_defaults_off(): void
    {
        // R43 OFF path — existing registration is unchanged unless an operator
        // opts into the closed-beta posture via INVITE_REQUIRED=true.
        $this->assertFalse((bool) config('invitations.invitation_required'));
    }

    public function test_invitation_required_gate_resolves_cleanly_in_both_states(): void
    {
        // R43 BOTH-states. NOTE: AskMyDocs has NO public self-registration route
        // — accounts are created by an admin via /api/admin/users — so there is
        // no host signup flow for this flag to gate, and the package itself does
        // not consume `invitation_required` (it is a host-enforced knob). The
        // meaningful both-states guarantee here is therefore that the flag is
        // readable + togglable in BOTH OFF and ON without breaking the wiring,
        // and that toggling it never affects the redeem/admin surfaces (which
        // are gated by auth + RBAC, not by this flag). Should AskMyDocs add a
        // public register flow later, that flow's gate gets its own both-states
        // test at that layer.
        config(['invitations.invitation_required' => false]);
        $this->assertFalse((bool) config('invitations.invitation_required'));
        // Admin surface still requires the gate regardless of the signup flag.
        $this->assertSame(
            401,
            $this->getJson('/api/admin/invitations/metrics')->getStatusCode(),
        );

        config(['invitations.invitation_required' => true]);
        $this->assertTrue((bool) config('invitations.invitation_required'));
        // Flipping the closed-beta flag ON changes nothing about the admin gate
        // (it is auth + can:manageInvitations, never the signup flag) — a guest
        // is still 401, not a 500 / open door.
        $this->assertSame(
            401,
            $this->getJson('/api/admin/invitations/metrics')->getStatusCode(),
        );
    }
}
