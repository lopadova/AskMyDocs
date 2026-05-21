<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\KbCanonicalAudit;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeDocumentAcl;
use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Notifications\ChannelRegistry;
use App\Notifications\NotificationPublisher;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Notifications\RecordingChannel;
use Tests\TestCase;

/**
 * v8.0/W1.2 — production publisher bridge
 * (`NotificationServiceProvider::wireDomainPublishers()`).
 *
 * Pins that EVERY ingestion / promotion path that ends up creating a
 * `KnowledgeDocument` row or a `kb_canonical_audit` row with
 * `event_type='promoted'` fans out the matching
 * `BaseNotificationEvent` via the dispatcher — provided the candidate
 * subscriber has both an enabled preference AND project membership +
 * row-level ACL covering the document. Addresses two Copilot findings
 * on PR #189:
 *   1. "events are dead code in production" (publisher wiring)
 *   2. "recipient resolution leaks across project / ACL boundaries"
 *      (project + ACL filter — pinned by the `ignores_*` scenarios)
 */
final class NotificationPublisherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');

        $registry = app(ChannelRegistry::class);
        $registry->register(new RecordingChannel(NotificationPreference::CHANNEL_IN_APP));
    }

    public function test_knowledge_document_create_fires_kb_document_changed_to_subscribers(): void
    {
        $subscriber = $this->makeMember('sub', 'proj-pub');
        $this->enablePref($subscriber, NotificationEvent::EVENT_KB_DOC_CREATED);

        KnowledgeDocument::create($this->docAttributes('proj-pub', 'docs/example.md', 'example-1'));

        $row = NotificationEvent::sole();
        $this->assertSame(NotificationEvent::EVENT_KB_DOC_CREATED, $row->event_type);
        $this->assertSame($subscriber->id, $row->user_id);
        $this->assertSame('proj-pub', $row->payload['project_key'] ?? null);
        $this->assertSame('created', $row->payload['change'] ?? null);
    }

    public function test_knowledge_document_reingest_fires_modified_event(): void
    {
        $subscriber = $this->makeMember('mod', 'proj-mod');
        $this->enablePref($subscriber, NotificationEvent::EVENT_KB_DOC_MODIFIED);

        // First version — no subscriber for `created`, so no row.
        KnowledgeDocument::create(
            $this->docAttributes('proj-mod', 'docs/dec.md', 'v1', 'archived')
        );
        $this->assertSame(0, NotificationEvent::count());

        // Second version — same (project, source_path) → 'modified'.
        KnowledgeDocument::create(
            $this->docAttributes('proj-mod', 'docs/dec.md', 'v2', 'active', 'Decision v2')
        );

        $row = NotificationEvent::sole();
        $this->assertSame(NotificationEvent::EVENT_KB_DOC_MODIFIED, $row->event_type);
        $this->assertSame('modified', $row->payload['change'] ?? null);
    }

    public function test_publisher_ignores_users_without_project_membership(): void
    {
        // Subscriber has the preference but no project_memberships row
        // covering 'proj-secret', so they MUST NOT receive the event.
        $outsider = $this->makeUser('outsider');
        $this->enablePref($outsider, NotificationEvent::EVENT_KB_DOC_CREATED);

        KnowledgeDocument::create(
            $this->docAttributes('proj-secret', 'docs/secret.md', 'secret-1', 'active', 'Secret')
        );

        $this->assertSame(0, NotificationEvent::count());
    }

    public function test_publisher_ignores_users_with_deny_acl_on_document(): void
    {
        // Pins the documented ACL contract on `KbDocumentChanged`:
        // when the publisher's `hasDocumentAccess($doc, 'view')` check
        // runs against a doc with a pre-existing deny ACL for the
        // candidate user, that user is dropped from the recipient set.
        //
        // The test invokes `NotificationPublisher::publishKbDocumentChanged()`
        // DIRECTLY (instead of triggering it via the `KnowledgeDocument::created`
        // hook) for a reason: ACL rows are keyed by the document's PK,
        // and the hook fires when a row is CREATED — at which point no
        // ACL row can have referenced that PK yet. The realistic
        // scenario the publisher must guard is a re-emission of the
        // event (queue retry, manual replay, future audit-replay
        // tooling) for a doc with a deny ACL already attached.
        $member = $this->makeMember('blocked', 'proj-acl');
        $this->enablePref($member, NotificationEvent::EVENT_KB_DOC_CREATED);

        $document = KnowledgeDocument::create(
            $this->docAttributes('proj-acl', 'docs/restricted.md', 'restricted-1', 'active', 'Restricted')
        );

        // First create already fanned out a `KbDocumentChanged(created)`
        // event (subscriber was eligible at that moment). Reset so the
        // assertion below measures the ACL filter only.
        NotificationEvent::query()->delete();

        KnowledgeDocumentAcl::create([
            'knowledge_document_id' => $document->id,
            'subject_type' => KnowledgeDocumentAcl::SUBJECT_USER,
            'subject_id' => (string) $member->getKey(),
            'permission' => KnowledgeDocumentAcl::PERMISSION_VIEW,
            'effect' => KnowledgeDocumentAcl::EFFECT_DENY,
        ]);

        // Direct publisher invocation — bypasses the `created` hook so
        // the ACL filter is actually exercised on the doc + ACL pair.
        app(NotificationPublisher::class)->publishKbDocumentChanged($document, false);

        $this->assertSame(0, NotificationEvent::count());
    }

    public function test_publisher_ignores_user_whose_scope_allowlist_excludes_document(): void
    {
        // Member has tenant-scoped project membership AND preference,
        // but their `scope_allowlist.folder_globs` only matches paths
        // outside the new document's source_path. Mirrors the read-path
        // policy (KnowledgeDocumentPolicy::view) so the user cannot
        // receive a notification about a doc they could not otherwise
        // read.
        $member = $this->makeUser('scoped');
        ProjectMembership::create([
            'user_id' => $member->id,
            'project_key' => 'proj-scope',
            'role' => 'member',
            'scope_allowlist' => ['folder_globs' => ['hr/*']],
        ]);
        $this->enablePref($member, NotificationEvent::EVENT_KB_DOC_CREATED);

        KnowledgeDocument::create(
            $this->docAttributes('proj-scope', 'engineering/rfc.md', 'rfc-1', 'active', 'Engineering RFC')
        );

        $this->assertSame(0, NotificationEvent::count());
    }

    public function test_publisher_includes_user_whose_scope_allowlist_matches_document(): void
    {
        // Same setup as above, but the doc source_path matches the
        // scope_allowlist glob → user IS notified.
        $member = $this->makeUser('scoped-match');
        ProjectMembership::create([
            'user_id' => $member->id,
            'project_key' => 'proj-scope-match',
            'role' => 'member',
            'scope_allowlist' => ['folder_globs' => ['hr/*']],
        ]);
        $this->enablePref($member, NotificationEvent::EVENT_KB_DOC_CREATED);

        KnowledgeDocument::create(
            $this->docAttributes('proj-scope-match', 'hr/policy.md', 'pol-1', 'active', 'HR Policy')
        );

        $row = NotificationEvent::sole();
        $this->assertSame($member->id, $row->user_id);
    }

    public function test_publisher_ignores_cross_tenant_project_membership_with_same_project_key(): void
    {
        // User Alice has membership in `(tenant_other, proj-shared)`.
        // The current event fires under tenant `default` for the same
        // `project_key = proj-shared`. Because `project_key` is NOT
        // globally unique and `User` rows ARE global, a naive
        // `User::allowedProjects()` (no tenant filter) would falsely
        // match Alice. The publisher's tenant-scoped membership lookup
        // must reject this candidate.
        $alice = $this->makeUser('cross-tenant-alice');
        ProjectMembership::create([
            'tenant_id' => 'tenant_other',
            'user_id' => $alice->id,
            'project_key' => 'proj-shared',
            'role' => 'member',
            'scope_allowlist' => null,
        ]);
        // Alice's preference is in the current tenant (default) —
        // preference lookup will return her id, but the project filter
        // MUST drop her because she has no membership in
        // (default, proj-shared).
        $this->enablePref($alice, NotificationEvent::EVENT_KB_DOC_CREATED);

        KnowledgeDocument::create(
            $this->docAttributes('proj-shared', 'docs/cross.md', 'cross-1', 'active', 'Cross-tenant')
        );

        $this->assertSame(0, NotificationEvent::count());
    }

    public function test_publisher_ignores_users_in_a_different_project(): void
    {
        // Member belongs to project-A but the event fires for project-B —
        // even though the preference matches the event_type, the member
        // must not receive it.
        $aMember = $this->makeMember('a-mem', 'proj-A');
        $this->enablePref($aMember, NotificationEvent::EVENT_KB_DOC_CREATED);

        KnowledgeDocument::create(
            $this->docAttributes('proj-B', 'docs/other.md', 'other-1', 'active', 'Other proj')
        );

        $this->assertSame(0, NotificationEvent::count());
    }

    public function test_kb_canonical_audit_promoted_fires_kb_canonical_promoted(): void
    {
        $subscriber = $this->makeMember('promo', 'proj-promo');
        $this->enablePref($subscriber, NotificationEvent::EVENT_KB_CANONICAL_PROMOTED);

        $this->makeCanonicalDocument('proj-promo', 'dec-cache-v2');
        // Reset — the document create() already fanned out a
        // KbDocumentChanged event (subscribers for that type are 0,
        // but if any tested side-effects landed we want a clean
        // baseline). No notification_events rows yet because the
        // subscriber is only opted into the promoted event_type.
        NotificationEvent::query()->delete();

        KbCanonicalAudit::create([
            'project_key' => 'proj-promo',
            'doc_id' => 'dec-cache-v2',
            'slug' => 'dec-cache-v2',
            'event_type' => 'promoted',
            'actor' => 'flow:kb.promote:write-markdown',
            'before_json' => null,
            'after_json' => null,
            'metadata_json' => [],
        ]);

        $row = NotificationEvent::sole();
        $this->assertSame(NotificationEvent::EVENT_KB_CANONICAL_PROMOTED, $row->event_type);
        $this->assertSame('dec-cache-v2', $row->payload['slug'] ?? null);
        $this->assertSame('flow:kb.promote:write-markdown', $row->payload['promoted_by'] ?? null);
    }

    public function test_canonical_promoted_suppressed_when_underlying_document_missing(): void
    {
        // Audit row exists but no `knowledge_documents` row matches the
        // doc_id/slug (force-deleted canonical, or audit emitted before
        // the doc lands). Publisher MUST NOT leak slug/doc_id metadata
        // to subscribers who can't be ACL-checked.
        $subscriber = $this->makeMember('orphan', 'proj-orphan');
        $this->enablePref($subscriber, NotificationEvent::EVENT_KB_CANONICAL_PROMOTED);

        KbCanonicalAudit::create([
            'project_key' => 'proj-orphan',
            'doc_id' => 'dec-gone',
            'slug' => 'dec-gone',
            'event_type' => 'promoted',
            'actor' => 'flow:kb.promote:write-markdown',
            'before_json' => null,
            'after_json' => null,
            'metadata_json' => [],
        ]);

        $this->assertSame(0, NotificationEvent::count());
    }

    public function test_canonical_promoted_ignores_users_with_deny_acl(): void
    {
        $member = $this->makeMember('canon-blocked', 'proj-acl-canon');
        $this->enablePref($member, NotificationEvent::EVENT_KB_CANONICAL_PROMOTED);
        $document = $this->makeCanonicalDocument('proj-acl-canon', 'dec-blocked');

        KnowledgeDocumentAcl::create([
            'knowledge_document_id' => $document->id,
            'subject_type' => KnowledgeDocumentAcl::SUBJECT_USER,
            'subject_id' => (string) $member->getKey(),
            'permission' => KnowledgeDocumentAcl::PERMISSION_VIEW,
            'effect' => KnowledgeDocumentAcl::EFFECT_DENY,
        ]);

        NotificationEvent::query()->delete();

        KbCanonicalAudit::create([
            'project_key' => 'proj-acl-canon',
            'doc_id' => 'dec-blocked',
            'slug' => 'dec-blocked',
            'event_type' => 'promoted',
            'actor' => 'flow:kb.promote:write-markdown',
            'before_json' => null,
            'after_json' => null,
            'metadata_json' => [],
        ]);

        $this->assertSame(0, NotificationEvent::count());
    }

    public function test_audit_with_non_promoted_event_type_does_not_fire_notification(): void
    {
        $subscriber = $this->makeMember('noise', 'proj-noise');
        $this->enablePref($subscriber, NotificationEvent::EVENT_KB_CANONICAL_PROMOTED);

        KbCanonicalAudit::create([
            'project_key' => 'proj-noise',
            'doc_id' => 'dec-x',
            'slug' => 'dec-x',
            'event_type' => 'deprecated',
            'actor' => 'system',
            'before_json' => null,
            'after_json' => null,
            'metadata_json' => [],
        ]);

        $this->assertSame(0, NotificationEvent::count());
    }

    public function test_canonical_promoted_ignores_users_in_other_projects(): void
    {
        // Subscriber belongs to project-A; the canonical audit row is
        // for project-B → publisher's project filter MUST skip them.
        $aMember = $this->makeMember('a-canon', 'proj-A');
        $this->enablePref($aMember, NotificationEvent::EVENT_KB_CANONICAL_PROMOTED);

        $this->makeCanonicalDocument('proj-B', 'dec-b');
        NotificationEvent::query()->delete();

        KbCanonicalAudit::create([
            'project_key' => 'proj-B',
            'doc_id' => 'dec-b',
            'slug' => 'dec-b',
            'event_type' => 'promoted',
            'actor' => 'flow:kb.promote:write-markdown',
            'before_json' => null,
            'after_json' => null,
            'metadata_json' => [],
        ]);

        $this->assertSame(0, NotificationEvent::count());
    }

    public function test_no_subscribers_means_no_dispatcher_invocation(): void
    {
        KnowledgeDocument::create(
            $this->docAttributes('proj-empty', 'docs/empty.md', 'empty', 'active', 'No Subscribers')
        );

        $this->assertSame(0, NotificationEvent::count());
    }

    /**
     * @return array<string, mixed>
     */
    private function docAttributes(
        string $projectKey,
        string $sourcePath,
        string $hashSeed,
        string $status = 'active',
        string $title = 'Example',
    ): array {
        return [
            'project_key' => $projectKey,
            'source_path' => $sourcePath,
            'source_type' => 'markdown',
            'title' => $title,
            'mime_type' => 'text/markdown',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => $status,
            'document_hash' => hash('sha256', $hashSeed),
            'version_hash' => hash('sha256', $hashSeed),
            'metadata' => [],
            'indexed_at' => now(),
        ];
    }

    private function enablePref(User $user, string $eventType): void
    {
        NotificationPreference::create([
            'user_id' => $user->id,
            'event_type' => $eventType,
            'channel' => NotificationPreference::CHANNEL_IN_APP,
            'enabled' => true,
        ]);
    }

    private function makeUser(string $slug): User
    {
        return User::create([
            'name' => "publisher-user-{$slug}",
            'email' => "{$slug}-".uniqid('', true).'@test.local',
            'password' => Hash::make('secret123'),
        ]);
    }

    private function makeMember(string $slug, string $projectKey): User
    {
        $user = $this->makeUser($slug);
        ProjectMembership::create([
            'user_id' => $user->id,
            'project_key' => $projectKey,
            'role' => 'member',
            'scope_allowlist' => null,
        ]);
        return $user;
    }

    private function makeCanonicalDocument(string $projectKey, string $docId): KnowledgeDocument
    {
        return KnowledgeDocument::create(array_merge(
            $this->docAttributes($projectKey, "canonical/{$docId}.md", "canonical-{$docId}", 'active', $docId),
            [
                'is_canonical' => true,
                'doc_id' => $docId,
                'slug' => $docId,
                'canonical_type' => 'decision',
                'canonical_status' => 'accepted',
            ],
        ));
    }
}
