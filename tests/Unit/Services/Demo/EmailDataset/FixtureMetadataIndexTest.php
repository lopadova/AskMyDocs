<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Demo\EmailDataset;

use App\Services\Demo\EmailDataset\FixtureMetadataIndex;
use Tests\TestCase;

final class FixtureMetadataIndexTest extends TestCase
{
    public function test_content_checksum_canonicalizes_transport_line_endings(): void
    {
        $subject = 'Aggiornamento spedizione';
        $lfBody = "Prima riga\nSeconda riga\nTerza riga";

        $this->assertSame(
            FixtureMetadataIndex::contentChecksum($subject, $lfBody),
            FixtureMetadataIndex::contentChecksum(
                $subject,
                str_replace("\n", "\r\n", $lfBody),
            ),
        );
        $this->assertSame(
            FixtureMetadataIndex::contentChecksum($subject, $lfBody),
            FixtureMetadataIndex::contentChecksum(
                $subject,
                str_replace("\n", "\r", $lfBody),
            ),
        );
    }
}
