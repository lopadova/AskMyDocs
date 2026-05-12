<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Chunking;

use App\Services\Kb\Chunkers\OfficeDocChunker;
use App\Services\Kb\Pipeline\ConvertedDocument;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OfficeDocChunkerTest extends TestCase
{
    #[Test]
    public function supports_the_office_source_type_family(): void
    {
        $chunker = new OfficeDocChunker();
        $this->assertTrue($chunker->supports('drive_gdoc'));
        $this->assertTrue($chunker->supports('drive_gsheet'));
        $this->assertTrue($chunker->supports('drive_gslide'));
        $this->assertTrue($chunker->supports('onedrive_office'));
        $this->assertFalse($chunker->supports('notion'));
        $this->assertFalse($chunker->supports('markdown'));
    }

    #[Test]
    public function dispatches_gdoc_through_markdown_section_split(): void
    {
        $chunker = new OfficeDocChunker();
        $doc = new ConvertedDocument(
            markdown: "# Title\n\nIntro paragraph.\n\n## Section\n\nSection body.",
            mediaItems: [],
            extractionMeta: [
                'filename' => 'doc.gdoc.md',
                'source_type' => 'drive_gdoc',
                '_derived' => ['search_tags' => ['planning']],
            ],
            sourceMimeType: 'application/vnd.google-apps.document',
        );

        $drafts = $chunker->chunk($doc);
        $this->assertNotEmpty($drafts);
        $this->assertSame('office-doc-markdown', $drafts[0]->metadata['strategy']);
        $this->assertSame('drive_gdoc', $drafts[0]->metadata['source_type']);
        $this->assertSame(['planning'], $drafts[0]->metadata['search_tags']);
    }

    #[Test]
    public function dispatches_gsheet_through_row_window_chunking(): void
    {
        $chunker = new OfficeDocChunker();
        $rows = [
            ['name', 'role', 'team'],
            ['lorenzo', 'founder', 'padosoft'],
            ['claudia', 'pm', 'padosoft'],
            ['marco', 'eng', 'padosoft'],
        ];
        $doc = new ConvertedDocument(
            markdown: 'irrelevant',
            mediaItems: [],
            extractionMeta: [
                'filename' => 'team.gsheet',
                'source_type' => 'drive_gsheet',
                'rows' => $rows,
                '_derived' => ['search_tags' => []],
            ],
            sourceMimeType: 'application/vnd.google-apps.spreadsheet',
        );

        $drafts = $chunker->chunk($doc);
        $this->assertCount(1, $drafts);
        $this->assertStringContainsString('name', $drafts[0]->text);
        $this->assertStringContainsString('lorenzo', $drafts[0]->text);
        $this->assertStringContainsString('marco', $drafts[0]->text);
        $this->assertSame('office-doc-spreadsheet', $drafts[0]->metadata['strategy']);
        $this->assertSame('Rows 1-3', $drafts[0]->headingPath);
    }

    #[Test]
    public function defers_gslide_with_skip_reason_marker(): void
    {
        $chunker = new OfficeDocChunker();
        $doc = new ConvertedDocument(
            markdown: 'Slide body text.',
            mediaItems: [],
            extractionMeta: [
                'filename' => 'deck.gslide',
                'source_type' => 'drive_gslide',
                '_derived' => ['search_tags' => []],
            ],
            sourceMimeType: 'application/vnd.google-apps.presentation',
        );

        $drafts = $chunker->chunk($doc);
        $this->assertCount(1, $drafts);
        $this->assertSame('gslide-deferred', $drafts[0]->metadata['skip_reason']);
        $this->assertSame('drive_gslide', $drafts[0]->metadata['source_type']);
    }

    #[Test]
    public function dispatches_onedrive_office_through_markdown_section_split(): void
    {
        $chunker = new OfficeDocChunker();
        $doc = new ConvertedDocument(
            markdown: "# OneDrive doc\n\nBody.",
            mediaItems: [],
            extractionMeta: [
                'filename' => 'doc.office',
                'source_type' => 'onedrive_office',
                '_derived' => ['search_tags' => []],
            ],
            sourceMimeType: 'application/vnd.onedrive.office+json',
        );

        $drafts = $chunker->chunk($doc);
        $this->assertNotEmpty($drafts);
        $this->assertSame('onedrive_office', $drafts[0]->metadata['source_type']);
    }
}
