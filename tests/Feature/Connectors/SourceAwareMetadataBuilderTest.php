<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Support\SourceAwareMetadataBuilder;
use App\Connectors\Support\VendorMimeSelector;
use App\Services\Kb\Chunking\Support\RecencyBucketer;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pins the v4.5/W5.5 connector → IngestDocumentJob handshake.
 *
 * Connectors call SourceAwareMetadataBuilder::build() to assemble the
 * `metadata` payload they pass to IngestDocumentJob::dispatch(). The
 * payload MUST carry:
 *  - the connector's own base metadata (connector key, installation_id, ...)
 *  - a `converter_hints.<source>` bag with the raw vendor-shaped fields
 *  - a `converter_hints._derived` map with normalised reranker signals
 *  - the same `_derived` map flat-projected at the top level
 *
 * The flat `_derived` slot is the contract DocumentIngestor uses; the
 * `converter_hints` wrapper is the contract the source-aware chunkers
 * read via DerivedMetadataReader. Both must always agree.
 */
final class SourceAwareMetadataBuilderTest extends TestCase
{
    #[Test]
    public function builds_the_namespaced_hints_and_flat_derived_block(): void
    {
        $builder = new SourceAwareMetadataBuilder();
        $meta = $builder->build(
            base: ['connector' => 'notion', 'installation_id' => 7],
            sourceKey: 'notion',
            sourceFields: [
                'database_id' => 'db-1',
                'properties' => ['status' => 'In Progress'],
            ],
            tags: ['decision', 'architecture'],
            statusActive: true,
            lastModified: '2026-05-09T12:00:00+00:00',
            owner: 'lorenzo@padosoft.com',
        );

        $this->assertSame('notion', $meta['connector']);
        $this->assertSame(7, $meta['installation_id']);

        $this->assertArrayHasKey('converter_hints', $meta);
        $this->assertSame('db-1', $meta['converter_hints']['notion']['database_id']);
        $this->assertSame('In Progress', $meta['converter_hints']['notion']['properties']['status']);

        $derived = $meta['converter_hints']['_derived'];
        $this->assertSame(['decision', 'architecture'], $derived['search_tags']);
        $this->assertTrue($derived['status_active']);
        $this->assertSame('lorenzo@padosoft.com', $derived['owner']);
        $this->assertContains($derived['recency_bucket'], RecencyBucketer::ALL_BUCKETS);

        // The flat top-level `_derived` matches the wrapped one exactly.
        $this->assertSame($derived, $meta['_derived']);
    }

    #[Test]
    public function recency_bucket_is_computed_from_last_modified(): void
    {
        $builder = new SourceAwareMetadataBuilder();
        $meta = $builder->build(
            base: [],
            sourceKey: 'evernote',
            sourceFields: [],
            tags: [],
            statusActive: null,
            lastModified: '2025-01-01T00:00:00+00:00',
        );
        $this->assertSame('older', $meta['_derived']['recency_bucket']);
    }

    #[Test]
    public function deduplicates_and_trims_tags(): void
    {
        $builder = new SourceAwareMetadataBuilder();
        $meta = $builder->build(
            base: [],
            sourceKey: 'evernote',
            sourceFields: [],
            tags: ['inbox', '  inbox  ', '', 'capture', 'capture'],
        );
        $this->assertSame(['inbox', 'capture'], $meta['_derived']['search_tags']);
    }

    #[Test]
    public function null_status_active_degrades_to_false(): void
    {
        $builder = new SourceAwareMetadataBuilder();
        $meta = $builder->build(
            base: [],
            sourceKey: 'fabric',
            sourceFields: [],
            statusActive: null,
        );
        $this->assertFalse($meta['_derived']['status_active']);
    }

    #[Test]
    public function vendor_mime_selector_routes_office_family_to_vendor_mime(): void
    {
        $this->assertSame(
            VendorMimeSelector::MIME_DRIVE_GDOC,
            VendorMimeSelector::forGoogleDrive('application/vnd.google-apps.document'),
        );
        $this->assertSame(
            VendorMimeSelector::MIME_DRIVE_GSHEET,
            VendorMimeSelector::forGoogleDrive('application/vnd.google-apps.spreadsheet'),
        );
        $this->assertSame(
            VendorMimeSelector::MIME_DRIVE_GSLIDE,
            VendorMimeSelector::forGoogleDrive('application/vnd.google-apps.presentation'),
        );
        // Non-Office Drive content falls back to plain markdown.
        $this->assertSame(
            VendorMimeSelector::MIME_GENERIC_MARKDOWN,
            VendorMimeSelector::forGoogleDrive('text/markdown'),
        );
        $this->assertSame(
            VendorMimeSelector::MIME_GENERIC_PDF,
            VendorMimeSelector::forGoogleDrive('application/pdf'),
        );
    }

    #[Test]
    public function vendor_mime_selector_routes_onedrive_office_correctly(): void
    {
        $this->assertSame(
            VendorMimeSelector::MIME_ONEDRIVE_OFFICE,
            VendorMimeSelector::forOneDrive('application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
        );
        $this->assertSame(
            VendorMimeSelector::MIME_ONEDRIVE_OFFICE,
            VendorMimeSelector::forOneDrive('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        );
        $this->assertSame(
            VendorMimeSelector::MIME_ONEDRIVE_OFFICE,
            VendorMimeSelector::forOneDrive('application/msword'),
        );
        $this->assertSame(
            VendorMimeSelector::MIME_GENERIC_PDF,
            VendorMimeSelector::forOneDrive('application/pdf'),
        );
        $this->assertSame(
            VendorMimeSelector::MIME_GENERIC_MARKDOWN,
            VendorMimeSelector::forOneDrive('text/markdown'),
        );
        $this->assertSame(
            VendorMimeSelector::MIME_GENERIC_MARKDOWN,
            VendorMimeSelector::forOneDrive(null),
        );
    }
}
