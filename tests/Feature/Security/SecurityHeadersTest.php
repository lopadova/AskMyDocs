<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Locks the security response-header contract (SEC-CSP-001, SEC-TLS-001,
 * response-headers, csp-nonce-cache, request-correlation). Both states of every
 * flag are exercised (R43): headers present when enabled, absent when disabled;
 * CSP on HTML but not JSON; HSTS only over HTTPS in a production-class env.
 */
class SecurityHeadersTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        // Load the real host routes (web.php provides /csp-report). Testbench
        // does NOT apply the host bootstrap/app.php global middleware stack, so
        // the SecurityHeaders middleware is attached explicitly to the probe
        // routes below — mirroring how the repo's tenant tests attach
        // ResolveTenant. The production wiring is the global append in
        // bootstrap/app.php.
        parent::defineRoutes($router);

        $router->middleware(\App\Http\Middleware\SecurityHeaders::class)->group(function ($router): void {
            $router->get('/sec-html', fn () => response('<!doctype html><html><body>ok</body></html>', 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ]));
            $router->get('/sec-json', fn () => response()->json(['ok' => true]));
        });
    }

    public function test_baseline_headers_present_on_html_response(): void
    {
        $response = $this->get('/sec-html');

        $response->assertStatus(200);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertNotEmpty($response->headers->get('Permissions-Policy'));
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }

    public function test_csp_report_only_header_carries_a_nonce_on_html(): void
    {
        $response = $this->get('/sec-html');

        $policy = $response->headers->get('Content-Security-Policy-Report-Only');
        $this->assertNotNull($policy, 'CSP report-only header must be present on HTML');
        $this->assertStringContainsString("script-src 'self' 'nonce-", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        // report-only by default — the enforcing header must be absent.
        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }

    public function test_csp_is_absent_on_json_but_baseline_headers_remain(): void
    {
        $response = $this->get('/sec-json');

        $this->assertNull($response->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertNull($response->headers->get('Content-Security-Policy'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_csp_nonce_is_fresh_per_request(): void
    {
        $first = $this->extractNonce($this->get('/sec-html')->headers->get('Content-Security-Policy-Report-Only'));
        $second = $this->extractNonce($this->get('/sec-html')->headers->get('Content-Security-Policy-Report-Only'));

        $this->assertNotSame('', $first);
        $this->assertNotSame($first, $second, 'each response must mint a fresh CSP nonce');
    }

    public function test_csp_enforces_when_report_only_is_disabled(): void
    {
        config(['security-headers.csp.report_only' => false]);

        $response = $this->get('/sec-html');

        $this->assertNotNull($response->headers->get('Content-Security-Policy'));
        $this->assertNull($response->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_hsts_absent_over_plain_http(): void
    {
        config(['security-headers.hsts.environments' => ['testing']]);

        $response = $this->get('http://localhost/sec-html');

        $this->assertNull($response->headers->get('Strict-Transport-Security'));
    }

    public function test_hsts_present_over_https_in_configured_environment(): void
    {
        config(['security-headers.hsts.environments' => ['testing']]);

        $response = $this->get('https://localhost/sec-html');

        $hsts = $response->headers->get('Strict-Transport-Security');
        $this->assertNotNull($hsts);
        $this->assertStringContainsString('max-age=', $hsts);
        $this->assertStringContainsString('includeSubDomains', $hsts);
    }

    public function test_hsts_absent_over_https_when_environment_not_listed(): void
    {
        config(['security-headers.hsts.environments' => ['production']]);

        $response = $this->get('https://localhost/sec-html');

        $this->assertNull($response->headers->get('Strict-Transport-Security'));
    }

    public function test_disabled_master_flag_emits_no_security_headers(): void
    {
        config(['security-headers.enabled' => false]);

        $response = $this->get('/sec-html');

        $response->assertStatus(200);
        $this->assertNull($response->headers->get('X-Content-Type-Options'));
        $this->assertNull($response->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertNull($response->headers->get('X-Request-Id'));
    }

    public function test_inbound_request_id_is_reused_when_well_formed(): void
    {
        $response = $this->get('/sec-html', ['X-Request-Id' => 'abc-123_DEF.456']);

        $response->assertHeader('X-Request-Id', 'abc-123_DEF.456');
    }

    public function test_malformed_inbound_request_id_is_replaced(): void
    {
        $response = $this->get('/sec-html', ['X-Request-Id' => "bad\r\ninjection value"]);

        $this->assertNotSame("bad\r\ninjection value", $response->headers->get('X-Request-Id'));
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }

    public function test_csp_report_endpoint_accepts_bounded_report(): void
    {
        $response = $this->postJson('/csp-report', [
            'csp-report' => ['blocked-uri' => 'https://evil.example', 'violated-directive' => 'script-src'],
        ]);

        $response->assertNoContent();
    }

    public function test_csp_report_endpoint_is_inert_when_report_uri_unset(): void
    {
        config(['security-headers.csp.report_uri' => null]);

        $response = $this->postJson('/csp-report', ['csp-report' => ['blocked-uri' => 'x']]);

        $response->assertStatus(404);
    }

    private function extractNonce(?string $policy): string
    {
        if ($policy === null) {
            return '';
        }
        if (preg_match("/'nonce-([^']+)'/", $policy, $m) === 1) {
            return $m[1];
        }

        return '';
    }
}
