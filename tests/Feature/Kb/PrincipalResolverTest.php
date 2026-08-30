<?php

declare(strict_types=1);

namespace Tests\Feature\Kb;

use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Kb\Access\PrincipalResolver;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Padosoft\AskMyDocsConnectorBase\Access\SourcePrincipal;
use Tests\TestCase;

/**
 * ADR 0028 phase 2 — the resolver is where over-sharing is either prevented
 * or quietly reintroduced.
 *
 * Every test here is really about one question: when the resolver is unsure,
 * does it say so, or does it guess? Guessing in the permissive direction is
 * the bug the ADR exists to fix; guessing in the restrictive one hides
 * documents from people who should see them. The answer has to be "it says
 * so" in both directions.
 */
final class PrincipalResolverTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = app(TenantContext::class)->current();
    }

    private function resolver(): PrincipalResolver
    {
        return app(PrincipalResolver::class);
    }

    private function makeUser(string $email, ?string $membershipTenant = null): User
    {
        $user = User::create([
            'name' => 'Someone',
            'email' => $email,
            'password' => Hash::make('secret-secret'),
        ]);

        if ($membershipTenant !== null) {
            ProjectMembership::create([
                'tenant_id' => $membershipTenant,
                'user_id' => $user->id,
                'project_key' => 'default',
                'role' => 'member',
                'scope_allowlist' => null,
            ]);
        }

        return $user;
    }

    public function test_it_matches_a_user_by_email_within_the_tenant(): void
    {
        $user = $this->makeUser('alice@example.com', $this->tenantId);

        $resolved = $this->resolver()->resolve(
            SourcePrincipal::user('alice@example.com'),
            $this->tenantId,
        );

        $this->assertNotNull($resolved);
        $this->assertSame('user', $resolved->subjectType);
        $this->assertSame((string) $user->id, $resolved->subjectId);
    }

    public function test_the_match_is_case_insensitive(): void
    {
        // Sources spell the same address in different cases and an address is
        // not case-sensitive in practice; a failed match here would send a
        // perfectly resolvable person to triage.
        $user = $this->makeUser('alice@example.com', $this->tenantId);

        $resolved = $this->resolver()->resolve(
            SourcePrincipal::user('  Alice@Example.COM '),
            $this->tenantId,
        );

        $this->assertNotNull($resolved);
        $this->assertSame((string) $user->id, $resolved->subjectId);
    }

    public function test_an_account_outside_the_tenant_is_not_a_match(): void
    {
        // R30. `users` is cross-tenant: the same email is one account across
        // every tenant. Matching on email alone would let a share in one
        // customer's source grant to an account belonging only to another.
        $this->makeUser('alice@example.com', 'some-other-tenant');

        $resolved = $this->resolver()->resolve(
            SourcePrincipal::user('alice@example.com'),
            $this->tenantId,
        );

        $this->assertNull($resolved);
    }

    public function test_an_account_with_no_membership_anywhere_is_not_a_match(): void
    {
        $this->makeUser('ghost@example.com');

        $this->assertNull(
            $this->resolver()->resolve(SourcePrincipal::user('ghost@example.com'), $this->tenantId),
        );
    }

    public function test_an_unknown_address_resolves_to_nothing(): void
    {
        // The external collaborator case, and the single most common one.
        // Null here is what puts the principal in front of an operator.
        $this->assertNull(
            $this->resolver()->resolve(SourcePrincipal::user('outsider@elsewhere.test'), $this->tenantId),
        );
    }

    public function test_a_non_email_identifier_resolves_to_nothing(): void
    {
        // Sources also expose opaque account ids. This application stores
        // none of them, so matching one would need a mapping table that does
        // not exist — a confident wrong answer instead of an honest unresolved
        // one.
        $this->assertNull(
            $this->resolver()->resolve(SourcePrincipal::user('1049283740192'), $this->tenantId),
        );
    }

    public function test_groups_and_domains_are_left_unresolved_rather_than_guessed(): void
    {
        // Mapping a group to an internal role needs a directory link this
        // application does not have. Inferring one from the name would grant
        // on a string coincidence — an upstream group called "editors" is not
        // this application's `editor` role.
        $this->assertNull(
            $this->resolver()->resolve(SourcePrincipal::group('editors'), $this->tenantId),
        );
        $this->assertNull(
            $this->resolver()->resolve(SourcePrincipal::domain('example.com'), $this->tenantId),
        );
    }

    public function test_anyone_is_not_a_subject(): void
    {
        // "Anyone with the link" is a statement about the document, not about
        // a person. An ACL row granting to "anyone" would be a public share
        // written into a per-subject table.
        $this->assertNull(
            $this->resolver()->resolve(SourcePrincipal::anyone(), $this->tenantId),
        );
    }

    public function test_a_deny_principal_still_resolves_to_its_subject(): void
    {
        // Resolution answers "who is this", not "what should happen". The
        // effect travels with the principal and is the caller's business —
        // refusing to resolve a deny would silently drop the most restrictive
        // half of an upstream ACL.
        $user = $this->makeUser('alice@example.com', $this->tenantId);

        $resolved = $this->resolver()->resolve(
            SourcePrincipal::user('alice@example.com', SourcePrincipal::EFFECT_DENY),
            $this->tenantId,
        );

        $this->assertNotNull($resolved);
        $this->assertSame((string) $user->id, $resolved->subjectId);
    }
}
