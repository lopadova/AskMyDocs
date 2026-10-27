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

    public function test_gescat_xml_validation_error_is_not_reported_as_a_json_format_problem(): void
    {
        $body = '<result><ack>KO</ack><error_message><![CDATA[Il parametro "orderBy" impone l\'Ordinamento dati. Valori accettati: "num_fattura"]]></error_message></result>';
        $result = (new ApiResponseDiagnostics)->formatMismatch(200, $body, 'text/xml; charset=utf-8', null);
        $this->assertSame('api_parameter_rejected', $result['error_code']);
        $this->assertSame('orderBy', $result['invalid_parameter']);
        $this->assertSame('KO', $result['response_diagnostics']['application_status']);
        $this->assertFalse($result['retryable']);
        $this->assertStringContainsString('parameter [orderBy]', $result['error']);
        $this->assertStringNotContainsString('Ordinamento dati', json_encode($result));
    }

    public function test_other_application_errors_do_not_retain_remote_prose_and_unsafe_xml_is_rejected(): void
    {
        $diagnostics = new ApiResponseDiagnostics;
        $body = '<result><ack>KO</ack><error_message>SQL failure secret-value customer@example.invalid</error_message></result>';
        $result = $diagnostics->formatMismatch(200, $body, 'text/xml', null);
        $this->assertSame('api_application_error', $result['error_code']);
        $this->assertStringNotContainsString('secret-value', json_encode($result));
        foreach (['<result><ack>OK</ack><error_message>Success</error_message></result>',
            '<result><ack>KO</ack><error_message>Bad</error_message><order/></result>',
            '<!DOCTYPE result [<!ENTITY x SYSTEM "file:///etc/passwd">]><result><ack>KO</ack><error_message>&x;</error_message></result>'] as $xml) {
            $this->assertSame('api_response_format_mismatch', $diagnostics->formatMismatch(200, $xml, 'text/xml', null)['error_code']);
        }
    }
}
