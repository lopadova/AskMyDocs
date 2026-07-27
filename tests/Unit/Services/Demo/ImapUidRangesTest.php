<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Demo;

use App\Services\Demo\ImapUidRanges;
use PHPUnit\Framework\TestCase;

final class ImapUidRangesTest extends TestCase
{
    public function test_compresses_only_consecutive_uids_without_spanning_gaps(): void
    {
        self::assertSame(
            [[4, 6], [9, 10], [14, 14]],
            ImapUidRanges::compress([10, 4, 5, 6, 9, 14, 10]),
        );
    }

    public function test_empty_page_produces_no_store_ranges(): void
    {
        self::assertSame([], ImapUidRanges::compress([]));
    }

    public function test_formats_ranges_as_an_imap_uid_sequence_set(): void
    {
        self::assertSame(
            '4:6,9:10,14',
            ImapUidRanges::sequenceSet([[4, 6], [9, 10], [14, 14]]),
        );
    }
}
