<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentAnswerSynthesizer;
use App\Agent\AgentExecutionContext;
use App\Agent\AgentLoopOutcome;
use App\Agent\Evidence\AgentEvidenceFactory;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Models\AgentDiagnosticSpan;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class FinalizationDeadlineTest extends TestCase
{
    use RefreshDatabase;

    public static function deadlines(): array
    {
        return [[100, true], [113, false]];
    }

    #[DataProvider('deadlines')]
    public function test_near_deadline_skips_selective_repair_and_presentation_after_checkpointing_verified_claims(int $elapsed, bool $coverageRuns): void
    {
        $this->travelTo(now()->startOfSecond());
        config(['agent.job_timeout_seconds' => 120, 'agent.finalization.safety_seconds' => 5,
            'agent.finalization.repair_min_seconds' => 45, 'agent.finalization.presentation_min_seconds' => 45,
            'agent.presentation.enabled' => true, 'reasoning.enabled' => true,
            'reasoning.selective_validation' => true, 'reasoning.selective_repair' => true,
            'ai.providers.openrouter.key' => 'test']);
        app(TenantContext::class)->set('acme');
        app(\Padosoft\AskMyDocsConnectorBase\Support\TenantContext::class)->set('acme');
        [$run, $document, $chunk] = $this->fixture();
        $this->travel($elapsed)->seconds();
        Http::preventStrayRequests();
        $checkpointSeenDuringCoverage = false;
        Http::fake(function ($request) use ($run, &$checkpointSeenDuringCoverage) {
            $answers = [];
            foreach ($request['questions'] as $key => $question) {
                if ($key === 'covered') {
                    $checkpointSeenDuringCoverage = data_get($run->fresh()->result_json, 'verified_answer_checkpoint.answer.grounding.status') === 'grounded';
                    $answers[$key] = ['type' => 'noul', 'noul' => .01];
                } elseif ($question['type'] === 'noul') {
                    $answers[$key] = ['type' => 'noul', 'noul' => .99];
                } else {
                    $pair = collect($request['state']['pairs'])->firstWhere('claim_id', $key);
                    $category = str_contains($pair['claim'], 'paid') ? 'unsupported' : 'supported_relevant';
                    $probabilities = array_fill_keys(array_keys($question['criteria']), .0025);
                    $probabilities[$category] = .99;
                    $answers[$key] = ['type' => 'choice', 'choice' => $category, 'confidence' => .99, 'probabilities' => $probabilities];
                }
            }
            return Http::response(['id' => 'decision', 'model' => 'jev-test', 'answers' => $answers]);
        });
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn(new AiResponse('', 'fake', 'test', toolCalls: [[
            'name' => 'submit_agent_answer', 'arguments' => ['completeness' => 'complete', 'claims' => [
                ['text' => $chunk->chunk_text, 'document_id' => $document->id, 'evidence_hash' => $chunk->chunk_hash],
                ['text' => 'CASE-A refund was paid.', 'document_id' => $document->id, 'evidence_hash' => $chunk->chunk_hash],
            ], 'limitations' => [], 'render_table' => false, 'requires_selection' => false],
        ]]));
        $this->app->instance(AiManager::class, $ai);
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $evidence->addDocument(['document_id' => $document->id, 'title' => $document->title, 'origin' => 'primary', 'evidence' => [[
            'chunk_id' => $chunk->id, 'content' => $chunk->chunk_text, 'evidence_hash' => $chunk->chunk_hash, 'complete_source' => true,
        ]]]);
        $focus = ['topic' => 'case', 'identifiers' => ['CASE-A'], 'aspect' => 'resolution', 'fields' => ['*']];
        $answer = app(AgentAnswerSynthesizer::class)->synthesize('What did the merchant confirm for CASE-A?',
            AgentExecutionContext::fromArray($run->toArray()), new AgentLoopOutcome('answer', $evidence, []),
            understanding: ['focus' => $focus, 'subquestions' => [$focus]], durableRunId: $run->run_id);

        $this->assertSame($coverageRuns, $checkpointSeenDuringCoverage);
        if (! $coverageRuns) {
            $this->assertSame(['status' => 'skipped', 'reason' => 'deadline', 'sufficient' => null, 'source_document_ids' => []], $answer->grounding['coverage_validation']);
            $this->assertSame('partial', $answer->completeness);
        }
        $this->assertStringContainsString($chunk->chunk_text, $answer->answer);
        $this->assertStringNotContainsString('refund was paid', $answer->answer);
        $this->assertSame('deadline', data_get($answer->grounding, 'presentation.reason'));
        $operations = AgentDiagnosticSpan::query()->where('agent_run_id', $run->id)->where('phase', 'finalization.skipped')
            ->get()->pluck('metadata_json.operation')->all();
        $this->assertContains('selective_repair', $operations);
        $this->assertContains('answer_presentation', $operations);
        $this->assertSame(! $coverageRuns, in_array('answer_coverage', $operations, true));
        $this->assertSame($chunk->chunk_text, data_get($run->fresh()->result_json, 'verified_answer_checkpoint.answer.grounding.claims.0.text'));
    }

    private function fixture(): array
    {
        $user = User::create(['name' => 'Tester', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('test'), 'is_active' => true]);
        ProjectMembership::create(['tenant_id' => 'acme', 'project_key' => 'orders', 'user_id' => $user->id, 'role' => 'member']);
        $this->actingAs($user);
        $conversation = Conversation::create(['tenant_id' => 'acme', 'project_key' => 'orders', 'user_id' => $user->id]);
        $run = AgentRun::create(['run_id' => (string) Str::uuid(), 'tenant_id' => 'acme', 'project_key' => 'orders',
            'channel' => 'chat', 'actor_type' => 'user', 'actor_id' => (string) $user->id, 'user_id' => $user->id,
            'conversation_id' => $conversation->id, 'locale' => 'en', 'timezone' => 'UTC', 'status' => AgentRun::STATUS_RUNNING,
            'input_json' => ['question' => 'What did the merchant confirm for CASE-A?']]);
        $text = 'The merchant accepted the CASE-A refund request.';
        $hash = hash('sha256', $text);
        $document = KnowledgeDocument::create(['tenant_id' => 'acme', 'project_key' => 'orders', 'source_type' => 'markdown',
            'title' => 'CASE-A', 'source_path' => (string) Str::uuid(), 'status' => 'active', 'document_hash' => $hash]);
        $chunk = KnowledgeChunk::create(['tenant_id' => 'acme', 'project_key' => 'orders', 'knowledge_document_id' => $document->id,
            'chunk_order' => 0, 'chunk_text' => $text, 'chunk_hash' => $hash]);
        return [$run, $document, $chunk];
    }
}
