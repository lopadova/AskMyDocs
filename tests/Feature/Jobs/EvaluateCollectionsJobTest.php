<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Ai\EmbeddingsResponse;
use App\Jobs\EvaluateCollectionsJob;
use App\Models\KbCollection;
use App\Models\KbCollectionMember;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Kb\EmbeddingCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

final class EvaluateCollectionsJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    public function test_creates_static_match_membership_when_document_matches_collection_criteria(): void
    {
        $doc = KnowledgeDocument::query()->create([
            'tenant_id' => 'tenant-a',
            'project_key' => 'proj-a',
            'source_type' => 'markdown',
            'title' => 'Decision Cache',
            'source_path' => 'docs/dec-cache-v2.md',
            'mime_type' => 'text/markdown',
            'slug' => 'dec-cache-v2',
            'canonical_type' => 'decision',
            'frontmatter_json' => ['tags' => ['cache', 'infra']],
        ]);

        $collection = KbCollection::query()->create([
            'tenant_id' => 'tenant-a',
            'slug' => 'core-decisions',
            'name' => 'Core Decisions',
            'visibility' => 'private',
            'criteria' => [
                'projects' => ['proj-a'],
                'tags' => ['cache'],
                'canonical_types' => ['decision'],
                'slug_globs' => ['dec-*'],
            ],
            'threshold' => 0.75,
        ]);

        $this->app->call([new EvaluateCollectionsJob($doc->id, 'tenant-a'), 'handle']);

        $member = KbCollectionMember::query()->first();
        $this->assertNotNull($member);
        $this->assertSame('tenant-a', $member->tenant_id);
        $this->assertSame($collection->id, $member->collection_id);
        $this->assertSame($doc->id, $member->knowledge_document_id);
        $this->assertSame('static_match', $member->reason);
        $this->assertFalse($member->manually_excluded);
    }

    public function test_skips_non_matching_collections_and_respects_manual_exclusion(): void
    {
        $doc = KnowledgeDocument::query()->create([
            'tenant_id' => 'tenant-a',
            'project_key' => 'proj-a',
            'source_type' => 'markdown',
            'title' => 'Runbook',
            'source_path' => 'docs/runbook.md',
            'mime_type' => 'text/markdown',
            'slug' => 'runbook',
            'canonical_type' => 'runbook',
            'frontmatter_json' => ['tags' => ['ops']],
        ]);

        $nonMatching = KbCollection::query()->create([
            'tenant_id' => 'tenant-a',
            'slug' => 'decisions-only',
            'name' => 'Decisions Only',
            'visibility' => 'private',
            'criteria' => ['canonical_types' => ['decision']],
            'threshold' => 0.75,
        ]);

        $manualExcluded = KbCollection::query()->create([
            'tenant_id' => 'tenant-a',
            'slug' => 'ops-docs',
            'name' => 'Ops Docs',
            'visibility' => 'private',
            'criteria' => ['tags' => ['ops']],
            'threshold' => 0.75,
        ]);

        KbCollectionMember::query()->create([
            'tenant_id' => 'tenant-a',
            'collection_id' => $manualExcluded->id,
            'knowledge_document_id' => $doc->id,
            'reason' => 'manual',
            'manually_excluded' => true,
        ]);

        $this->app->call([new EvaluateCollectionsJob($doc->id, 'tenant-a'), 'handle']);

        $this->assertSame(1, KbCollectionMember::query()->count());
        $this->assertSame(0, KbCollectionMember::query()->where('collection_id', $nonMatching->id)->count());
        $this->assertTrue((bool) KbCollectionMember::query()->first()?->manually_excluded);
    }

    public function test_creates_semantic_match_membership_when_threshold_is_met(): void
    {
        $doc = KnowledgeDocument::query()->create([
            'tenant_id' => 'tenant-a',
            'project_key' => 'proj-a',
            'source_type' => 'markdown',
            'title' => 'Caching Guide',
            'source_path' => 'docs/caching.md',
            'mime_type' => 'text/markdown',
            'slug' => 'caching-guide',
            'canonical_type' => 'runbook',
            'frontmatter_json' => ['tags' => ['ops']],
        ]);

        KnowledgeChunk::query()->create([
            'tenant_id' => 'tenant-a',
            'knowledge_document_id' => $doc->id,
            'project_key' => 'proj-a',
            'chunk_order' => 1,
            'chunk_hash' => 'h1',
            'heading_path' => 'H1',
            'chunk_text' => 'Cache invalidation and TTL strategy.',
            'metadata' => [],
            'embedding' => [],
        ]);

        $collection = KbCollection::query()->create([
            'tenant_id' => 'tenant-a',
            'slug' => 'semantic-caching',
            'name' => 'Semantic Caching',
            'visibility' => 'private',
            'criteria' => ['projects' => ['other-project']],
            'semantic_prompt' => 'Documents about caching',
            'semantic_prompt_embedding' => [1.0, 0.0],
            'threshold' => 0.80,
        ]);

        $mock = Mockery::mock(EmbeddingCacheService::class);
        $mock->shouldReceive('generate')
            ->once()
            ->andReturn(new EmbeddingsResponse(
                embeddings: [[0.9, 0.1]],
                provider: 'fake',
                model: 'fake',
                totalTokens: null,
            ));
        $this->app->instance(EmbeddingCacheService::class, $mock);

        $this->app->call([new EvaluateCollectionsJob($doc->id, 'tenant-a'), 'handle']);

        $member = KbCollectionMember::query()->first();
        $this->assertNotNull($member);
        $this->assertSame($collection->id, $member->collection_id);
        $this->assertSame('semantic_match', $member->reason);
        $this->assertNotNull($member->semantic_score);
        $this->assertGreaterThanOrEqual(0.80, (float) $member->semantic_score);
    }

    public function test_dispatches_collection_new_member_notification_when_membership_is_newly_created(): void
    {
        $subscriber = User::query()->create([
            'tenant_id' => 'tenant-a',
            'name' => 'collection-subscriber',
            'email' => 'collection-subscriber@test.local',
            'password' => Hash::make('secret123'),
        ]);
        ProjectMembership::query()->create([
            'tenant_id' => 'tenant-a',
            'user_id' => $subscriber->id,
            'project_key' => 'proj-a',
            'role' => 'member',
            'scope_allowlist' => null,
        ]);
        NotificationPreference::query()->create([
            'tenant_id' => 'tenant-a',
            'user_id' => $subscriber->id,
            'event_type' => NotificationEvent::EVENT_COLLECTION_NEW_MEMBER,
            'channel' => NotificationPreference::CHANNEL_IN_APP,
            'enabled' => true,
        ]);

        $doc = KnowledgeDocument::query()->create([
            'tenant_id' => 'tenant-a',
            'project_key' => 'proj-a',
            'source_type' => 'markdown',
            'title' => 'Decision Cache',
            'source_path' => 'docs/dec-cache-v2.md',
            'mime_type' => 'text/markdown',
            'slug' => 'dec-cache-v2',
            'canonical_type' => 'decision',
            'frontmatter_json' => ['tags' => ['cache', 'infra']],
        ]);

        KbCollection::query()->create([
            'tenant_id' => 'tenant-a',
            'slug' => 'core-decisions',
            'name' => 'Core Decisions',
            'visibility' => 'private',
            'criteria' => [
                'projects' => ['proj-a'],
                'tags' => ['cache'],
                'canonical_types' => ['decision'],
                'slug_globs' => ['dec-*'],
            ],
            'threshold' => 0.75,
        ]);

        $this->app->call([new EvaluateCollectionsJob($doc->id, 'tenant-a'), 'handle']);

        $event = NotificationEvent::query()
            ->where('event_type', NotificationEvent::EVENT_COLLECTION_NEW_MEMBER)
            ->sole();
        $this->assertSame($subscriber->id, $event->user_id);
        $this->assertSame('Core Decisions', $event->payload['collection_name'] ?? null);
        $this->assertSame((int) $doc->id, $event->payload['doc_id'] ?? null);
        $this->assertSame('static_match', $event->payload['reason'] ?? null);
    }
}

