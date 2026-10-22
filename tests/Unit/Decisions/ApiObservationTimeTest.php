<?php

namespace Tests\Unit\Decisions;

use App\Services\Qa\ApiObservationTime;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApiObservationTimeTest extends TestCase
{
    public static function clocks(): array
    {
        return ['summer' => ['2026-10-22 00:49:56', '2026-10-22T00:49:56+02:00'],
            'winter' => ['2026-12-22 00:49:56', '2026-12-22T00:49:56+01:00']];
    }

    #[DataProvider('clocks')]
    public function test_naive_timestamp_uses_run_timezone_instead_of_evaluator_timezone(string $raw, string $expected): void
    {
        $previous = date_default_timezone_get();
        try {
            date_default_timezone_set('UTC');
            $this->assertSame($expected, ApiObservationTime::resolve(7, [], $raw, 'Europe/Rome'));
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function test_durable_evidence_offset_takes_priority_over_naive_sql_casts(): void
    {
        $time = '2026-10-22T00:49:56+02:00';
        $this->assertSame($time, ApiObservationTime::resolve(7, ['api_tools' => [
            ['execution_id' => 8, 'retrieved_at' => '2026-10-23T12:00:00Z'],
            ['execution_id' => 7, 'retrieved_at' => $time],
        ]], '2026-10-22 00:49:56', 'UTC'));
    }

    public function test_missing_time_cannot_be_replaced_with_the_evaluation_time(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        ApiObservationTime::resolve(7, [], null, 'Europe/Rome');
    }
}
