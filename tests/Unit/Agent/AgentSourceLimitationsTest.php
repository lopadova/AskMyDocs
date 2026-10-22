<?php

namespace Tests\Unit\Agent;

use App\Agent\AgentSourceLimitations;
use PHPUnit\Framework\TestCase;

class AgentSourceLimitationsTest extends TestCase
{
    private function tool(): array
    {
        return ['kind' => 'api', 'execution_id' => 42, 'evidence_hash' => 'current',
            'presentation' => ['evidence_limitations' => [AgentSourceLimitations::RETURN_NOT_SETTLEMENT, 'untrusted_text']]];
    }

    public function test_declared_limit_is_rendered_once_for_a_used_verified_source(): void
    {
        $claim = ['tool_execution_id' => 42, 'evidence_hash' => 'current'];
        $notes = (new AgentSourceLimitations)->forClaims([$this->tool(), $this->tool()], [$claim], 'it');
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('non costituiscono una verifica indipendente', $notes[0]['text']);
        $this->assertStringNotContainsString('untrusted_text', $notes[0]['text']);
        $this->assertSame(42, $notes[0]['tool_execution_id']);
    }

    public function test_unused_or_mismatched_sources_cannot_add_a_limit(): void
    {
        $service = new AgentSourceLimitations;
        $this->assertSame([], $service->forClaims([$this->tool()], [], 'it'));
        $this->assertSame([], $service->forClaims([$this->tool()], [['tool_execution_id' => 42, 'evidence_hash' => 'other']], 'it'));
        $this->assertSame([], $service->forClaims([$this->tool()], [['tool_execution_id' => 99, 'evidence_hash' => 'current']], 'it'));
    }

    public function test_payload_and_unknown_metadata_cannot_inject_text(): void
    {
        $tool = $this->tool();
        $tool['presentation']['evidence_limitations'] = ['untrusted_text'];
        $tool['result']['evidence_limitations'] = [AgentSourceLimitations::RETURN_NOT_SETTLEMENT];
        $this->assertSame([], (new AgentSourceLimitations)->forClaims([$tool], [['tool_execution_id' => 42, 'evidence_hash' => 'current']], 'it'));
    }
}
