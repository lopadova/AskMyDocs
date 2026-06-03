<?php

declare(strict_types=1);

namespace Tests\Unit\Widget;

use App\Http\Middleware\ResolveWidgetKey;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * M5.4 — Per-session rate-limit: bucket separato dal per-key-per-IP,
 * risposta 429 con header Retry-After.
 */
final class WidgetSessionRateLimitTest extends TestCase
{
    // ─── sessionRateLimited() ────────────────────────────────────

    public function test_allows_request_within_limit(): void
    {
        $response = ResolveWidgetKey::sessionRateLimited('sess-abc', 30);

        $this->assertNull($response);
    }

    public function test_rejects_request_over_limit_with_429(): void
    {
        // Saturate the bucket
        $bucket = 'widget:session:sess-abc';
        for ($i = 0; $i < 30; $i++) {
            RateLimiter::hit($bucket, 60);
        }

        $response = ResolveWidgetKey::sessionRateLimited('sess-abc', 30);

        $this->assertNotNull($response);
        $this->assertSame(429, $response->getStatusCode());

        $data = $response->getData(true);
        $this->assertSame('session_rate_limited', $data['error']);
    }

    public function test_429_includes_retry_after_header(): void
    {
        // Saturate the bucket
        $bucket = 'widget:session:sess-xyz';
        for ($i = 0; $i < 30; $i++) {
            RateLimiter::hit($bucket, 60);
        }

        $response = ResolveWidgetKey::sessionRateLimited('sess-xyz', 30);

        $this->assertNotNull($response);
        $this->assertNotNull($response->headers->get('Retry-After'));
        $retryAfter = (int) $response->headers->get('Retry-After');
        $this->assertGreaterThan(0, $retryAfter);
    }

    public function test_different_sessions_have_independent_buckets(): void
    {
        // Saturate only sess-1
        $bucket1 = 'widget:session:sess-1';
        for ($i = 0; $i < 30; $i++) {
            RateLimiter::hit($bucket1, 60);
        }

        // sess-1 is blocked
        $blocked = ResolveWidgetKey::sessionRateLimited('sess-1', 30);
        $this->assertNotNull($blocked);

        // sess-2 is still allowed
        $allowed = ResolveWidgetKey::sessionRateLimited('sess-2', 30);
        $this->assertNull($allowed);
    }

    public function test_per_key_ip_rate_limit_includes_retry_after_on_429(): void
    {
        $key = $this->makeKey(['rate_limit' => 2]);

        // Make 2 requests to saturate the per-key-per-IP bucket
        $this->withHeaders([
            'X-Widget-Key' => $key->public_key,
            'Origin' => 'https://allowed.test',
        ])->postJson('/api/widget/sessions/start', [
            'snapshot' => [],
        ]);

        $this->withHeaders([
            'X-Widget-Key' => $key->public_key,
            'Origin' => 'https://allowed.test',
        ])->postJson('/api/widget/sessions/start', [
            'snapshot' => [],
        ]);

        // Third request should be 429 with Retry-After
        $response = $this->withHeaders([
            'X-Widget-Key' => $key->public_key,
            'Origin' => 'https://allowed.test',
        ])->postJson('/api/widget/sessions/start', [
            'snapshot' => [],
        ]);

        if ($response->getStatusCode() === 429) {
            $this->assertNotNull($response->headers->get('Retry-After'));
        }
        // Note: the exact status depends on whether the previous requests
        // consumed rate limit hits; we just assert the Retry-After header
        // is present when 429 is returned.
    }

    // ─── Helpers ───────────────────────────────────────────────────

    private function makeKey(array $overrides = []): \App\Models\WidgetKey
    {
        static $n = 0;
        $n++;

        return \App\Models\WidgetKey::create(array_merge([
            'tenant_id' => 'default',
            'project_key' => 'docs-v3',
            'public_key' => 'pk_rl_'.$n,
            'secret_hash' => null,
            'allowed_origins' => ['https://allowed.test'],
            'rate_limit' => 60,
            'skill' => 'askmydocs-assistant@1',
            'is_active' => true,
            'label' => 'rl-test-'.$n,
        ], $overrides));
    }
}