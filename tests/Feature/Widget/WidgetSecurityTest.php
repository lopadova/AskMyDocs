<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Models\WidgetKey;
use App\Models\WidgetSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Security/pentest regression suite for the embeddable KITT channel.
 *
 * These lock the containment guarantees documented in
 * docs/kitt/INTEGRATION.md §14 so a future refactor can't silently weaken them:
 *
 *   - the public-key (`pk_`) origin allowlist is EXACT-match — look-alike,
 *     scheme-downgrade and port-mismatch origins must NOT pass (the only thing
 *     that stops a stolen public key being used from another browser context);
 *   - the public `/setup` manifest never leaks `secret_hash`;
 *   - even a perfectly-formed request (allowlisted Origin) is CONTAINED: it
 *     cannot reach another key's / tenant's session (anti-IDOR 404).
 */
final class WidgetSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function makeKey(array $overrides = []): WidgetKey
    {
        static $n = 0;
        $n++;

        return WidgetKey::create(array_merge([
            'tenant_id' => 'default',
            'project_key' => 'docs-v3',
            'public_key' => 'pk_sec_'.$n,
            'secret_hash' => null,
            'allowed_origins' => ['https://allowed.test'],
            'rate_limit' => 1000,
            'skill' => 'askmydocs-assistant@1',
            'is_active' => true,
            'label' => 'sec-'.$n,
        ], $overrides));
    }

    /**
     * The origin allowlist is EXACT-match: a look-alike that merely contains or
     * suffixes the allowed host is rejected. This is the textbook public-widget
     * theft vector (attacker hosts `https://allowed.test.evil.com`).
     *
     */
    #[DataProvider('lookalikeOrigins')]
    public function test_origin_allowlist_rejects_lookalike_origins(string $origin): void
    {
        $key = $this->makeKey(['allowed_origins' => ['https://allowed.test']]);

        $this->withHeaders([
            'X-Widget-Key' => $key->public_key,
            'Origin' => $origin,
        ])
            ->getJson('/api/widget/setup')
            ->assertStatus(403)
            ->assertJsonPath('error', 'origin_not_allowed');
    }

    /** @return array<string, array{0: string}> */
    public static function lookalikeOrigins(): array
    {
        return [
            'suffix attack'   => ['https://allowed.test.evil.com'],
            'subdomain inject'=> ['https://evil.allowed.test'],
            'scheme downgrade'=> ['http://allowed.test'],
            'port mismatch'   => ['https://allowed.test:8443'],
            'prefix path'     => ['https://allowed.test.attacker.io'],
            'embedded host'   => ['https://allowed.test@evil.com'],
        ];
    }

    /** The exact allowed origin (and only it) passes. */
    public function test_origin_allowlist_accepts_only_the_exact_origin(): void
    {
        $key = $this->makeKey(['allowed_origins' => ['https://allowed.test']]);

        $this->withHeaders([
            'X-Widget-Key' => $key->public_key,
            'Origin' => 'https://allowed.test',
        ])->getJson('/api/widget/setup')->assertOk();
    }

    /** An empty allowlist denies every browser-mode origin (no implicit allow). */
    public function test_empty_allowlist_denies_all_browser_origins(): void
    {
        $key = $this->makeKey(['allowed_origins' => []]);

        $this->withHeaders([
            'X-Widget-Key' => $key->public_key,
            'Origin' => 'https://allowed.test',
        ])
            ->getJson('/api/widget/setup')
            ->assertStatus(403)
            ->assertJsonPath('error', 'origin_not_allowed');
    }

    /** The public manifest endpoint never serializes the secret hash. */
    public function test_setup_manifest_never_leaks_the_secret_hash(): void
    {
        $key = $this->makeKey([
            'allowed_origins' => ['https://allowed.test'],
            'secret_hash' => \Illuminate\Support\Facades\Hash::make('sk_super_secret'),
        ]);

        $res = $this->withHeaders([
            'X-Widget-Key' => $key->public_key,
            'Origin' => 'https://allowed.test',
        ])->getJson('/api/widget/setup')->assertOk();

        $this->assertStringNotContainsString('secret_hash', $res->getContent());
        $this->assertStringNotContainsString($key->secret_hash, $res->getContent());
    }

    /**
     * Containment: even a perfectly-formed, allowlisted request cannot drive a
     * session that belongs to another key (anti-IDOR). The response is 404
     * (existence-hiding), not 403.
     */
    public function test_a_valid_request_cannot_reach_another_keys_session(): void
    {
        $victim = $this->makeKey(['allowed_origins' => ['https://allowed.test']]);
        $attacker = $this->makeKey(['allowed_origins' => ['https://allowed.test']]);

        $session = WidgetSession::create([
            'tenant_id' => 'default',
            'widget_key_id' => $victim->id,
            'project_key' => 'docs-v3',
            'public_session_id' => \Illuminate\Support\Str::uuid()->toString(),
            'status' => WidgetSession::STATUS_WAITING_TOOL,
            'skill' => 'askmydocs-assistant@1',
        ]);

        // Attacker key, valid Origin, valid session id — but not its session.
        $this->withHeaders([
            'X-Widget-Key' => $attacker->public_key,
            'Origin' => 'https://allowed.test',
        ])->getJson("/api/widget/sessions/{$session->public_session_id}/replay")
            ->assertNotFound();
    }

    /**
     * Containment across tenants: a key in tenant A cannot reach a session in
     * tenant B even with a structurally-valid request (the binding is tenant +
     * key scoped, R30).
     */
    public function test_a_key_cannot_reach_a_session_in_another_tenant(): void
    {
        $tenantBKey = $this->makeKey(['tenant_id' => 'tenant-b', 'allowed_origins' => ['https://allowed.test']]);
        $tenantASession = WidgetSession::create([
            'tenant_id' => 'default',
            'widget_key_id' => $this->makeKey(['allowed_origins' => ['https://allowed.test']])->id,
            'project_key' => 'docs-v3',
            'public_session_id' => \Illuminate\Support\Str::uuid()->toString(),
            'status' => WidgetSession::STATUS_WAITING_TOOL,
            'skill' => 'askmydocs-assistant@1',
        ]);

        $this->withHeaders([
            'X-Widget-Key' => $tenantBKey->public_key,
            'Origin' => 'https://allowed.test',
        ])->getJson("/api/widget/sessions/{$tenantASession->public_session_id}/replay")
            ->assertNotFound();
    }
}
