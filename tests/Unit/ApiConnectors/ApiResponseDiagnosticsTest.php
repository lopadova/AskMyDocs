<?php

declare(strict_types=1);

namespace Tests\Unit\ApiConnectors;

use App\ApiConnectors\ApiResponseDiagnostics;
use PHPUnit\Framework\TestCase;

final class ApiResponseDiagnosticsTest extends TestCase
{
    public function test_format_diagnostics_never_include_response_text_or_header_parameters(): void
    {
        foreach (['<orders><token>secret-value</token></orders>' => 'xml',
            '<!DOCTYPE html><html>secret-value</html>' => 'html', '{"token":"secret-value"' => 'invalid_json',
            '' => 'empty', 'secret-value' => 'text'] as $body => $kind) {
            $result = (new ApiResponseDiagnostics)->formatMismatch(200, $body, 'Text/HTML; token=secret-value', null);
            $this->assertSame('api_response_format_mismatch', $result['error_code']);
            $this->assertFalse($result['retryable']);
            $this->assertSame(['expected_format' => 'json', 'detected_format' => $kind,
                'content_type' => 'text/html', 'body_bytes' => strlen($body)], $result['response_diagnostics']);
            $this->assertStringNotContainsString('secret-value', json_encode($result));
        }
    }

    public function test_configured_xml_and_untrusted_content_type_are_reported_safely(): void
    {
        $result = (new ApiResponseDiagnostics)->formatMismatch(200, '<bad/>', "text/xml\r\nsecret-value", ['response_format' => 'xml']);
        $this->assertSame('xml', $result['response_diagnostics']['expected_format']);
        $this->assertNull($result['response_diagnostics']['content_type']);
    }
}
