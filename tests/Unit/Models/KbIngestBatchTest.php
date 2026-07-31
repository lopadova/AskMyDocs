<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\KbIngestBatch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KbIngestBatchTest extends TestCase
{
    #[DataProvider('statuses')]
    public function test_every_declared_status_fits_the_database_column(string $status): void
    {
        self::assertLessThanOrEqual(
            KbIngestBatch::STATUS_COLUMN_LENGTH,
            strlen($status),
            "Batch status [{$status}] exceeds the database column width.",
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function statuses(): iterable
    {
        foreach (KbIngestBatch::STATUSES as $status) {
            yield $status => [$status];
        }
    }
}
