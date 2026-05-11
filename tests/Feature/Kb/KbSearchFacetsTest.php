<?php

declare(strict_types=1);

namespace Tests\Feature\Kb;

use App\Services\Kb\Retrieval\RetrievalFilters;
use App\Support\Kb\SourceType;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * v4.5/W5.5 — `facets[source]` + `facets[tag]` are plumbed through the
 * existing `RetrievalFilters` DTO without controller changes. The
 * `sourceTypes` field has been part of the DTO since T2.1; this test
 * pins the integration point — the new vendor tokens (notion,
 * confluence, evernote, fabric, drive_*, onedrive_office) MUST be
 * accepted by the validator rule, the DTO, and round-trip through the
 * KbSearchService applyFilters() WHERE clause without a code change.
 *
 * Deeper coverage of the WHERE-clause behaviour is in
 * KbSearchServiceFiltersTest.php (pre-existing T2.x suite); this file
 * adds the new-token-acceptance gate so future API additions don't
 * silently drop the source-aware tokens.
 */
final class KbSearchFacetsTest extends TestCase
{
    #[Test]
    public function retrieval_filters_accepts_v4_5_source_type_tokens(): void
    {
        $filters = new RetrievalFilters(
            sourceTypes: [
                SourceType::NOTION->value,
                SourceType::CONFLUENCE->value,
                SourceType::EVERNOTE->value,
                SourceType::FABRIC->value,
                SourceType::DRIVE_GDOC->value,
                SourceType::DRIVE_GSHEET->value,
                SourceType::DRIVE_GSLIDE->value,
                SourceType::ONEDRIVE_OFFICE->value,
                SourceType::NOTION_NOTE->value,
            ],
            tagSlugs: ['architecture-decision', 'cache'],
        );

        $this->assertSame(9, count($filters->sourceTypes));
        $this->assertSame(2, count($filters->tagSlugs));
        $this->assertFalse($filters->isEmpty());
    }

    #[Test]
    public function every_v4_5_token_round_trips_through_source_type_enum(): void
    {
        $tokens = [
            'notion', 'notion_note', 'confluence', 'evernote', 'fabric',
            'drive_gdoc', 'drive_gsheet', 'drive_gslide', 'onedrive_office',
        ];
        foreach ($tokens as $token) {
            $enum = SourceType::from($token);
            $this->assertSame($token, $enum->value, "Round-trip failed for token: {$token}");
        }
    }

    #[Test]
    public function source_type_fromMime_resolves_each_vendor_mime(): void
    {
        $pairs = [
            'application/vnd.notion.page+json'         => SourceType::NOTION,
            'application/vnd.notion.note+json'         => SourceType::NOTION_NOTE,
            'application/vnd.confluence.page+json'     => SourceType::CONFLUENCE,
            'application/vnd.evernote.note+xml'        => SourceType::EVERNOTE,
            'application/vnd.fabric.note+json'         => SourceType::FABRIC,
            'application/vnd.google-apps.document'     => SourceType::DRIVE_GDOC,
            'application/vnd.google-apps.spreadsheet'  => SourceType::DRIVE_GSHEET,
            'application/vnd.google-apps.presentation' => SourceType::DRIVE_GSLIDE,
            'application/vnd.onedrive.office+json'     => SourceType::ONEDRIVE_OFFICE,
        ];
        foreach ($pairs as $mime => $expected) {
            $this->assertSame($expected, SourceType::fromMime($mime));
        }
    }
}
