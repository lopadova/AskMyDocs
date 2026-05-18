<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\KnowledgeDocument;
use App\Models\KnowledgeDocumentAcl;
use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Notifications\Events\KbCanonicalPromoted;
use App\Notifications\Events\KbDocumentChanged;
use App\Scopes\AccessScopeScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * v8.0/W1.2 — production-side publisher that converts domain mutations
 * (a freshly-created `KnowledgeDocument` row, a `kb_canonical_audit`
 * row with `event_type='promoted'`) into the matching
 * `BaseNotificationEvent` subclass and dispatches it.
 *
 * Wired from `NotificationServiceProvider::boot()` via Eloquent model
 * `created` hooks so EVERY ingestion / promotion path (HTTP, CLI,
 * Flow, future connectors) ends up firing the event without each
 * publisher having to remember the call.
 *
 * Recipient resolution is layered (cheapest filter first to keep the
 * common case — zero-subscriber tenants — to 1 SELECT) and STREAMED
 * via `User::chunkById(500)` so a tenant with thousands of opt-in
 * users never materialises a single huge IN-clause or in-memory
 * collection:
 *
 *   1. Stream the User set via `chunkById(500)` driven by a
 *      `whereExists` join against `notification_preferences` —
 *      neither the candidate user-id list nor the User collection is
 *      materialised in full. Eligible recipients are accumulated
 *      after the predicate runs, so the resulting array stays
 *      bounded by the FILTERED count.
 *   2. Access predicate (`userCanViewDocumentInTenant`):
 *        a. `kb.read.any` global permission → allow (matches the
 *           `AccessScopeScope` bypass on the read path).
 *        b. Project membership in `(tenant_id, user_id, project_key)`
 *           on `project_memberships` — explicit tenant scope. Crucial:
 *           `User::allowedProjects()` does NOT carry a tenant filter
 *           and therefore returns memberships across tenants, so a
 *           User in `(tenant_B, project_X)` would falsely match an
 *           event for `(tenant_A, project_X)` since `User` rows are
 *           global and `project_key` is NOT globally unique. We
 *           bypass that leaky helper and query the join directly.
 *        c. INLINE ACL row evaluation (`evaluateAclForUser`) on
 *           `knowledge_document_acl`, keyed by the document's PK.
 *           Mirrors `User::evaluateAclDecision()` (which is
 *           `private`) so we get tenant-safe deny / allow semantics
 *           without dragging in `User::hasDocumentAccess()`'s
 *           leaky `allowedScopesFor()` path. Any matching deny →
 *           reject; any matching allow → allow; no match → fall
 *           through to membership-only allow.
 *
 *   `KbCanonicalPromoted` resolves the canonical `KnowledgeDocument`
 *   row from the audit's `(tenant, project, doc_id|slug)` triple
 *   (bypassing `AccessScopeScope` because this is a SYSTEM-side
 *   lookup), and SUPPRESSES the notification entirely if the row
 *   can't be resolved — otherwise we'd leak slug/doc_id metadata to
 *   subscribers the ACL would deny.
 *
 *   One limitation explicitly accepted in W1.2 baseline:
 *     - `knowledge_document_acl` is keyed by `knowledge_documents.id`
 *       (the auto-increment PK), and each re-ingest creates a NEW
 *       row with a new PK. Deny ACL rows attached to a prior version
 *       do NOT carry over to the fresh-row notification — the
 *       publisher checks ACL on the EXACT row passed in. Inheriting
 *       ACL via stable `doc_id` would be a schema change parked
 *       outside W1.2. The regression test pins that EXACT-row
 *       contract by invoking the publisher with a doc + pre-existing
 *       deny ACL.
 */
