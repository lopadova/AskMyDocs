<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\KnowledgeDocument;
use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
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
 * common case — zero-subscriber tenants — to 1 SELECT):
 *
 *   1. Pull every `User` who has a `notification_preferences` row in
 *      the event's tenant with `enabled=true` for the event_type.
 *      No project filter at the SQL layer because preferences are
 *      stored per (user, event_type, channel) — the project scope
 *      is enforced in PHP against `User::allowedProjects()` next.
 *   2. Filter the candidate set by `User::allowedProjects()`
 *      containing the event's `$projectKey` (or
 *      `User::PROJECT_WILDCARD`). A user with no membership in the
 *      project the event came from MUST NOT receive a notification
 *      that leaks the `source_path`, `title`, or `slug` of a doc
 *      they cannot otherwise see (Copilot PR #189 finding —
 *      cross-project ACL leak).
 *   3. For both `KbDocumentChanged` AND `KbCanonicalPromoted`,
 *      additionally call `User::hasDocumentAccess($doc, 'view')` per
 *      candidate so the row-level ACL on `knowledge_document_acl` and
 *      the `scope_allowlist` folder_globs / tags are honoured the same
 *      way `KnowledgeDocumentPolicy::view()` enforces them on the read
 *      path. `KbCanonicalPromoted` resolves the canonical
 *      `KnowledgeDocument` row from the audit's `(tenant, project,
 *      doc_id|slug)` triple (bypassing `AccessScopeScope` because the
 *      lookup is a SYSTEM operation), and SUPPRESSES the notification
 *      entirely if the row can't be resolved — otherwise we'd leak
 *      slug/doc_id metadata to a subscriber the ACL would deny.
 *
 *      ACL caveat for `KbDocumentChanged`: `knowledge_document_acl`
 *      rows are keyed by `knowledge_documents.id` (the auto-increment
 *      PK), and each re-ingest creates a NEW row with a new PK. Deny
 *      ACL rows attached to an earlier version of the same logical
 *      document therefore do NOT automatically carry over to the new
 *      row's notification — the publisher checks ACL on the EXACT
 *      row passed in. Inheriting ACL via stable `doc_id` would be a
 *      schema change parked outside W1.2. For first-ingest docs and
 *      for any future deny ACL added after creation, the check works
 *      as documented; the regression test pins that exact contract by
 *      invoking the publisher with a doc + pre-existing deny ACL.
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

        $candidates = $this->resolveCandidateRecipients($tenantId, $eventType);
        if ($candidates === []) {
            return;
        }

        $recipients = $this->filterByProjectAndDocumentAccess(
            $candidates,
            $projectKey,
            $document,
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

        $candidates = $this->resolveCandidateRecipients(
            $tenantId,
            NotificationEvent::EVENT_KB_CANONICAL_PROMOTED,
        );
        if ($candidates === []) {
            return;
        }

        $recipients = $this->filterByProjectAndDocumentAccess(
            $candidates,
            $projectKey,
            $document,
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
     * @return array<int, User>
     */
    private function resolveCandidateRecipients(string $tenantId, string $eventType): array
    {
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

        return User::query()
            ->withTrashed()
            ->whereIn('id', $userIds)
            ->get()
            ->all();
    }

    /**
     * @param  array<int, User>  $candidates
     * @return array<int, User>
     */
    private function filterByProject(array $candidates, string $projectKey): array
    {
        $eligible = [];
        foreach ($candidates as $user) {
            $allowed = $user->allowedProjects();
            if (in_array(User::PROJECT_WILDCARD, $allowed, true)
                || in_array($projectKey, $allowed, true)
            ) {
                $eligible[] = $user;
            }
        }
        return $eligible;
    }

    /**
     * @param  array<int, User>  $candidates
     * @return array<int, User>
     */
    private function filterByProjectAndDocumentAccess(
        array $candidates,
        string $projectKey,
        KnowledgeDocument $document,
    ): array {
        $eligible = [];
        foreach ($this->filterByProject($candidates, $projectKey) as $user) {
            if ($user->hasDocumentAccess($document, 'view')) {
                $eligible[] = $user;
            }
        }
        return $eligible;
    }
}
