<?php

namespace Tests\Feature\Agent;

use App\Agent\Evidence\AgentEvidenceFactory;
use App\Agent\Planning\AgentIdentifierSources;
use App\Models\AgentRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentIdentifierSourcesTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_branch_questions_cannot_authorize_a_new_identifier(): void
    {
        $attrs = ['tenant_id' => 'warehouse', 'project_key' => 'orders', 'channel' => 'chat', 'actor_type' => 'user',
            'actor_id' => '3', 'locale' => 'it', 'timezone' => 'Europe/Rome', 'status' => 'running'];
        $parent = AgentRun::create($attrs + ['run_id' => (string) Str::uuid(), 'input_json' => ['question' => 'Read ORDER-42 and RETURN-43.']]);
        $child = AgentRun::create($attrs + ['run_id' => (string) Str::uuid(), 'input_json' => ['question' => 'Read ORDER-43', 'research_parent_id' => $parent->id, 'research_flow_id' => 0]]);
        $parent->forceFill(['result_json' => ['research_runs' => [$child->id]]])->save();
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $sources = app(AgentIdentifierSources::class)->forRun($child, $evidence);
        $this->assertTrue(AgentIdentifierSources::contains('ORDER-42', $sources));
        $this->assertFalse(AgentIdentifierSources::contains('ORDER-43', $sources));
        $parent->forceFill(['result_json' => ['research_runs' => []]])->save();
        $this->expectException(\DomainException::class);
        app(AgentIdentifierSources::class)->forRun($child, $evidence);
    }

    public function test_arguments_are_not_observations_and_corrupt_or_unbound_results_are_excluded(): void
    {
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $result = ['order' => ['reference' => 'ORDER-43']];
        $evidence->import(['api_tools' => [
            ['execution_id' => 1, 'arguments' => ['reference' => 'ORDER-44'], 'result' => $result, 'evidence_hash' => 'wrong'],
            ['result' => $result, 'evidence_hash' => hash('sha256', json_encode($result, JSON_UNESCAPED_UNICODE))],
        ]]);
        $guard = app(AgentIdentifierSources::class);
        $sources = $guard->texts('Read RETURN-43', $evidence);
        $this->assertFalse(AgentIdentifierSources::contains('ORDER-43', $sources));
        $this->assertFalse(AgentIdentifierSources::contains('ORDER-44', $sources));
        $evidence->import(['api_tools' => [['execution_id' => 2, 'result' => $result,
            'evidence_hash' => hash('sha256', json_encode($result, JSON_UNESCAPED_UNICODE))]]]);
        $this->assertTrue(AgentIdentifierSources::contains('ORDER-43', $guard->texts('', $evidence)));
    }
}
