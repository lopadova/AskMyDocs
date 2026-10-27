<?php

declare(strict_types=1);

namespace App\Agent\Tools;

/** Host-owned operational observations. Remote error prose is never evidence. */
final class AgentToolFailure
{
    public static function summarize(array $body): array
    {
        $status = $body['status'] ?? null;
        $status = is_int($status) && $status >= 100 && $status <= 599 ? $status : null;
        $formatMismatch = ($body['error_code'] ?? null) === 'api_response_format_mismatch'
            || ($body['error'] ?? null) === 'Endpoint returned a non-JSON response (unsupported in Fase 1).';

        return [
            'code' => $formatMismatch ? 'api_response_format_mismatch' : 'tool_request_failed',
            'http_status' => $status,
            'retryable' => ! $formatMismatch && ($status === null || $status === 429 || $status >= 500),
        ];
    }
}
