<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\EvaluateCollectionsJob;
use App\Models\KbCollection;
use App\Models\KbCollectionMember;
use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EvaluateCollectionsJobTest extends TestCase
{
    use RefreshDatabase;

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
}

