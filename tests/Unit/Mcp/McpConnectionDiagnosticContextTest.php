<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use App\Mcp\Diagnostics\McpConnectionDiagnosticContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class McpConnectionDiagnosticContextTest extends TestCase
{
    public function test_it_removes_credentials_and_query_parameters_from_urls(): void
    {
        $diagnostics = new McpConnectionDiagnosticContext;

        $this->assertSame(
            'https://example.test/mcp/tenant',
            $diagnostics->sanitizeUrl('https://user:secret@example.test/mcp/tenant?access_token=secret&state=oauth-state'),
        );
    }

    public function test_it_redacts_credentials_from_exception_details(): void
    {
        $diagnostics = new McpConnectionDiagnosticContext;

        $diagnostics->recordException(
            'POST_FORM',
            'https://example.test/oauth/token?code=secret-code',
            new RuntimeException('Bearer secret-bearer client_secret=secret-client&refresh_token=secret-refresh at https://user:pass@example.test/oauth/callback?code=secret-code'),
            18,
        );

        $attempt = $diagnostics->outboundAttempts()[0];

        $this->assertSame('POST', $attempt['method']);
        $this->assertSame('https://example.test/oauth/token', $attempt['url']);
        $this->assertSame(18, $attempt['duration_ms']);
        $this->assertStringNotContainsString('secret-bearer', $attempt['detail']);
        $this->assertStringNotContainsString('secret-client', $attempt['detail']);
        $this->assertStringNotContainsString('secret-refresh', $attempt['detail']);
        $this->assertStringNotContainsString('user:pass', $attempt['detail']);
        $this->assertStringNotContainsString('secret-code', $attempt['detail']);
        $this->assertSame(
            'Bearer [redacted] client_secret=[redacted]&refresh_token=[redacted] at https://example.test/oauth/callback',
            $attempt['detail'],
        );
    }
}
