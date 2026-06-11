<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Models\WidgetKey;
use App\Models\WidgetSessionToken;
use App\Services\Widget\WidgetSessionTokenService;
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

        // Tema additivo (R27): key senza theme_config → default risolto.
        $res->assertJsonPath('theme.accent', '#2563eb')
            ->assertJsonPath('theme.fontFamily', 'system')
            ->assertJsonPath('theme.launcherShape', 'pill');
    }

    public function test_setup_returns_the_stored_theme_resolved_over_defaults(): void
    {
        $key = $this->makeKey([
            'allowed_origins' => ['https://allowed.test'],
            'theme_config' => ['accent' => '#10b981', 'launcherShape' => 'circle'],
        ]);

        $this->withHeaders([
            'X-Widget-Key' => $key->public_key,
            'Origin' => 'https://allowed.test',
        ])->getJson('/api/widget/setup')
            ->assertOk()
            ->assertJsonPath('theme.accent', '#10b981')   // valore custom
            ->assertJsonPath('theme.launcherShape', 'circle')
            ->assertJsonPath('theme.background', '#ffffff'); // resto sui default
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

    /** #21 — un ?skill DIVERSO da quello della key è rifiutato (no override). */
    public function test_setup_rejects_a_skill_other_than_the_keys(): void
    {
        $key = $this->makeKey(['allowed_origins' => ['https://allowed.test']]);

        $this->withHeaders([
            'X-Widget-Key' => $key->public_key,
            'Origin' => 'https://allowed.test',
        ])
            ->getJson('/api/widget/setup?skill=nope@9')
            ->assertStatus(403)
            ->assertJsonPath('error', 'skill_not_allowed');
    }

    /** #21 — un ?skill che COMBACIA con quello della key è ammesso. */
    public function test_setup_accepts_the_keys_own_skill_via_query(): void
    {
        $key = $this->makeKey(['allowed_origins' => ['https://allowed.test']]);

        $this->withHeaders([
            'X-Widget-Key' => $key->public_key,
            'Origin' => 'https://allowed.test',
        ])
            ->getJson('/api/widget/setup?skill='.urlencode($key->skill))
            ->assertOk()
            ->assertJsonPath('skill', $key->skill);
    }

    /** Se lo skill DELLA KEY non esiste nel registry → 404 skill_not_found. */
    public function test_setup_returns_404_when_the_keys_own_skill_is_unknown(): void
    {
        // Creiamo direttamente una key con skill inesistente (bypassa la
        // validazione admin che impedirebbe il salvataggio — #15).
        $key = $this->makeKey([
            'allowed_origins' => ['https://allowed.test'],
            'skill' => 'ghost-skill@9',
        ]);

        $this->withHeaders([
            'X-Widget-Key' => $key->public_key,
            'Origin' => 'https://allowed.test',
        ])
            ->getJson('/api/widget/setup')
            ->assertStatus(404)
            ->assertJsonPath('error', 'skill_not_found');
    }

    /** #10 — modalità session-token: SOLO `Authorization: Bearer wt_…`, niente X-Widget-Key. */
    public function test_session_token_mode_authenticates_without_x_widget_key(): void
    {
        $key = $this->makeKey(['allowed_origins' => ['https://allowed.test']]);
        $minted = app(WidgetSessionTokenService::class)->mint($key, null, 'https://allowed.test');

        // Esattamente ciò che invia transport.ts in token mode: nessun X-Widget-Key.
        $this->withHeaders([
            'Authorization' => 'Bearer '.$minted['token'],
            'Origin' => 'https://allowed.test',
        ])->getJson('/api/widget/setup')
            ->assertOk()
            ->assertJsonPath('project', $key->project_key);
    }

    /** #12 — un 429 sul rate-limit NON deve bruciare il token single-use. */
    public function test_session_token_is_not_consumed_on_rate_limit_429(): void
    {
        $key = $this->makeKey(['rate_limit' => 1, 'allowed_origins' => ['https://allowed.test']]);
        $service = app(WidgetSessionTokenService::class);

        // Esaurisce il bucket per-key con una richiesta pk.
        $this->withHeaders(['X-Widget-Key' => $key->public_key, 'Origin' => 'https://allowed.test'])
            ->getJson('/api/widget/setup')->assertOk();

        // Token presentato con bucket pieno → 429, MA il token resta non consumato.
        $minted = $service->mint($key, null, 'https://allowed.test');
        $this->withHeaders(['Authorization' => 'Bearer '.$minted['token'], 'Origin' => 'https://allowed.test'])
            ->getJson('/api/widget/setup')
            ->assertStatus(429);

        $row = WidgetSessionToken::where('token', hash('sha256', $minted['token']))->first();
        $this->assertNotNull($row);
        $this->assertNull($row->consumed_at, 'Il token non deve essere consumato su un 429.');
    }

    /** #26 — last_used_at è aggiornato con throttle: due richieste ravvicinate → una sola scrittura. */
    public function test_last_used_at_write_is_throttled(): void
    {
        $key = $this->makeKey(['allowed_origins' => ['https://allowed.test']]);
        $headers = ['X-Widget-Key' => $key->public_key, 'Origin' => 'https://allowed.test'];

        $this->withHeaders($headers)->getJson('/api/widget/setup')->assertOk();
        $first = $key->fresh()->last_used_at;
        $this->assertNotNull($first);

        // Seconda richiesta subito dopo: il throttle (60s) NON deve riscrivere.
        $this->withHeaders($headers)->getJson('/api/widget/setup')->assertOk();
        $second = $key->fresh()->last_used_at;

        $this->assertSame($first->toIso8601String(), $second->toIso8601String());
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
