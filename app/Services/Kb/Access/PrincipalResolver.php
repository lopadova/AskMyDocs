<?php

declare(strict_types=1);

namespace App\Services\Kb\Access;

use App\Models\ProjectMembership;
use App\Models\User;
use Padosoft\AskMyDocsConnectorBase\Access\SourcePrincipal;

/**
 * Turns "Drive says alice@example.com may read this" into "user 41", or into
 * nothing at all.
 *
 * This is the unglamorous core of ADR 0028 phase 2, and the part most likely
 * to be quietly softened later, so the rules it will not bend are worth
 * stating up front.
 *
 * **Failing to resolve is a result, not an error.** A group the directory
 * does not know, an external collaborator, a domain-wide share — these are
 * ordinary and expected. The caller gets null and is expected to record the
 * principal as unmapped, which is what puts it in front of an operator. A
 * resolver that swallowed them would make the triage queue look empty while
 * documents were quietly over- or under-shared.
 *
 * **Nothing here falls back to project-wide visibility.** That fallback is
 * the bug the whole ADR exists to fix and it is the tempting shortcut: it
 * makes the feature look like it works because everything stays visible.
 *
 * **Tenant membership is part of identity, not a filter applied afterwards.**
 * `users` is a cross-tenant table: the same email is one account across every
 * tenant. Matching on email alone would let a share in tenant A grant to an
 * account that only belongs to tenant B, so a match is only a match when the
 * account actually belongs to the tenant being resolved for (R30).
 */
final class PrincipalResolver
{
    /**
     * The internal subject this principal denotes, or null when it cannot be
     * determined.
     *
     * Null is never "no access" and never "everyone" — it is "we do not know
     * who this is", and the caller must treat it as such.
     */
    public function resolve(SourcePrincipal $principal, string $tenantId): ?ResolvedSubject
    {
        return match ($principal->type) {
            SourcePrincipal::TYPE_USER => $this->resolveUser($principal->externalId, $tenantId),

            // A group or a domain names a set of people, and mapping one to
            // an internal role or team needs a directory link this
            // application does not have yet. Returning null puts it in the
            // triage queue, which is the honest answer; inventing a mapping
            // from the group's name would grant on a string coincidence.
            SourcePrincipal::TYPE_GROUP,
            SourcePrincipal::TYPE_DOMAIN => null,

            // "Anyone with the link" is not a subject at all. It is a
            // statement about the document, and the caller handles it as one
            // — an ACL row granting to "anyone" would be a public share
            // written into a per-subject table.
            SourcePrincipal::TYPE_ANYONE => null,

            default => null,
        };
    }

    /**
     * Match a user principal by verified email within the tenant.
     *
     * Only an email is attempted. Sources also expose opaque account ids, but
     * those are per-provider and this application stores none of them, so
     * matching one would require a mapping table that does not exist —
     * pretending otherwise would produce confident wrong answers instead of
     * honest unresolved ones.
     */
    private function resolveUser(string $externalId, string $tenantId): ?ResolvedSubject
    {
        $email = strtolower(trim($externalId));

        if ($email === '' || ! str_contains($email, '@')) {
            return null;
        }

        // Case-insensitive by intent: sources spell the same address in
        // different cases and an address is not case-sensitive in practice.
        $user = User::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->first(['id']);

        if ($user === null) {
            return null;
        }

        // R30 — `users` is cross-tenant. Without this, a share in one
        // customer's Drive would resolve to an account that belongs only to
        // another customer.
        $belongs = ProjectMembership::query()
            ->forTenant($tenantId)
            ->where('user_id', $user->getKey())
            ->exists();

        return $belongs ? ResolvedSubject::user($user->getKey()) : null;
    }
}
