<?php

declare(strict_types=1);

namespace App\ApiConnectors;

/** Response shape diagnostics without retaining remote text, headers or secrets. */
final class ApiResponseDiagnostics
{
    public function formatMismatch(int $status, string $body, ?string $contentType, ?array $options): array
    {
        $prefix = ltrim(substr($body, 0, 512), "\xEF\xBB\xBF \t\n\r\0\x0B");
        $format = match (true) {
            $prefix === '' => 'empty',
            preg_match('/^(?:<!doctype\s+html\b|<html\b)/i', $prefix) === 1 => 'html',
            str_starts_with($prefix, '<') => 'xml',
            str_starts_with($prefix, '{'), str_starts_with($prefix, '[') => 'invalid_json',
            default => 'text',
        };
        $mediaType = strtolower(trim(explode(';', $contentType ?? '')[0]));
        if (preg_match('~^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+$~D', $mediaType) !== 1 || strlen($mediaType) > 100) {
            $mediaType = null;
        }

        return [
            'error' => 'Endpoint response does not match the configured structured format.',
            'error_code' => 'api_response_format_mismatch',
            'status' => $status,
            'retryable' => false,
            'response_diagnostics' => [
                'expected_format' => ($options['response_format'] ?? null) === 'xml' ? 'xml' : 'json',
                'detected_format' => $format,
                'content_type' => $mediaType,
                'body_bytes' => strlen($body),
            ],
        ];
    }
}
