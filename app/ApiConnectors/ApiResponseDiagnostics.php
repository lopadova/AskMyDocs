<?php

declare(strict_types=1);

namespace App\ApiConnectors;

use DOMDocument;
use DOMElement;

/** Classify rejected responses without retaining remote prose or credentials. */
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

        $result = [
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
        $applicationError = $this->xmlApplicationError($body);
        if ($applicationError !== null) {
            $parameter = $applicationError['parameter'];
            $result['error'] = $parameter === null ? 'Endpoint returned an application error (ack=KO).'
                : 'Endpoint rejected parameter [orderBy]. Use a value declared by its input schema.';
            $result['error_code'] = $parameter === null ? 'api_application_error' : 'api_parameter_rejected';
            $result['response_diagnostics']['application_status'] = 'KO';
            if ($parameter !== null) $result['invalid_parameter'] = $parameter;
        }

        return $result;
    }

    /** This recognizes only an already rejected error envelope; it never accepts XML as data. */
    private function xmlApplicationError(string $body): ?array
    {
        if (! str_starts_with(ltrim($body), '<') || strlen($body) > 65536 || preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $body)) return null;
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadXML($body, LIBXML_NONET | LIBXML_NOCDATA)) return null;
            $root = $document->documentElement;
            if ($root?->nodeName !== 'result' || $root->childElementCount !== 2) return null;
            $fields = [];
            foreach ($root->childNodes as $child) {
                if (! $child instanceof DOMElement) continue;
                if (! in_array($child->nodeName, ['ack', 'error_message'], true) || isset($fields[$child->nodeName])
                    || $child->childElementCount !== 0) return null;
                $fields[$child->nodeName] = trim($child->textContent);
            }
            if (($fields['ack'] ?? null) !== 'KO' || ! isset($fields['error_message'])) return null;
            // Map the observed GESCAT validation message to host-owned facts.
            // Unknown error prose (including SQL, personal data or secrets) is discarded.
            return ['parameter' => str_starts_with($fields['error_message'], 'Il parametro "orderBy"') ? 'orderBy' : null];
        } catch (\Throwable) {
            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
