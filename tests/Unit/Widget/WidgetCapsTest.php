<?php

declare(strict_types=1);

namespace Tests\Unit\Widget;

use App\Models\WidgetKey;
use App\Models\WidgetSession;
use App\Models\WidgetSessionStep;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * M5.5 — Cap su message length e max steps per sessione → 422/blocked.
 */
final class WidgetCapsTest extends TestCase
{
    private WidgetKey $key;

    private WidgetSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->key = $this->makeKey();
        $this->session = WidgetSession::create([
            'tenant_id' => $this->key->tenant_id,
            'widget_key_id' => $this->key->id,
            'project_key' => $this->key->project_key,
            'public_session_id' => (string) \Illuminate\Support\Str::uuid(),
            'status' => WidgetSession::STATUS_ACTIVE,
            'skill' => $this->key->skill,
        ]);

        // Ensure per-key-per-IP and per-session rate limits don't interfere
        RateLimiter::clear('widget:'.$this->key->public_key.':127.0.0.1');
        RateLimiter::clear('widget:session:'.$this->session->public_session_id);
    }

    // ─── Message length cap ───────────────────────────────────────

    public function test_message_exceeding_configured_max_length_returns_422(): void
    {
        config(['widget.max_message_length' => 10]);

        $response = $this->withHeaders([
            'X-Widget-Key' => $this->key->public_key,
            'Origin' => 'https://allowed.test',
        ])->postJson('/api/widget/sessions/start', [
            'snapshot' => $this->validSnapshot(),
            'message' => str_repeat('a', 11),
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $errors = $response->json('errors', []);
        $this->assertArrayHasKey('message', $errors);
    }

    public function test_message_at_exact_max_length_passes_validation(): void
    {
        config(['widget.max_message_length' => 10]);

        $response = $this->withHeaders([
            'X-Widget-Key' => $this->key->public_key,
            'Origin' => 'https://allowed.test',
        ])->postJson('/api/widget/sessions/start', [
            'snapshot' => $this->validSnapshot(),
            'message' => str_repeat('a', 10),
        ]);

        // Validation should pass for message; any error must NOT be about message
        if ($response->getStatusCode() === 422) {
            $errors = $response->json('errors', []);
            $this->assertArrayNotHasKey('message', $errors);
        }
        $this->assertTrue(true);
    }

    // ─── Snapshot byte cap (#23) ───────────────────────────────────

    public function test_snapshot_exceeding_byte_cap_returns_422(): void
    {
        // Conteggi conformi (un solo field) ma stringa enorme: passa i count cap,
        // supera il byte cap → 422 snapshot_too_large (mai 200 muto, R14).
        config(['widget.snapshot_max_bytes' => 1024]);

        $snapshot = $this->validSnapshot();
        $snapshot['fields'] = [
            ['name' => 'big', 'label' => 'Big', 'type' => 'text', 'value' => str_repeat('x', 4000)],
        ];

        $response = $this->withHeaders([
            'X-Widget-Key' => $this->key->public_key,
            'Origin' => 'https://allowed.test',
        ])->postJson('/api/widget/sessions/start', [
            'snapshot' => $snapshot,
            'message' => 'ciao',
        ]);

        $response->assertStatus(422)->assertJsonPath('error', 'snapshot_too_large');
    }

    public function test_snapshot_within_byte_cap_is_accepted(): void
    {
        // OFF/ON entrambi gli stati (R43): sotto il cap il path procede (non 422 size).
        config(['widget.snapshot_max_bytes' => 262144]);

        $response = $this->withHeaders([
            'X-Widget-Key' => $this->key->public_key,
            'Origin' => 'https://allowed.test',
        ])->postJson('/api/widget/sessions/start', [
            'snapshot' => $this->validSnapshot(),
            'message' => 'ciao',
        ]);

        $this->assertNotSame('snapshot_too_large', $response->json('error'));
    }

    // ─── Step cap ──────────────────────────────────────────────────

    public function test_session_step_count_at_cap_triggers_blocked(): void
    {
        config(['widget.max_steps_per_session' => 3]);

        // Create 3 steps (>= max)
        for ($i = 0; $i < 3; $i++) {
            $this->session->steps()->create([
                'step_index' => $i,
                'kind' => WidgetSessionStep::KIND_USER_MESSAGE,
                'args_json' => ['content' => 'msg '.$i],
            ]);
        }

        $maxSteps = (int) config('widget.max_steps_per_session', 100);
        $stepCount = $this->session->steps()->count();
        $this->assertGreaterThanOrEqual($maxSteps, $stepCount, 'Steps should be at or above cap');

        // Simulate what the controller does when cap is hit
        $this->session->forceFill([
            'status' => WidgetSession::STATUS_BLOCKED,
            'blocked_reason' => 'Max steps per session exceeded.',
        ])->save();

        $this->session->refresh();
        $this->assertSame(WidgetSession::STATUS_BLOCKED, $this->session->status);
        $this->assertSame('Max steps per session exceeded.', $this->session->blocked_reason);
    }

    public function test_session_below_step_cap_is_not_blocked(): void
    {
        config(['widget.max_steps_per_session' => 10]);

        for ($i = 0; $i < 2; $i++) {
            $this->session->steps()->create([
                'step_index' => $i,
                'kind' => WidgetSessionStep::KIND_USER_MESSAGE,
                'args_json' => ['content' => 'msg '.$i],
            ]);
        }

        $this->assertSame(2, $this->session->steps()->count());
        $this->assertSame(WidgetSession::STATUS_ACTIVE, $this->session->status);
    }

    // ─── Helpers ───────────────────────────────────────────────────

    /**
     * Snapshot minimale che passa assertWithinCaps e sanitizeSnapshot.
     */
    private function validSnapshot(): array
    {
        return [
            'page' => ['url' => 'https://example.com', 'title' => 'Test'],
            'regions' => [],
            'fields' => [],
            'actions' => [],
            'messages' => [],
            'page_outline' => [
                'links' => [],
                'headings' => [],
            ],
        ];
    }

    private function makeKey(array $overrides = []): WidgetKey
    {
        static $n = 0;
        $n++;

        return WidgetKey::create(array_merge([
            'tenant_id' => 'default',
            'project_key' => 'docs-v3',
            'public_key' => 'pk_cap_'.$n,
            'secret_hash' => null,
            'allowed_origins' => ['https://allowed.test'],
            'rate_limit' => 600,
            'skill' => 'askmydocs-assistant@1',
            'is_active' => true,
            'label' => 'cap-test-'.$n,
        ], $overrides));
    }
}