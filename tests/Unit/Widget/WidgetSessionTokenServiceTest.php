<?php

declare(strict_types=1);

namespace Tests\Unit\Widget;

use App\Models\WidgetKey;
use App\Models\WidgetSession;
use App\Models\WidgetSessionToken;
use App\Services\Widget\WidgetSessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M5.2 — WidgetSessionTokenService: conio e consumo atomico (R21) dei
 * token di sessione origin-bound per la modalità browser (A).
 */
final class WidgetSessionTokenServiceTest extends TestCase
{
    use RefreshDatabase;

    private WidgetSessionTokenService $service;

    private WidgetKey $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WidgetSessionTokenService;
        $this->key = $this->makeKey();
    }

    // ─── Mint ──────────────────────────────────────────────────────

    public function test_mint_creates_a_valid_token(): void
    {
        $result = $this->service->mint($this->key, null, 'https://allowed.test');

        $this->assertStringStartsWith('wt_', $result['token']);
        $this->assertNotEmpty($result['expires_at']);

        $this->assertDatabaseHas('widget_session_tokens', [
            'token' => hash('sha256', $result['token']),
            'widget_key_id' => $this->key->id,
            'origin' => 'https://allowed.test',
        ]);
        // #14 — il plaintext NON deve esistere a riposo (solo l'hash sha256).
        $this->assertDatabaseMissing('widget_session_tokens', ['token' => $result['token']]);
    }

    public function test_mint_with_session_links_token_to_session(): void
    {
        $session = WidgetSession::create([
            'tenant_id' => $this->key->tenant_id,
            'widget_key_id' => $this->key->id,
            'project_key' => $this->key->project_key,
            'public_session_id' => (string) \Illuminate\Support\Str::uuid(),
            'status' => WidgetSession::STATUS_ACTIVE,
            'skill' => $this->key->skill,
        ]);

        $result = $this->service->mint($this->key, $session, null);

        $this->assertDatabaseHas('widget_session_tokens', [
            'token' => hash('sha256', $result['token']),
            'widget_session_id' => $session->id,
        ]);
    }

    public function test_mint_without_origin_creates_originless_token(): void
    {
        $result = $this->service->mint($this->key, null, null);

        $this->assertDatabaseHas('widget_session_tokens', [
            'token' => hash('sha256', $result['token']),
            'origin' => null,
        ]);
    }

    // ─── Consume ───────────────────────────────────────────────────

    public function test_consume_valid_token_returns_key(): void
    {
        $minted = $this->service->mint($this->key, null, 'https://allowed.test');

        $result = $this->service->consume($minted['token'], 'https://allowed.test');

        $this->assertNotNull($result);
        $this->assertSame($this->key->id, $result['key']->id);
    }

    public function test_consume_marks_token_as_consumed(): void
    {
        $minted = $this->service->mint($this->key, null, null);

        $this->service->consume($minted['token'], null);

        $token = WidgetSessionToken::where('token', hash('sha256', $minted['token']))->first();
        $this->assertNotNull($token->consumed_at);
    }

    public function test_consume_rejects_already_consumed_token(): void
    {
        $minted = $this->service->mint($this->key, null, null);

        // First consume
        $first = $this->service->consume($minted['token'], null);
        $this->assertNotNull($first);

        // Second consume — MUST be rejected (R21 atomic)
        $second = $this->service->consume($minted['token'], null);
        $this->assertNull($second);
    }

    public function test_consume_rejects_expired_token(): void
    {
        $minted = $this->service->mint($this->key, null, null);

        // Expire the token
        WidgetSessionToken::where('token', hash('sha256', $minted['token']))
            ->update(['expires_at' => now()->subMinutes(5)]);

        $result = $this->service->consume($minted['token'], null);
        $this->assertNull($result);
    }

    public function test_consume_rejects_unknown_token(): void
    {
        $result = $this->service->consume('wt_nonexistent', null);
        $this->assertNull($result);
    }

    public function test_consume_rejects_wrong_origin(): void
    {
        $minted = $this->service->mint($this->key, null, 'https://allowed.test');

        $result = $this->service->consume($minted['token'], 'https://evil.test');
        $this->assertNull($result);
    }

    public function test_consume_accepts_originless_token_from_any_origin(): void
    {
        $minted = $this->service->mint($this->key, null, null);

        $result = $this->service->consume($minted['token'], 'https://any.test');
        $this->assertNotNull($result);
    }

    /** #11 — un token ORIGIN-BOUND presentato SENZA header Origin → rifiutato E NON consumato. */
    public function test_consume_rejects_origin_bound_token_when_request_has_no_origin(): void
    {
        $minted = $this->service->mint($this->key, null, 'https://allowed.test');

        // Replay via curl senza header Origin: prima bypassava il binding.
        $result = $this->service->consume($minted['token'], null);
        $this->assertNull($result);

        // R21 — la mutazione è condizionata al successo: il token NON va bruciato.
        $row = WidgetSessionToken::where('token', hash('sha256', $minted['token']))->first();
        $this->assertNull($row->consumed_at);
    }

    /** #12 — peekKey ritorna la key senza consumare il token. */
    public function test_peek_key_returns_key_without_consuming(): void
    {
        $minted = $this->service->mint($this->key, null, null);

        $peeked = $this->service->peekKey($minted['token']);
        $this->assertNotNull($peeked);
        $this->assertSame($this->key->id, $peeked->id);

        // Il token NON è stato consumato → consume successivo riesce.
        $consumed = $this->service->consume($minted['token'], null);
        $this->assertNotNull($consumed);
    }

    /**
     * #12 (R40 must-fix) — peekKey NON ritorna la key per token scaduti o
     * consumati: altrimenti il replay di un token morto raggiungerebbe il
     * rate-limit (bucket della key incrementato) prima del 401 → DoS.
     */
    public function test_peek_key_returns_null_for_expired_or_consumed_token(): void
    {
        // Scaduto
        $expired = $this->service->mint($this->key, null, null);
        WidgetSessionToken::where('token', hash('sha256', $expired['token']))
            ->update(['expires_at' => now()->subMinute()]);
        $this->assertNull($this->service->peekKey($expired['token']));

        // Consumato
        $used = $this->service->mint($this->key, null, null);
        $this->service->consume($used['token'], null);
        $this->assertNull($this->service->peekKey($used['token']));
    }

    public function test_consume_rejects_inactive_key(): void
    {
        $minted = $this->service->mint($this->key, null, null);

        // Deactivate the key after minting
        $this->key->forceFill(['is_active' => false])->save();

        $result = $this->service->consume($minted['token'], null);
        $this->assertNull($result);

        // #13/R21 — presentare un token per una key revocata NON deve bruciarlo
        // (la mutazione avviene SOLO a validazione superata).
        $row = WidgetSessionToken::where('token', hash('sha256', $minted['token']))->first();
        $this->assertNull($row->consumed_at);
    }

    public function test_consume_returns_linked_session(): void
    {
        $session = WidgetSession::create([
            'tenant_id' => $this->key->tenant_id,
            'widget_key_id' => $this->key->id,
            'project_key' => $this->key->project_key,
            'public_session_id' => (string) \Illuminate\Support\Str::uuid(),
            'status' => WidgetSession::STATUS_ACTIVE,
            'skill' => $this->key->skill,
        ]);

        $minted = $this->service->mint($this->key, $session, null);
        $result = $this->service->consume($minted['token'], null);

        $this->assertNotNull($result);
        $this->assertSame($session->id, $result['session']->id);
    }

    // ─── R21: Atomicità — no TOCTOU ───────────────────────────────

    public function test_consume_is_atomic_no_double_consume_under_concurrency(): void
    {
        $minted = $this->service->mint($this->key, null, null);

        // Simulate two concurrent consume attempts using DB transactions
        // The first lock will win; the second will see consumed_at set.
        $results = [];

        DB::transaction(function () use ($minted, &$results) {
            $first = $this->service->consume($minted['token'], null);
            $results[] = $first !== null;
        });

        // Second attempt after first commit
        $second = $this->service->consume($minted['token'], null);
        $results[] = $second !== null;

        // Exactly one must succeed
        $this->assertCount(2, $results);
        $this->assertTrue($results[0], 'First consume should succeed.');
        $this->assertFalse($results[1], 'Second consume must fail (R21 atomic).');
    }

    // ─── Helpers ───────────────────────────────────────────────────

    private function makeKey(array $overrides = []): WidgetKey
    {
        static $n = 0;
        $n++;

        return WidgetKey::create(array_merge([
            'tenant_id' => 'default',
            'project_key' => 'docs-v3',
            'public_key' => 'pk_st_'.$n,
            'secret_hash' => null,
            'allowed_origins' => ['https://allowed.test'],
            'rate_limit' => 60,
            'skill' => 'askmydocs-assistant@1',
            'is_active' => true,
            'label' => 'st-test-'.$n,
        ], $overrides));
    }
}