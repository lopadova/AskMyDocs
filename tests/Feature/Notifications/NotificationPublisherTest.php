<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\KbCanonicalAudit;
use App\Models\KnowledgeDocument;
use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\ChannelRegistry;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Notifications\RecordingChannel;
use Tests\TestCase;

/**
 * v8.0/W1.2 — production publisher bridge (NotificationServiceProvider
 * `wireDomainPublishers()`).
 *
 * Pins that EVERY ingestion / promotion path that ends up creating a
 * `KnowledgeDocument` row or a `kb_canonical_audit` row with
 * `event_type='promoted'` fans out the matching `BaseNotificationEvent`
 * via the dispatcher, regardless of which code path triggered the
 * create() — addresses the "events are dead code in production"
 * Copilot finding on PR #189.
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
        $subscriber = $this->makeUser('sub');
        NotificationPreference::create([
            'user_id' => $subscriber->id,
            'event_type' => NotificationEvent::EVENT_KB_DOC_CREATED,
            'channel' => NotificationPreference::CHANNEL_IN_APP,
            'enabled' => true,
        ]);

        KnowledgeDocument::create([
            'project_key' => 'proj-pub',
            'source_path' => 'docs/example.md',
            'source_type' => 'markdown',
            'title' => 'Example',
            'mime_type' => 'text/markdown',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', 'example-1'),
            'version_hash' => hash('sha256', 'example-1'),
            'metadata' => [],
            'indexed_at' => now(),
        ]);

        $row = NotificationEvent::sole();
        $this->assertSame(NotificationEvent::EVENT_KB_DOC_CREATED, $row->event_type);
        $this->assertSame($subscriber->id, $row->user_id);
        $this->assertSame('proj-pub', $row->payload['project_key'] ?? null);
        $this->assertSame('created', $row->payload['change'] ?? null);
    }

    public function test_knowledge_document_reingest_fires_modified_event(): void
    {
        $subscriber = $this->makeUser('mod');
        NotificationPreference::create([
            'user_id' => $subscriber->id,
            'event_type' => NotificationEvent::EVENT_KB_DOC_MODIFIED,
            'channel' => NotificationPreference::CHANNEL_IN_APP,
            'enabled' => true,
        ]);

        // First version — no subscriber for `created`, so no row.
        KnowledgeDocument::create([
            'project_key' => 'proj-mod',
            'source_path' => 'docs/dec.md',
            'source_type' => 'markdown',
            'title' => 'Decision v1',
            'mime_type' => 'text/markdown',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => 'archived',
            'document_hash' => hash('sha256', 'v1'),
            'version_hash' => hash('sha256', 'v1'),
            'metadata' => [],
            'indexed_at' => now(),
        ]);

        $this->assertSame(0, NotificationEvent::count());

        // Second version — same (project, source_path) → 'modified'.
        KnowledgeDocument::create([
            'project_key' => 'proj-mod',
            'source_path' => 'docs/dec.md',
            'source_type' => 'markdown',
            'title' => 'Decision v2',
            'mime_type' => 'text/markdown',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', 'v2'),
            'version_hash' => hash('sha256', 'v2'),
            'metadata' => [],
            'indexed_at' => now(),
        ]);

        $row = NotificationEvent::sole();
        $this->assertSame(NotificationEvent::EVENT_KB_DOC_MODIFIED, $row->event_type);
        $this->assertSame('modified', $row->payload['change'] ?? null);
    }

    public function test_kb_canonical_audit_promoted_fires_kb_canonical_promoted(): void
    {
        $subscriber = $this->makeUser('promo');
        NotificationPreference::create([
            'user_id' => $subscriber->id,
            'event_type' => NotificationEvent::EVENT_KB_CANONICAL_PROMOTED,
            'channel' => NotificationPreference::CHANNEL_IN_APP,
            'enabled' => true,
        ]);

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
    }

    public function test_audit_with_non_promoted_event_type_does_not_fire_notification(): void
    {
        $subscriber = $this->makeUser('noise');
        NotificationPreference::create([
            'user_id' => $subscriber->id,
            'event_type' => NotificationEvent::EVENT_KB_CANONICAL_PROMOTED,
            'channel' => NotificationPreference::CHANNEL_IN_APP,
            'enabled' => true,
        ]);

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

    public function test_no_subscribers_means_no_dispatcher_invocation(): void
    {
        // Sanity: hook fires unconditionally on create, but
        // resolveRecipients() returns [] → publisher early-exits
        // and no notification_events row is inserted.
        KnowledgeDocument::create([
            'project_key' => 'proj-empty',
            'source_path' => 'docs/empty.md',
            'source_type' => 'markdown',
            'title' => 'No Subscribers',
            'mime_type' => 'text/markdown',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', 'empty'),
            'version_hash' => hash('sha256', 'empty'),
            'metadata' => [],
            'indexed_at' => now(),
        ]);

        $this->assertSame(0, NotificationEvent::count());
    }

    private function makeUser(string $slug): User
    {
        return User::create([
            'name' => "publisher-user-{$slug}",
            'email' => "{$slug}-".uniqid('', true).'@test.local',
            'password' => Hash::make('secret123'),
        ]);
    }
}
