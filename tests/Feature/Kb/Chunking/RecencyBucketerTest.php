<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Chunking;

use App\Services\Kb\Chunking\Support\RecencyBucketer;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RecencyBucketerTest extends TestCase
{
    private function reference(): DateTimeImmutable
    {
        // Deterministic reference instant so the buckets don't drift with wall-clock.
        return new DateTimeImmutable('2026-05-11T12:00:00+00:00');
    }

    #[Test]
    public function buckets_within_the_last_seven_days_as_this_week(): void
    {
        $bucketer = new RecencyBucketer();
        $modified = '2026-05-09T12:00:00+00:00'; // 2 days ago

        $this->assertSame(RecencyBucketer::BUCKET_WEEK, $bucketer->bucket($modified, $this->reference()));
    }

    #[Test]
    public function buckets_within_the_last_thirty_days_as_this_month(): void
    {
        $bucketer = new RecencyBucketer();
        $modified = '2026-04-20T12:00:00+00:00'; // 21 days ago

        $this->assertSame(RecencyBucketer::BUCKET_MONTH, $bucketer->bucket($modified, $this->reference()));
    }

    #[Test]
    public function buckets_within_the_last_ninety_days_as_this_quarter(): void
    {
        $bucketer = new RecencyBucketer();
        $modified = '2026-03-01T12:00:00+00:00'; // ~71 days ago

        $this->assertSame(RecencyBucketer::BUCKET_QUARTER, $bucketer->bucket($modified, $this->reference()));
    }

    #[Test]
    public function buckets_anything_beyond_ninety_days_as_older(): void
    {
        $bucketer = new RecencyBucketer();
        $modified = '2025-11-01T12:00:00+00:00'; // ~190 days ago

        $this->assertSame(RecencyBucketer::BUCKET_OLDER, $bucketer->bucket($modified, $this->reference()));
    }

    #[Test]
    public function buckets_null_or_unparseable_as_older(): void
    {
        $bucketer = new RecencyBucketer();

        $this->assertSame(RecencyBucketer::BUCKET_OLDER, $bucketer->bucket(null, $this->reference()));
        $this->assertSame(RecencyBucketer::BUCKET_OLDER, $bucketer->bucket('not-a-date', $this->reference()));
        $this->assertSame(RecencyBucketer::BUCKET_OLDER, $bucketer->bucket('', $this->reference()));
    }

    #[Test]
    public function treats_future_dated_documents_as_this_week(): void
    {
        $bucketer = new RecencyBucketer();
        $modified = '2026-06-01T12:00:00+00:00'; // 21 days in the future

        $this->assertSame(RecencyBucketer::BUCKET_WEEK, $bucketer->bucket($modified, $this->reference()));
    }

    #[Test]
    public function accepts_datetime_objects_in_addition_to_strings(): void
    {
        $bucketer = new RecencyBucketer();
        $modified = new DateTimeImmutable('2026-05-05T00:00:00+00:00'); // 6 days ago

        $this->assertSame(RecencyBucketer::BUCKET_WEEK, $bucketer->bucket($modified, $this->reference()));
    }
}
