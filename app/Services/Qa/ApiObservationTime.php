<?php

declare(strict_types=1);

namespace App\Services\Qa;

use Carbon\CarbonImmutable;

/** Preserve the writer's timezone when offline QA reads a naive SQL timestamp. */
final class ApiObservationTime
{
    public static function resolve(int $executionId, array $evidence, ?string $rawTimestamp, string $runTimezone): string
    {
        foreach ($evidence['api_tools'] ?? [] as $tool) {
            if (($tool['execution_id'] ?? null) === $executionId) {
                $value = $tool['retrieved_at'] ?? null;
                if (is_string($value) && preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
                    // Validate the durable offset without converting to the
                    // evaluator process's default timezone.
                    CarbonImmutable::parse($value);

                    return $value;
                }
            }
        }
        if ($rawTimestamp === null || trim($rawTimestamp) === '' || $runTimezone === '') {
            throw new \UnexpectedValueException('API observation time is unavailable.');
        }

        return CarbonImmutable::parse($rawTimestamp, new \DateTimeZone($runTimezone))->toIso8601String();
    }
}
