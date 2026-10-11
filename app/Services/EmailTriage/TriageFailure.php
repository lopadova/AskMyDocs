<?php

declare(strict_types=1);

namespace App\Services\EmailTriage;

use App\Decisions\DecisionException;

/** Persist useful diagnostics without copying exception payloads or source text. */
final class TriageFailure
{
    public static function code(\Throwable $error): string
    {
        if ($error instanceof DecisionException) {
            if (preg_match('/returned HTTP (\d{3})/', $error->getMessage(), $match)) {
                return 'decision_http_'.$match[1];
            }
            return match ($error->getMessage()) {
                'OpenRouter API key is not configured.' => 'decision_credentials_missing',
                'Decision provider is unavailable.' => 'decision_connection_or_timeout',
                default => 'decision_invalid_response_or_configuration',
            };
        }
        return match ($error->getMessage()) {
            'The pinned triage model changed. Save a new profile before evaluating.' => 'decision_model_changed',
            'The immutable email original is missing or corrupt.' => 'email_original_missing_or_corrupt',
            'missing_complete_email_source' => 'email_complete_source_unavailable',
            'Cannot preserve the historical email.' => 'email_historical_snapshot_failed',
            'The historical email snapshot is corrupt.' => 'email_historical_snapshot_corrupt',
            default => class_basename($error),
        };
    }
}
