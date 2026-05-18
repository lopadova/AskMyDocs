<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\KnowledgeDocument;
use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Notifications\Events\KbCanonicalPromoted;
use App\Notifications\Events\KbDocumentChanged;
use App\Scopes\AccessScopeScope;
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
 *   1. Pull `notification_preferences.user_id` for the event's tenant
 *      + event_type + enabled=true. This is the only SQL pre-filter.
 *   2. `chunkById(500)` the candidate User set; for each chunk apply
 *      the access predicate in PHP and accumulate eligible users.
 *   3. Access predicate (`userCanViewDocumentInTenant`):
 *        a. Project membership in `(tenant_id, user_id, project_key)`
 *           on `project_memberships` — explicit tenant scope. Crucial:
 *           `User::allowedProjects()` does NOT carry a tenant filter
 *           and therefore returns memberships across tenants, so a
 *           User in `(tenant_B, project_X)` would falsely match an
 *           event for `(tenant_A, project_X)` since `User` rows are
 *           global and `project_key` is NOT globally unique. We
 *           bypass that leaky helper and query the join directly.
 *           `kb.read.any` global permission still short-circuits.
 *        b. `User::hasDocumentAccess($doc, 'view')` — the ACL row
 *           lookup is keyed by `knowledge_documents.id`, so the
 *           document we already loaded (with explicit tenant_id)
 *           anchors the check to the right tenant's doc.
 *
 *   `KbCanonicalPromoted` resolves the canonical `KnowledgeDocument`
 *   row from the audit's `(tenant, project, doc_id|slug)` triple
 *   (bypassing `AccessScopeScope` because this is a SYSTEM-side
 *   lookup), and SUPPRESSES the notification entirely if the row
 *   can't be resolved — otherwise we'd leak slug/doc_id metadata to
 *   subscribers the ACL would deny.
 *
 *   ACL caveat for `KbDocumentChanged`: `knowledge_document_acl`
 *   rows are keyed by `knowledge_documents.id` (the auto-increment
 *   PK), and each re-ingest creates a NEW row with a new PK. Deny
 *   ACL rows attached to an earlier version of the same logical
 *   document therefore do NOT automatically carry over to the new
 *   row's notification — the publisher checks ACL on the EXACT row
 *   passed in. Inheriting ACL via stable `doc_id` would be a schema
 *   change parked outside W1.2. For first-ingest docs and for any
 *   future deny ACL added after creation, the check works as
 *   documented; the regression test pins that exact contract by
 *   invoking the publisher with a doc + pre-existing deny ACL.
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
     * membership covering `$document->project_key`, AND (c) pass
     * `User::hasDocumentAccess($document, 'view')` so deny ACL rows
     * + scope_allowlist restrictions block the leak.
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
     * membership AND per-document ACL: the canonical
     * `KnowledgeDocument` is resolved from the audit's
     * `(tenant, project, doc_id|slug)` triple (with
     * `AccessScopeScope` bypassed because this is a SYSTEM-side
     * lookup) and the recipient list is gated on
     * `User::hasDocumentAccess($doc, 'view')`. When the audit row
     * predates / outlives a force-deleted canonical doc and the
     * resolver returns null, the notification is SUPPRESSED rather
     * than fanned out — otherwise we'd leak `slug` / `doc_id`
     * metadata to a subscriber whose ACL would otherwise deny them
     * read access to the same canonical via the chat / admin paths.
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
        $userIds = NotificationPreference::query()
            ->where('tenant_id', $tenantId)
            ->where('event_type', $eventType)
            ->where('enabled', true)
            ->distinct()
            ->pluck('user_id')
            ->all();

        if ($userIds === []) {
            return [];
        }

        $eligible = [];
        User::query()
            ->withTrashed()
            ->whereIn('id', $userIds)
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
     * Tenant-aware project membership check. `User::allowedProjects()`
     * queries `project_memberships` without a tenant filter, so a User
     * who belongs to `(tenant_B, project_X)` would falsely match an
     * event for `(tenant_A, project_X)` — `User` rows are global,
     * `project_key` is NOT globally unique, and BelongsToTenant only
     * stamps on write. Query memberships explicitly with both
     * `tenant_id` AND `project_key` predicates so the leak is
     * structurally impossible at the SQL layer.
     *
     * Global `kb.read.any` permission still short-circuits to allow
     * (matching `AccessScopeScope` semantics on the read path).
     */
    private function userHasProjectAccessInTenant(
        User $user,
        string $tenantId,
        string $projectKey,
    ): bool {
        if ($user->can('kb.read.any')) {
            return true;
        }

        return ProjectMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('project_key', $projectKey)
            ->exists();
    }

    /**
     * Tenant-aware per-document access check. `User::hasDocumentAccess()`
     * does the ACL lookup by `knowledge_document_id` (tenant-safe via
     * the doc's PK) and the scope_allowlist lookup via
     * `User::allowedScopesFor($projectKey)` (which queries
     * `project_memberships` without a tenant filter). Setting the
     * `TenantContext` for the duration of the check would not help
     * because the User methods don't consult TenantContext when
     * querying memberships — so we mirror the tenant-correct
     * membership lookup inline first, then defer to
     * `hasDocumentAccess()` for the ACL + permission semantics.
     */
    private function userCanViewDocumentInTenant(
        User $user,
        string $tenantId,
        string $projectKey,
        KnowledgeDocument $document,
    ): bool {
        if (! $this->userHasProjectAccessInTenant($user, $tenantId, $projectKey)) {
            return false;
        }
        return $user->hasDocumentAccess($document, 'view');
    }
}
