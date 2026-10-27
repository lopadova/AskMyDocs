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
}
