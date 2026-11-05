<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors;

use App\Connectors\Imap\Backfill\ImapBackfillFetchPlan;
use PHPUnit\Framework\TestCase;

final class ImapBackfillFetchPlanTest extends TestCase
{
    public function test_byte_and_count_budgets_preserve_order_and_isolate_large_messages(): void
    {
        $sizes = [1 => 3, 2 => 4, 3 => 5, 4 => 30, 5 => 2, 6 => 2, 7 => 2];

        $this->assertSame([[1, 2], [3], [4], [5, 6], [7]],
            ImapBackfillFetchPlan::chunks(range(1, 7), $sizes, 2, 8));
    }

    public function test_missing_or_invalid_metadata_never_combines_unknown_messages(): void
    {
        $this->assertSame([[1], [2], [3], [4]],
            ImapBackfillFetchPlan::chunks([1, 2, 3, 4], [1 => 3, 3 => -1], 50, 8));
        $this->assertSame([[1], [2]], ImapBackfillFetchPlan::chunks([1, 2], null, 50, 8));
    }

    public function test_empty_plan_fetches_nothing(): void
    {
        $this->assertSame([], ImapBackfillFetchPlan::chunks([], [], 5, 8));
    }
}
