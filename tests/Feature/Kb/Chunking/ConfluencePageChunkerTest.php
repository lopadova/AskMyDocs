<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Chunking;

use App\Services\Kb\Chunkers\ConfluencePageChunker;
use App\Services\Kb\Pipeline\ConvertedDocument;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ConfluencePageChunkerTest extends TestCase
{
    #[Test]
    public function supports_only_confluence_source_type(): void
    {
        $chunker = new ConfluencePageChunker();
        $this->assertTrue($chunker->supports('confluence'));
        $this->assertFalse($chunker->supports('notion'));
        $this->assertFalse($chunker->supports('markdown'));
    }

    #[Test]
    public function emits_preamble_with_space_labels_and_version(): void
    {
        $chunker = new ConfluencePageChunker();
        $doc = new ConvertedDocument(
            markdown: "# Architecture decision\n\nWe will use Redis for caching.",
            mediaItems: [],
            extractionMeta: [
                'filename' => 'adr-001.confluence.md',
                'source_type' => 'confluence',
                'confluence' => [
                    'space_key' => 'ENGINEERING',
                    'labels' => ['architecture-decision', 'caching'],
                    'version' => 4,
                    'ancestor_titles' => ['Engineering', 'Architecture'],
                ],
                '_derived' => [
                    'search_tags' => ['architecture-decision', 'caching'],
                    'status_active' => true,
                    'recency_bucket' => 'this_week',
                ],
            ],
            sourceMimeType: 'application/vnd.confluence.page+json',
        );

        $drafts = $chunker->chunk($doc);
        $this->assertNotEmpty($drafts);
        $preamble = $drafts[0];
        $this->assertTrue($preamble->metadata['page_property_panel']);
        $this->assertSame('ENGINEERING', $preamble->metadata['space_key']);
        $this->assertStringContainsString('Space: ENGINEERING', $preamble->text);
        $this->assertStringContainsString('architecture-decision', $preamble->text);
        $this->assertStringContainsString('Version: 4', $preamble->text);
        $this->assertSame('Engineering > Architecture > Page properties', $preamble->headingPath);
    }

    #[Test]
    public function splits_body_on_heading_boundaries_and_prepends_ancestor_path(): void
    {
        $chunker = new ConfluencePageChunker();
        $markdown = "# Top\n\nIntro.\n\n## Detail A\n\nA body.\n\n## Detail B\n\nB body.\n";

        $doc = new ConvertedDocument(
            markdown: $markdown,
            mediaItems: [],
            extractionMeta: [
                'filename' => 'page.confluence.md',
                'source_type' => 'confluence',
                'confluence' => ['ancestor_titles' => ['Engineering']],
                '_derived' => ['search_tags' => []],
            ],
            sourceMimeType: 'application/vnd.confluence.page+json',
        );

        $drafts = $chunker->chunk($doc);

        // First body chunk under H1 "Top".
        $topChunk = $this->firstChunkWithHeading($drafts, 'Engineering > Top');
        $this->assertNotNull($topChunk);
        $this->assertStringContainsString('Intro.', $topChunk->text);

        // Detail A and Detail B both prefixed with ancestor.
        $a = $this->firstChunkWithHeading($drafts, 'Engineering > Top > Detail A');
        $b = $this->firstChunkWithHeading($drafts, 'Engineering > Top > Detail B');
        $this->assertNotNull($a, 'Detail A chunk missing');
        $this->assertNotNull($b, 'Detail B chunk missing');
        $this->assertStringContainsString('A body.', $a->text);
        $this->assertStringContainsString('B body.', $b->text);
    }

    #[Test]
    public function works_without_ancestor_titles(): void
    {
        $chunker = new ConfluencePageChunker();
        $doc = new ConvertedDocument(
            markdown: "# Solo page\n\nSolo body.\n",
            mediaItems: [],
            extractionMeta: [
                'filename' => 'solo.confluence.md',
                'source_type' => 'confluence',
                'confluence' => [],
                '_derived' => ['search_tags' => []],
            ],
            sourceMimeType: 'application/vnd.confluence.page+json',
        );

        $drafts = $chunker->chunk($doc);
        $body = $this->firstChunkWithHeading($drafts, 'Solo page');
        $this->assertNotNull($body);
        $this->assertStringContainsString('Solo body.', $body->text);
    }

    /**
     * @param  list<\App\Services\Kb\Pipeline\ChunkDraft>  $drafts
     */
    private function firstChunkWithHeading(array $drafts, string $heading): ?\App\Services\Kb\Pipeline\ChunkDraft
    {
        foreach ($drafts as $d) {
            if ($d->headingPath === $heading) {
                return $d;
            }
        }
        return null;
    }
}
