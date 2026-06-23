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
        // duplicate row is created (firstOrCreate on the natural key).
        $this->assertDatabaseHas('project_memberships', [
            'user_id' => $user->getKey(),
            'project_key' => 'hr-portal',
            'role' => 'owner',
        ]);
        $this->assertSame(
            1,
            ProjectMembership::query()
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
}
