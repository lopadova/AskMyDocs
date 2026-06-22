<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\KbGuardrailsInsightsTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Tests\TestCase;

/**
 * v8.19/W2 — proves the AI Guardrails posture MCP read surface (R44 third surface).
 * Covers the ON path (posture + injection-audit aggregate) and the R43 OFF path
 * (master switch off → available:false, no throw).
 */
final class GuardrailsInsightsToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Pin the enforced posture for the assertions (host config already does this).
        config()->set('ai-guardrails.enabled', true);
        config()->set('ai-guardrails.input_screen.enabled', true);
        config()->set('ai-guardrails.output_handler.enabled', true);
        config()->set('ai-guardrails.modes.input_screen', 'enforce');
        config()->set('ai-guardrails.modes.output_handler', 'monitor');
        config()->set('ai-guardrails.audit.store', 'database');
    }

    private function seedAudit(bool $blocked, ?string $ruleId): void
    {
        DB::table('ai_guardrails_injection_audit')->insert([
            'prompt' => 'redacted',
            'blocked' => $blocked,
            'rule_id' => $ruleId,
            'principal_id' => null,
            'ruleset_version' => 'v1',
            'errored_rule_ids' => null,
            'match_start' => null,
            'match_end' => null,
            'occurred_at' => now(),
        ]);
    }

    private function invoke(array $args = []): array
    {
        $response = (new KbGuardrailsInsightsTool())->handle(new Request($args));

        return json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_reports_posture_and_injection_audit_aggregate_when_enabled(): void
    {
        $this->seedAudit(blocked: true, ruleId: 'ignore_previous');   // blocked
        $this->seedAudit(blocked: false, ruleId: 'role_override');    // observed (monitor match)
        $this->seedAudit(blocked: false, ruleId: null);               // clean allow

        $payload = $this->invoke(['hours' => 24]);

        $this->assertTrue($payload['available']);
        $this->assertTrue($payload['enabled']);

        // Four controls, in order, with their effective mode.
        $keys = array_column($payload['controls'], 'key');
        $this->assertSame(['input_screen', 'output_handler', 'tool_firewall', 'hitl'], $keys);
        $input = $payload['controls'][0];
        $this->assertTrue($input['enabled']);
        $this->assertSame('enforce', $input['mode']);
        $this->assertSame('monitor', $payload['controls'][1]['mode']);

        // Injection-audit aggregate: 3 screened, 1 blocked, 1 observed.
        $this->assertSame(24, $payload['injection_audit']['window_hours']);
        $this->assertSame(3, $payload['injection_audit']['screened']);
        $this->assertSame(1, $payload['injection_audit']['blocked']);
        $this->assertSame(1, $payload['injection_audit']['observed']);
    }

    public function test_off_state_returns_unavailable_without_throwing(): void
    {
        // R43 OFF: the master switch off → available:false, null posture, no throw.
        config()->set('ai-guardrails.enabled', false);

        $payload = $this->invoke();

        $this->assertFalse($payload['available']);
        $this->assertFalse($payload['enabled']);
        $this->assertNull($payload['controls']);
        $this->assertNull($payload['injection_audit']);
    }
}
