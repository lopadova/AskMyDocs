<?php

declare(strict_types=1);

namespace App\Agent\Tools;

/** Host-owned operational observations. Remote error prose is never evidence. */
final class AgentToolFailure
{
    public static function summarize(array $body, array $inputSchema = []): array
    {
        $status = $body['status'] ?? null;
        $status = is_int($status) && $status >= 100 && $status <= 599 ? $status : null;
        $formatMismatch = ($body['error_code'] ?? null) === 'api_response_format_mismatch'
            || ($body['error'] ?? null) === 'Endpoint returned a non-JSON response (unsupported in Fase 1).';

        $applicationError = in_array($body['error_code'] ?? null, ['api_parameter_rejected', 'api_application_error'], true);
        $failure = [
            'code' => $formatMismatch ? 'api_response_format_mismatch' : ($applicationError ? $body['error_code'] : 'tool_request_failed'),
            'http_status' => $status,
            'retryable' => ! $formatMismatch && ! $applicationError && ($status === null || $status === 429 || $status >= 500),
        ];
        $parameter = $body['invalid_parameter'] ?? null;
        if ($applicationError && $parameter === 'orderBy' && is_array($inputSchema['properties'][$parameter] ?? null)) {
            $failure['invalid_parameter'] = $parameter;
            // Correction choices come from the declared contract, never remote error prose.
            $allowed = $inputSchema['properties'][$parameter]['enum'] ?? [];
            if (is_array($allowed)) $failure['allowed_values'] = array_values($allowed);
        }

        return $failure;
    }
}
