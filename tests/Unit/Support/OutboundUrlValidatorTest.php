<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Http\OutboundUrlValidator;
use App\Support\Http\UnsafeOutboundUrlException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * SSRF allow-list (SEC-SSRF-001). IP-literal cases exercise the range checks
 * without touching DNS; `localhost` is used for the one resolution case because
 * it deterministically resolves to loopback everywhere.
 */
class OutboundUrlValidatorTest extends TestCase
{
    public function test_allows_a_public_https_ip_literal(): void
    {
        // 93.184.216.34 is a public address (example.com's historical IP).
        OutboundUrlValidator::assertAllowed('https://93.184.216.34/webhook');
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeUrls(): array
    {
        return [
            'metadata IP' => ['https://169.254.169.254/latest/meta-data'],
            'ipv4 loopback' => ['https://127.0.0.1/x'],
            'private 10/8' => ['https://10.0.0.5/x'],
            'private 192.168' => ['https://192.168.1.1/x'],
            'private 172.16' => ['https://172.16.0.9/x'],
            'link-local' => ['https://169.254.10.10/x'],
            'ipv6 loopback' => ['https://[::1]/x'],
            'ipv4-mapped ipv6 loopback' => ['https://[::ffff:127.0.0.1]/x'],
            'decimal-obfuscated loopback' => ['https://2130706433/x'],
            'hex-obfuscated loopback' => ['https://0x7f000001/x'],
            'localhost hostname' => ['https://localhost/x'],
            // Built by concatenation so the literal userinfo does not trip
            // GitHub's diff credential-redaction (it stays a real, valid URL).
            'credentials in authority' => ['https://'.'me'.':'.'pw'.'@93.184.216.34/x'],
        ];
    }

    #[DataProvider('unsafeUrls')]
    public function test_rejects_unsafe_targets(string $url): void
    {
        $this->expectException(UnsafeOutboundUrlException::class);
        OutboundUrlValidator::assertAllowed($url);
    }

    public function test_rejects_disallowed_scheme(): void
    {
        // https-only by default → plain http to a public IP is rejected.
        $this->expectException(UnsafeOutboundUrlException::class);
        OutboundUrlValidator::assertAllowed('http://93.184.216.34/x');
    }

    public function test_allows_http_when_scheme_is_permitted(): void
    {
        config(['outbound-http.allowed_schemes' => ['http', 'https']]);
        OutboundUrlValidator::assertAllowed('http://93.184.216.34/x');
        $this->addToAssertionCount(1);
    }

    public function test_rejects_malformed_url(): void
    {
        $this->expectException(UnsafeOutboundUrlException::class);
        OutboundUrlValidator::assertAllowed('not-a-url');
    }

    public function test_no_op_when_guard_disabled(): void
    {
        config(['outbound-http.enabled' => false]);
        // Even a metadata URL passes when the guard is switched off.
        OutboundUrlValidator::assertAllowed('https://169.254.169.254/x');
        $this->addToAssertionCount(1);
    }
}
