<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Chunking;

use App\Services\Kb\Chunkers\NotionBlockChunker;
use App\Services\Kb\Pipeline\ConvertedDocument;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class NotionBlockChunkerTest extends TestCase
{
    #[Test]
    public function supports_only_notion_source_type(): void
    {
        $chunker = new NotionBlockChunker();

        $this->assertTrue($chunker->supports('notion'));
        $this->assertFalse($chunker->supports('confluence'));
        $this->assertFalse($chunker->supports('markdown'));
        $this->assertFalse($chunker->supports('pdf'));
    }

    #[Test]
    public function emits_synthetic_preamble_when_property_panel_present(): void
    {
        $chunker = new NotionBlockChunker();
        $doc = new ConvertedDocument(
            markdown: "# Cache eviction policy\n\nThe LRU cache should evict the oldest entries first.",
            mediaItems: [],
            extractionMeta: [
                'filename' => 'cache-policy.notion.md',
                'source_type' => 'notion',
                'notion' => [
                    'properties' => [
                        'status' => 'In Progress',
                        'owner' => 'lorenzo@padosoft.com',
                    ],
                ],
                '_derived' => [
                    'search_tags' => ['decision', 'cache'],
                    'status_active' => true,
                    'recency_bucket' => 'this_week',
                ],
            ],
            sourceMimeType: 'application/vnd.notion.page+json',
        );

        $drafts = $chunker->chunk($doc);

        $this->assertNotEmpty($drafts);
        $preamble = $drafts[0];
        $this->assertTrue($preamble->metadata['page_property_panel']);
        $this->assertSame('Page properties', $preamble->headingPath);
        $this->assertStringContainsString('status: In Progress', $preamble->text);
        $this->assertSame(['decision', 'cache'], $preamble->metadata['search_tags']);
        $this->assertTrue($preamble->metadata['status_active']);
        $this->assertSame('this_week', $preamble->metadata['recency_bucket']);

        // Body chunk follows the preamble.
        $body = $drafts[1];
        $this->assertFalse($body->metadata['page_property_panel']);
        $this->assertStringContainsString('LRU cache', $body->text);
        $this->assertSame('Cache eviction policy', $body->headingPath);
    }

    #[Test]
    public function omits_preamble_when_no_property_panel(): void
    {
        $chunker = new NotionBlockChunker();
        $doc = new ConvertedDocument(
            markdown: "Plain note body without properties.",
            mediaItems: [],
            extractionMeta: [
                'filename' => 'note.notion.md',
                'source_type' => 'notion',
                '_derived' => ['search_tags' => []],
            ],
            sourceMimeType: 'application/vnd.notion.page+json',
        );

        $drafts = $chunker->chunk($doc);
        $this->assertCount(1, $drafts);
        $this->assertFalse($drafts[0]->metadata['page_property_panel']);
    }

    #[Test]
    public function carries_search_tags_and_status_active_on_every_body_chunk(): void
    {
        $chunker = new NotionBlockChunker();
        $doc = new ConvertedDocument(
            markdown: "# Heading\n\nBody text.",
            mediaItems: [],
            extractionMeta: [
                'filename' => 'doc.notion.md',
                'source_type' => 'notion',
                '_derived' => [
                    'search_tags' => ['architecture'],
                    'status_active' => false,
                    'recency_bucket' => 'this_month',
                ],
            ],
            sourceMimeType: 'application/vnd.notion.page+json',
        );

        $drafts = $chunker->chunk($doc);
        $this->assertNotEmpty($drafts);
        foreach ($drafts as $draft) {
            $this->assertSame(['architecture'], $draft->metadata['search_tags']);
            $this->assertFalse($draft->metadata['status_active']);
            $this->assertSame('this_month', $draft->metadata['recency_bucket']);
            $this->assertSame('notion', $draft->metadata['source_type']);
        }
    }
}