final class NotificationPublisher
{
    /**
     * Fires `KbDocumentChanged` for a freshly-persisted
     * `KnowledgeDocument` row. `$isModified` is `true` if any other
     * row exists in the same tenant + project + source_path (the prior
     * version was archived in the same transaction).
     *
     * Recipients are filtered to users who (a) hold an enabled
     * preference for the resolved event_type, (b) have project
     * membership in `(tenant_id, user_id, project_key)` (or the
     * global `kb.read.any` permission), (c) have no matching deny
     * row on `knowledge_document_acl` for this document's PK, AND
     * (d) pass the membership's `scope_allowlist` folder_globs /
     * tags check (when configured). The class docblock documents
     * the per-version ACL caveat.
     */
    public function publishKbDocumentChanged(
        KnowledgeDocument $document,
        bool $isModified,
    ): void {
        $tenantId = (string) ($document->tenant_id ?? '');
        $projectKey = (string) ($document->project_key ?? '');
        if ($tenantId === '' || $projectKey === '') {
            return;
        }

        $eventType = $isModified
            ? NotificationEvent::EVENT_KB_DOC_MODIFIED
            : NotificationEvent::EVENT_KB_DOC_CREATED;

        $recipients = $this->streamEligibleRecipients(
            $tenantId,
            $eventType,
            fn (User $user): bool => $this->userCanViewDocumentInTenant(
                $user,
                $tenantId,
                $projectKey,
                $document,
            ),
        );
        if ($recipients === []) {
            return;
        }

        Event::dispatch(new KbDocumentChanged(
            recipients: $recipients,
            payload: [
                'doc_id' => (int) $document->id,
                'project_key' => $projectKey,
                'source_path' => (string) $document->source_path,
                'title' => $document->title === null ? null : (string) $document->title,
                'change' => $isModified ? 'modified' : 'created',
            ],
            tenantId: $tenantId,
        ));
    }

    /**
     * Fires `KbCanonicalPromoted` for a `kb_canonical_audit` row with
     * `event_type='promoted'`. Recipients are filtered by project
     * membership AND the inline tenant-safe ACL evaluation
     * (`evaluateAclForUser`): the canonical `KnowledgeDocument` is
     * resolved from the audit's `(tenant, project, doc_id|slug)`
     * triple (with `AccessScopeScope` bypassed because this is a
     * SYSTEM-side lookup), and any candidate with a matching deny
     * row on `knowledge_document_acl` is dropped. When the audit
     * row predates / outlives a force-deleted canonical doc and the
     * resolver returns null, the notification is SUPPRESSED rather
     * than fanned out — otherwise we'd leak `slug` / `doc_id`
     * metadata to a subscriber whose ACL would deny them read access
     * to the same canonical via the chat / admin paths.
     */
    public function publishKbCanonicalPromoted(
        string $tenantId,
        string $projectKey,
        ?string $docId,
        ?string $slug,
        ?string $actor,
    ): void {
        if ($tenantId === '' || $projectKey === '') {
            return;
        }

        $document = $this->resolveCanonicalDocument($tenantId, $projectKey, $docId, $slug);
        if ($document === null) {
            // No live canonical row — suppress instead of leaking
            // slug/doc_id metadata to project members who can't
            // actually read the canonical via the normal paths.
            return;
        }

        $recipients = $this->streamEligibleRecipients(
            $tenantId,
            NotificationEvent::EVENT_KB_CANONICAL_PROMOTED,
            fn (User $user): bool => $this->userCanViewDocumentInTenant(
                $user,
                $tenantId,
                $projectKey,
                $document,
            ),
        );
        if ($recipients === []) {
            return;
        }

        Event::dispatch(new KbCanonicalPromoted(
            recipients: $recipients,
            payload: [
                'project_key' => $projectKey,
                'doc_id' => $docId,
                'slug' => $slug,
                'promoted_by' => $actor,
            ],
            tenantId: $tenantId,
        ));
    }

    /**
     * Resolve the canonical `KnowledgeDocument` referenced by a
     * `kb_canonical_audit` row. The audit row stores the canonical
     * `doc_id` and `slug` (tenant-scoped per R10) but does NOT carry
     * the `knowledge_documents.id` foreign key — by design, since the
     * audit must survive force-deletes. We resolve via the unique
     * `(tenant_id, project_key, doc_id)` slot, falling back to
     * `(tenant_id, project_key, slug, is_canonical=true)` for audits
     * that only recorded the slug. `AccessScopeScope` is bypassed
     * because this is a SYSTEM lookup, not a user-facing read.
     * `withTrashed()` is intentionally NOT used: a soft-deleted
     * canonical should NOT trigger fresh notifications.
     */
    private function resolveCanonicalDocument(
        string $tenantId,
        string $projectKey,
        ?string $docId,
        ?string $slug,
    ): ?KnowledgeDocument {
        if ($docId === null && $slug === null) {
            return null;
        }

        $query = KnowledgeDocument::query()
            ->withoutGlobalScope(AccessScopeScope::class)
            ->where('tenant_id', $tenantId)
            ->where('project_key', $projectKey);

        if ($docId !== null) {
            return $query->where('doc_id', $docId)->first();
        }

        return $query
            ->where('slug', $slug)
            ->where('is_canonical', true)
            ->first();
    }

