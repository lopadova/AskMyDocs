<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Retrieval;

use App\Services\Kb\Chunking\Support\RecencyBucketer;
use App\Services\Kb\Retrieval\RecencyScorer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RecencyScorerTest extends TestCase
{
    #[Test]
    public function week_scores_higher_than_month_higher_than_quarter_higher_than_older(): void
    {
        $scorer = new RecencyScorer();
        $week = $scorer->score(RecencyBucketer::BUCKET_WEEK);
        $month = $scorer->score(RecencyBucketer::BUCKET_MONTH);
        $quarter = $scorer->score(RecencyBucketer::BUCKET_QUARTER);
        $older = $scorer->score(RecencyBucketer::BUCKET_OLDER);

        $this->assertGreaterThan($month, $week);
        $this->assertGreaterThan($quarter, $month);
        $this->assertGreaterThan($older, $quarter);
        $this->assertSame(1.0, $week);
    }

    #[Test]
    public function null_bucket_returns_zero_so_legacy_chunks_take_no_boost(): void
    {
        $this->assertSame(0.0, (new RecencyScorer())->score(null));
    }

    #[Test]
    public function unknown_bucket_returns_zero(): void
    {
        $this->assertSame(0.0, (new RecencyScorer())->score('next_year'));
    }
}
