<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Models\WidgetKey;
use App\Models\WidgetSession;
use App\Models\WidgetSessionStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * M5.9 — GET /api/widget/sessions/{session}/replay.
 *
 * Copre: (a) step ritornati con PII mascherata, (b) scoping per key
 * (key diversa → 404, anti-IDOR), (c) sessione senza step → array vuoto.
 */
final class WidgetSessionReplayTest extends TestCase
{
    use RefreshDatabase;

    private function makeKey(array $overrides = []): WidgetKey
    {
        static $n = 0;
        $n++;

        return WidgetKey::create(array_merge([
            'tenant_id' => 'default',
            'project_key' => 'docs-v3',
            'public_key' => 'pk_replay_'.$n,
            'allowed_origins' => ['https://allowed.test'],
            'rate_limit' => 1000,
            'skill' => 'askmydocs-assistant@1',
            'is_active' => true,
            'label' => 'replay-'.$n,
        ], $overrides));
    }

    private function headers(WidgetKey $key): array
    {
        return ['X-Widget-Key' => $key->public_key, 'Origin' => 'https://allowed.test'];
    }

    private function createSession(WidgetKey $key, string $status = WidgetSession::STATUS_COMPLETED): WidgetSession
    {
        return WidgetSession::create([
            'tenant_id' => 'default',
            'widget_key_id' => $key->id,
            'project_key' => 'docs-v3',
            'public_session_id' => Str::uuid()->toString(),
            'status' => $status,
            'skill' => 'askmydocs-assistant@1',
            'page_url' => 'https://allowed.test/account',
            'origin' => 'https://allowed.test',
        ]);
    }

    // ─── (a) step con PII mascherata ─────────────────────────────────

    public function test_replay_returns_steps_with_pii_masked(): void
    {
        $key = $this->makeKey();
        $session = $this->createSession($key);

        // Create steps with PII data in args_json and diagnostic_json.
        // Even though the data might already be masked at save time,
        // we test that the replay endpoint applies masking on output.
        $session->steps()->create([
            'tenant_id' => 'default',
            'step_index' => 0,
            'kind' => WidgetSessionStep::KIND_USER_MESSAGE,
            'tool' => null,
            'args_json' => ['content' => 'Contatta user@example.com per assistenza'],
            'diagnostic_json' => ['raw' => 'Telefono +39 333 1234567'],
        ]);
        $session->steps()->create([
            'tenant_id' => 'default',
            'step_index' => 1,
            'kind' => WidgetSessionStep::KIND_TOOL_CALL,
            'tool' => 'click',
            'args_json' => ['target' => 'submit', 'email' => 'alice@acme.com'],
            'diagnostic_json' => null,
        ]);

        $res = $this->withHeaders($this->headers($key))
            ->getJson("/api/widget/sessions/{$session->public_session_id}/replay");

        $res->assertOk();

        $steps = $res->json('steps');
        $this->assertCount(2, $steps);

        // Step 0: user_message — email must be masked
        $this->assertSame(0, $steps[0]['step_index']);
        $this->assertSame('user_message', $steps[0]['kind']);
        $this->assertStringContainsString('[EMAIL]', $steps[0]['args_json']['content']);
        $this->assertStringNotContainsString('user@example.com', json_encode($steps[0]['args_json']));

        // diagnostic_json: phone must be masked
        $this->assertStringContainsString('[PHONE]', $steps[0]['diagnostic_json']['raw']);
        $this->assertStringNotContainsString('+39 333 1234567', json_encode($steps[0]['diagnostic_json']));

        // Step 1: tool_call — email in args_json must be masked
        $this->assertSame(1, $steps[1]['step_index']);
        $this->assertSame('tool_call', $steps[1]['kind']);
        $this->assertSame('click', $steps[1]['tool']);
        $this->assertSame('submit', $steps[1]['args_json']['target']); // safe field unchanged
        $this->assertStringContainsString('[EMAIL]', $steps[1]['args_json']['email']);
        $this->assertStringNotContainsString('alice@acme.com', json_encode($steps[1]['args_json']));
    }

    // ─── (b) scoping per key (anti-IDOR) ─────────────────────────────

    public function test_replay_returns_404_for_different_key(): void
    {
        $keyA = $this->makeKey();
        $keyB = $this->makeKey();

        $session = $this->createSession($keyA);

        // keyB tries to replay keyA's session → 404 (anti-IDOR)
        $this->withHeaders($this->headers($keyB))
            ->getJson("/api/widget/sessions/{$session->public_session_id}/replay")
            ->assertNotFound();
    }

    public function test_replay_returns_404_for_nonexistent_session(): void
    {
        $key = $this->makeKey();
        $fakeUuid = (string) Str::uuid();

        $this->withHeaders($this->headers($key))
            ->getJson("/api/widget/sessions/{$fakeUuid}/replay")
            ->assertNotFound();
    }

    // ─── (c) sessione vuota → array vuoto ────────────────────────────

    public function test_replay_returns_empty_array_for_session_without_steps(): void
    {
        $key = $this->makeKey();
        $session = $this->createSession($key);

        $res = $this->withHeaders($this->headers($key))
            ->getJson("/api/widget/sessions/{$session->public_session_id}/replay");

        $res->assertOk();
        $this->assertSame([], $res->json('steps'));
    }

    // ─── formato di risposta ─────────────────────────────────────────

    public function test_replay_response_contains_only_expected_fields(): void
    {
        $key = $this->makeKey();
        $session = $this->createSession($key);

        $session->steps()->create([
            'tenant_id' => 'default',
            'step_index' => 0,
            'kind' => WidgetSessionStep::KIND_BOT_MESSAGE,
            'tool' => null,
            'args_json' => ['answer' => 'Ecco come fare.'],
            'diagnostic_json' => null,
        ]);

        $res = $this->withHeaders($this->headers($key))
            ->getJson("/api/widget/sessions/{$session->public_session_id}/replay");

        $res->assertOk();
        $step = $res->json('steps.0');

        // Only the fields defined by the replay endpoint, never raw data
        $this->assertArrayHasKey('step_index', $step);
        $this->assertArrayHasKey('kind', $step);
        $this->assertArrayHasKey('tool', $step);
        $this->assertArrayHasKey('args_json', $step);
        $this->assertArrayHasKey('diagnostic_json', $step);

        // No internal fields leak (id, widget_session_id, tenant_id, etc.)
        $this->assertArrayNotHasKey('id', $step);
        $this->assertArrayNotHasKey('widget_session_id', $step);
        $this->assertArrayNotHasKey('tenant_id', $step);
        $this->assertArrayNotHasKey('tokens_in', $step);
        $this->assertArrayNotHasKey('tokens_out', $step);
        $this->assertArrayNotHasKey('latency_ms', $step);
        $this->assertArrayNotHasKey('idempotency_key', $step);
    }
}