    /**
     * Step 1 of the recipient pipeline: every `User` who opted in to
     * the event_type in the given tenant via at least one enabled
     * channel preference. The dispatcher will re-query per recipient
     * to pick the actual channel set.
     *
     * Loads in `User::chunkById(500)` batches so a tenant with
     * thousands of subscribers does not blow up the IN-clause list
     * or materialise a single huge model collection in PHP memory.
     * The returned array stays bounded by the *filtered* recipient
     * count (project + ACL filters apply per chunk and only eligible
     * recipients are kept), so a tenant where only a small fraction
     * of subscribers has access to the document does not allocate a
     * recipient array proportional to the total subscriber count.
     *
     * @param  callable(User $user): bool  $filter
     * @return array<int, User>
     */
    private function streamEligibleRecipients(
        string $tenantId,
        string $eventType,
        callable $filter,
    ): array {
        $eligible = [];
        User::query()
            ->withTrashed()
            ->whereExists(function ($query) use ($tenantId, $eventType): void {
                $query->select(DB::raw(1))
                    ->from('notification_preferences')
                    ->whereColumn('notification_preferences.user_id', 'users.id')
                    ->where('notification_preferences.tenant_id', $tenantId)
                    ->where('notification_preferences.event_type', $eventType)
                    ->where('notification_preferences.enabled', true);
            })
            ->chunkById(
                500,
                function ($users) use (&$eligible, $filter): void {
                    foreach ($users as $user) {
                        if ($filter($user)) {
                            $eligible[] = $user;
                        }
                    }
                },
            );
        return $eligible;
    }

    /**
     * Tenant-aware per-document access check.
     *
     * Layered semantics (cheapest predicate first, short-circuit):
     *   1. Global `kb.read.any` permission → allow (matches the
     *      `AccessScopeScope` bypass on the read path).
     *   2. Tenant-scoped project membership in
     *      `(tenant_id, user_id, project_key)`. We fetch the full
     *      `ProjectMembership` row (not just `exists()`) so the
     *      `scope_allowlist` JSON is available for step 4.
     *   3. INLINE ACL row evaluation (`evaluateAclForUser`) on
     *      `knowledge_document_acl` for the user OR any of their
     *      roles, scoped to the document's PK. Any matching deny →
     *      reject; any matching allow → allow.
     *   4. `scope_allowlist` folder_globs / tags check: if the
     *      membership row carries a non-empty
     *      `scope_allowlist`, the document MUST match at least one
     *      glob OR at least one tag (`documentMatchesScope`).
     *      Mirrors `User::matchesScope()` exactly so a recipient who
     *      cannot read the doc via the chat / admin path also cannot
     *      be notified about it.
     *
     * We deliberately do NOT delegate to `User::hasDocumentAccess()`:
     * its scope_allowlist arm calls `User::allowedScopesFor()` which
     * queries `project_memberships` WITHOUT a tenant_id predicate —
     * same cross-tenant leak class as `User::allowedProjects()`.
     * Mirroring the policy semantics inline keeps the tenant scope
     * structurally enforced at the SQL layer.
     */
    private function userCanViewDocumentInTenant(
        User $user,
        string $tenantId,
        string $projectKey,
        KnowledgeDocument $document,
    ): bool {
        if ($user->can('kb.read.any')) {
            return true;
        }

        // ACL evaluation BEFORE the membership check so the semantics
        // mirror `User::hasDocumentAccess()` exactly:
        //   - deny ACL row → always reject (overrides everything)
        //   - allow ACL row → always allow (admins can grant access to
        //     non-members)
        //   - no ACL match → fall through to membership + scope check
        $acl = $this->evaluateAclForUser($user, $document);
        if ($acl === 'deny') {
            return false;
        }
        if ($acl === 'allow') {
            return true;
        }

        $membership = ProjectMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('project_key', $projectKey)
            ->first();
        if ($membership === null) {
            return false;
        }

        $scope = is_array($membership->scope_allowlist) ? $membership->scope_allowlist : [];
        return $this->documentMatchesScope($document, $scope);
    }

