<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Models\WidgetKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * M1 — gate del canale pubblico widget (`widget.key` + /api/widget/setup).
 *
 * Verifica le due modalità d'accesso (A browser pk+Origin, B proxy pk+secret),
 * i fallimenti espliciti (401/403/429, R14) e che project/tenant arrivino
 * DALLA KEY, mai dal client (R30).
 */
final class WidgetAccessTest extends TestCase
{
    use RefreshDatabase;

    private function makeKey(array $overrides = []): WidgetKey
    {
        static $n = 0;
        $n++;

        return WidgetKey::create(array_merge([
            'tenant_id' => 'default',
            'project_key' => 'docs-v3',
            'public_key' => 'pk_test_'.$n,
            'secret_hash' => null,
            'allowed_origins' => ['https://allowed.test'],
            'rate_limit' => 60,
            'skill' => 'askmydocs-assistant@1',
            'is_active' => true,
            'label' => 'test-'.$n,
        ], $overrides));
    }

    public function test_setup_requires_a_widget_key(): void
    {
        $this->getJson('/api/widget/setup')
            ->assertStatus(401)
            ->assertJsonPath('error', 'widget_key_missing');
    }

    public function test_unknown_widget_key_is_rejected(): void
    {
        $this->withHeaders(['X-Widget-Key' => 'pk_nope'])
            ->getJson('/api/widget/setup')
            ->assertStatus(401)
            ->assertJsonPath('error', 'widget_key_invalid');
    }

    public function test_inactive_key_is_forbidden(): void
    {
        $key = $this->makeKey(['is_active' => false]);

        $this->withHeaders([
            'X-Widget-Key' => $key->public_key,
            'Origin' => 'https://allowed.test',
        ])
            ->getJson('/api/widget/setup')
            ->assertStatus(403)
            ->assertJsonPath('error', 'widget_key_inactive');
    }

    public function test_browser_mode_rejects_a_disallowed_origin(): void
    {
        $key = $this->makeKey(['allowed_origins' => ['https://allowed.test']]);

        $this->withHeaders([
            'X-Widget-Key' => $key->public_key,
            'Origin' => 'https://evil.test',
        ])
            ->getJson('/api/widget/setup')
            ->assertStatus(403)
            ->assertJsonPath('error', 'origin_not_allowed');
    }

    public function test_browser_mode_allows_a_listed_origin_and_returns_manifest(): void
    {
        $key = $this->makeKey([
            'project_key' => 'hr-portal',
            'allowed_origins' => ['https://allowed.test'],
        ]);

        $res = $this->withHeaders([
            'X-Widget-Key' => $key->public_key,
            'Origin' => 'https://allowed.test',
        ])->getJson('/api/widget/setup');

        $res->assertOk()
            ->assertJsonPath('skill', 'askmydocs-assistant@1')
            ->assertJsonPath('project', 'hr-portal'); // R30: project dalla key

        $this->assertContains('click', $res->json('tools_enabled'));
        $this->assertContains('search_knowledge_base', $res->json('tools_enabled'));
    }

    public function test_proxy_mode_with_valid_secret_skips_the_origin_check(): void
    {
        $key = $this->makeKey([
            'secret_hash' => Hash::make('sk_super_secret'),
            'allowed_origins' => [], // nessun Origin ammesso in modalità browser
        ]);

        // Nessun Origin, ma Bearer col secret corretto → modalità proxy (B).
        $this->withHeaders([
            'X-Widget-Key' => $key->public_key,
            'Authorization' => 'Bearer sk_super_secret',
        ])
            ->getJson('/api/widget/setup')
            ->assertOk()
            ->assertJsonPath('project', $key->project_key);
    }

    public function test_wrong_secret_falls_back_to_browser_and_needs_origin(): void
    {
        $key = $this->makeKey([
            'secret_hash' => Hash::make('sk_super_secret'),
            'allowed_origins' => ['https://allowed.test'],
        ]);

        // Bearer errato → ricade in modalità browser → senza Origin valido = 403.
        $this->withHeaders([
            'X-Widget-Key' => $key->public_key,
            'Authorization' => 'Bearer sk_wrong',
        ])
            ->getJson('/api/widget/setup')
            ->assertStatus(403)
            ->assertJsonPath('error', 'origin_not_allowed');
    }

    public function test_unknown_skill_returns_404(): void
    {
        $key = $this->makeKey(['allowed_origins' => ['https://allowed.test']]);

        $this->withHeaders([
            'X-Widget-Key' => $key->public_key,
            'Origin' => 'https://allowed.test',
        ])
            ->getJson('/api/widget/setup?skill=nope@9')
            ->assertStatus(404)
            ->assertJsonPath('error', 'skill_not_found');
    }

    public function test_per_key_rate_limit_returns_429(): void
    {
        $key = $this->makeKey([
            'rate_limit' => 1,
            'allowed_origins' => ['https://allowed.test'],
        ]);

        $headers = [
            'X-Widget-Key' => $key->public_key,
            'Origin' => 'https://allowed.test',
        ];

        $this->withHeaders($headers)->getJson('/api/widget/setup')->assertOk();
        $this->withHeaders($headers)->getJson('/api/widget/setup')
            ->assertStatus(429)
            ->assertJsonPath('error', 'rate_limited');
    }
}
