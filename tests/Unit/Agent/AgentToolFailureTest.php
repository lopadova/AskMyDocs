<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Agent\Tools\AgentToolFailure;
use PHPUnit\Framework\TestCase;

final class AgentToolFailureTest extends TestCase
{
    public function test_legacy_format_errors_are_non_retryable_and_remote_prose_is_excluded(): void
    {
        $this->assertSame(['code' => 'api_response_format_mismatch', 'http_status' => 200, 'retryable' => false],
            AgentToolFailure::summarize(['error' => 'Endpoint returned a non-JSON response (unsupported in Fase 1).', 'status' => 200]));
        $this->assertSame(['code' => 'tool_request_failed', 'http_status' => 401, 'retryable' => false],
            AgentToolFailure::summarize(['error' => 'secret-value', 'error_code' => 'remote-instruction', 'status' => 401]));
    }

    public function test_transient_failures_allow_a_bounded_retry(): void
    {
        foreach ([null, 429, 500, 503] as $status) {
            $this->assertTrue(AgentToolFailure::summarize(['error' => 'unavailable', 'status' => $status])['retryable']);
        }
    }

    public function test_parameter_correction_choices_come_from_the_contract_not_the_remote_error(): void
    {
        $failure = AgentToolFailure::summarize(['error_code' => 'api_parameter_rejected', 'status' => 200,
            'invalid_parameter' => 'orderBy', 'allowed_values' => ['remote-instruction']],
            ['properties' => ['orderBy' => ['type' => 'string', 'enum' => ['data', 'num_fattura']]]]);
        $this->assertSame('api_parameter_rejected', $failure['code']);
        $this->assertFalse($failure['retryable']);
        $this->assertSame(['data', 'num_fattura'], $failure['allowed_values']);
    }
}