    /**
     * Mirror of `User::matchesScope()`. Empty scope → unrestricted;
     * non-empty scope → doc must match at least one folder glob OR
     * one tag for the user to be eligible. Keeping the algorithm
     * byte-identical to the read-path policy means a future R19
     * fnmatch tightening (FNM_PATHNAME) lands once in `User` and
     * the publisher inherits the corrected semantics on the next
     * sync — no parallel implementation to keep in lockstep.
     *
     * @param  array{folder_globs?: array<int,string>, tags?: array<int,string>}  $scope
     */
    private function documentMatchesScope(KnowledgeDocument $document, array $scope): bool
    {
        $globs = $scope['folder_globs'] ?? [];
        $tags = $scope['tags'] ?? [];

        if ($globs === [] && $tags === []) {
            return true;
        }
        if ($globs !== [] && $this->matchesAnyGlob((string) $document->source_path, $globs)) {
            return true;
        }
        if ($tags !== [] && $this->documentHasAnyTag($document, $tags)) {
            return true;
        }
        return false;
    }

    /**
     * @param  array<int,string>  $globs
     *
     * `FNM_PATHNAME` so `*` matches a single path segment instead of
     * spanning `/` separators — `hr/*` should match `hr/policy.md`
     * but NOT `hr/policy/details.md` (per R19 input-escape complete).
     *
     * Intentional divergence from `User::matchesAnyGlob()`, which
     * still calls `fnmatch()` without flags (the long-standing R19
     * bug already flagged in CLAUDE.md and tracked as a follow-up at
     * the User-layer). The publisher chooses the stricter behaviour
     * because a false-POSITIVE notification (the user gets notified
     * about a doc they can read via the broken-glob read path) is
     * less harmful than a false-NEGATIVE (the user is denied a
     * notification they SHOULD receive). Once the User-level R19 fix
     * lands, both paths will agree without further changes here.
     */
    private function matchesAnyGlob(string $path, array $globs): bool
    {
        foreach ($globs as $glob) {
            if (fnmatch($glob, $path, FNM_PATHNAME)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Defence-in-depth tenant scope: `kb_tags` and
     * `knowledge_document_tags` are R30 tenant-aware. The
     * `knowledge_document_id` predicate is naturally tenant-safe
     * (the document was loaded with explicit tenant_id), but we
     * still add the explicit `tenant_id` predicate so a future
     * audit trace can see the constraint at the SQL layer instead
     * of inferring it from the FK chain.
     *
     * @param  array<int,string>  $tagSlugs
     */
    private function documentHasAnyTag(KnowledgeDocument $document, array $tagSlugs): bool
    {
        $tenantId = (string) ($document->tenant_id ?? '');
        if ($tenantId === '') {
            return false;
        }

        return DB::table('knowledge_document_tags')
            ->join('kb_tags', 'kb_tags.id', '=', 'knowledge_document_tags.kb_tag_id')
            ->where('knowledge_document_tags.knowledge_document_id', $document->getKey())
            ->where('knowledge_document_tags.tenant_id', $tenantId)
            ->where('kb_tags.tenant_id', $tenantId)
            ->whereIn('kb_tags.slug', $tagSlugs)
            ->exists();
    }

    /**
     * Inline mirror of `User::evaluateAclDecision()` (which is
     * `private`) — returns `'deny'` if any matching deny ACL row
     * exists, `'allow'` if at least one allow row matches with no
     * deny, or `null` if no ACL row matches. The lookup is keyed by
     * the document's PK so it is naturally tenant-safe.
     *
     * @return 'allow'|'deny'|null
     */
    private function evaluateAclForUser(User $user, KnowledgeDocument $document): ?string
    {
        $tenantId = (string) ($document->tenant_id ?? '');
        if ($tenantId === '') {
            return null;
        }

        $roleNames = $user->getRoleNames()->all();
        $effects = DB::table('knowledge_document_acl')
            ->where('knowledge_document_id', $document->getKey())
            ->where('tenant_id', $tenantId)
            ->where('permission', KnowledgeDocumentAcl::PERMISSION_VIEW)
            ->where(function ($query) use ($user, $roleNames): void {
                $query->where(function ($q) use ($user): void {
                    $q->where('subject_type', KnowledgeDocumentAcl::SUBJECT_USER)
                        ->where('subject_id', (string) $user->getKey());
                })->orWhere(function ($q) use ($roleNames): void {
                    if ($roleNames === []) {
                        $q->whereRaw('1=0');
                        return;
                    }
                    $q->where('subject_type', KnowledgeDocumentAcl::SUBJECT_ROLE)
                        ->whereIn('subject_id', $roleNames);
                });
            })
            ->pluck('effect');

        if ($effects->contains(KnowledgeDocumentAcl::EFFECT_DENY)) {
            return 'deny';
        }
        if ($effects->contains(KnowledgeDocumentAcl::EFFECT_ALLOW)) {
            return 'allow';
        }
        return null;
    }
}
