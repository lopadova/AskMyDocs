<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentResearchProgress;
use App\Agent\ProgressiveAnswerPublisher;
use App\Models\AgentRun;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ProgressiveAnswerPublisherTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_findings_are_durable_deduplicated_and_not_released_as_raw_candidates(): void
    {
        [$run, $sources, $finding] = $this->fixture();
        Http::fake(['*' => Http::response(['id' => 'decision-1', 'model' => 'jev-test', 'answers' => [
            'finding_0' => ['type' => 'noul', 'noul' => .99],
        ]])]);
        $observer = app(AgentResearchProgress::class)->observer($run);
        $observer('research.candidates', ['sources' => $sources, 'findings' => [$finding]]);
        $observer('research.candidates', ['sources' => $sources, 'findings' => [$finding]]);

        $event = $run->events()->sole();
        $this->assertSame('research.findings', $event->type);
        $this->assertSame($finding['text'], data_get($event->payload_json, 'data.findings.0.text'));
        $this->assertSame($finding['quote'], data_get($event->payload_json, 'data.findings.0.quote'));
        $this->assertSame($finding['document_id'], data_get($event->payload_json, 'data.findings.0.citation.document_id'));
        $this->assertArrayNotHasKey('sources', $event->payload_json['data']);
        Http::assertSentCount(1);
        $this->assertSame('published', data_get($run->fresh()->result_json, 'progressive_audit.0.status'));
    }

    public static function rejectedCases(): array
    {
        return ['confident rejection' => [.01], 'uncertain' => [.55], 'malformed' => [null]];
    }

    #[DataProvider('rejectedCases')]
    public function test_rejected_uncertain_or_invalid_decisions_never_publish(?float $probability): void
    {
        [$run, $sources, $finding] = $this->fixture();
        Http::fake(['*' => Http::response(['id' => 'decision-1', 'model' => 'jev-test',
            'answers' => $probability === null ? [] : ['finding_0' => ['type' => 'noul', 'noul' => $probability]]])]);
        app(ProgressiveAnswerPublisher::class)->publish($run, $sources, [$finding]);
        $this->assertSame(0, $run->events()->count());
        $expected = $probability === .01 ? 'rejected' : 'uncertain_or_malformed';
        $this->assertSame($expected, data_get($run->fresh()->result_json, 'progressive_audit.0.status'));
        $this->assertStringNotContainsString($finding['quote'], json_encode($run->fresh()->result_json['progressive_audit']));
    }

    public function test_unavailable_provider_never_publishes_unchecked_text(): void
    {
        [$run, $sources, $finding] = $this->fixture();
        Http::fake(['*' => Http::response([], 503)]);
        app(ProgressiveAnswerPublisher::class)->publish($run, $sources, [$finding]);
        $this->assertSame(0, $run->events()->count());
    }

    public function test_guessed_foreign_or_changed_sources_and_nonliteral_quotes_do_not_reach_the_model(): void
    {
        [$run, $sources, $finding] = $this->fixture();
        Http::preventStrayRequests();
        $publisher = app(ProgressiveAnswerPublisher::class);
        $publisher->publish($run, [], [$finding]);
        $publisher->publish($run, $sources, [[...$finding, 'quote' => 'Invented quotation.']]);
        KnowledgeDocument::whereKey($finding['document_id'])->update(['tenant_id' => 'another-tenant']);
        $publisher->publish($run, $sources, [$finding]);
        KnowledgeDocument::whereKey($finding['document_id'])->update(['tenant_id' => 'acme', 'project_key' => 'another-project']);
        $publisher->publish($run, $sources, [$finding]);
        KnowledgeDocument::whereKey($finding['document_id'])->update(['project_key' => 'orders', 'status' => 'archived']);
        $publisher->publish($run, $sources, [$finding]);
        KnowledgeDocument::whereKey($finding['document_id'])->update(['status' => 'active']);
        KnowledgeChunk::where('knowledge_document_id', $finding['document_id'])->update(['chunk_text' => 'Changed source.']);
        $publisher->publish($run, $sources, [$finding]);
        Http::assertNothingSent();
        $this->assertSame(0, $run->events()->count());
    }

    public function test_completed_or_cancelled_runs_never_receive_findings(): void
    {
        [$run, $sources, $finding] = $this->fixture();
        Http::preventStrayRequests();
        foreach ([AgentRun::STATUS_COMPLETED, AgentRun::STATUS_CANCELLED] as $status) {
            $run->forceFill(['status' => $status])->save();
            app(ProgressiveAnswerPublisher::class)->publish($run, $sources, [$finding]);
        }
        Http::assertNothingSent();
        $this->assertSame(0, $run->events()->count());
    }

    public function test_permission_revocation_during_verification_prevents_publication(): void
    {
        [$run, $sources, $finding] = $this->fixture();
        Http::fake(function () use ($finding) {
            KnowledgeDocument::whereKey($finding['document_id'])->update(['status' => 'archived']);
            return Http::response(['id' => 'decision-1', 'model' => 'jev-test',
                'answers' => ['finding_0' => ['type' => 'noul', 'noul' => .99]]]);
        });
        app(ProgressiveAnswerPublisher::class)->publish($run, $sources, [$finding]);
        $this->assertSame(0, $run->events()->count());
        $this->assertSame('source_changed_or_revoked', data_get($run->fresh()->result_json, 'progressive_audit.0.status'));
    }

    public function test_oversized_context_and_mismatched_source_identity_do_not_trigger_a_decision(): void
    {
        [$run, $sources, $finding] = $this->fixture();
        Http::preventStrayRequests();
        $publisher = app(ProgressiveAnswerPublisher::class);
        $publisher->publish($run, [99999 => $sources[$finding['document_id']]], [[...$finding, 'document_id' => 99999]]);
        config(['decisions.max_state_bytes' => 32]);
        $publisher->publish($run, $sources, [$finding]);
        Http::assertNothingSent();
        $this->assertSame(0, $run->events()->count());
    }

    public function test_registered_branches_publish_to_the_parent_without_exposing_unlinked_branch_results(): void
    {
        [$parent, $sources, $finding] = $this->fixture();
        $child = AgentRun::create(['run_id' => (string) Str::uuid(), 'tenant_id' => 'acme', 'project_key' => 'orders',
            'channel' => 'chat', 'actor_type' => 'user', 'actor_id' => '1', 'locale' => 'it', 'timezone' => 'UTC',
            'status' => AgentRun::STATUS_RUNNING, 'input_json' => ['research_parent_id' => $parent->id, 'research_flow_id' => 0]]);
        Http::fake(['*' => Http::response(['id' => 'decision-1', 'model' => 'jev-test',
            'answers' => ['finding_0' => ['type' => 'noul', 'noul' => .99]]])]);
        $publisher = app(ProgressiveAnswerPublisher::class);
        $publisher->publish($child, $sources, [$finding]);
        Http::assertNothingSent();
        $parent->forceFill(['result_json' => ['research_runs' => [$child->id]]])->save();
        $publisher->publish($child, $sources, [$finding]);
        $this->assertSame(1, $parent->events()->count());
        $this->assertSame(0, $child->events()->count());
        Http::assertSentCount(1);
    }

    public function test_a_mismatched_worker_tenant_cannot_publish_foreign_sources_into_the_run(): void
    {
        [$run, $sources, $finding] = $this->fixture();
        KnowledgeDocument::whereKey($finding['document_id'])->update(['tenant_id' => 'another-tenant']);
        KnowledgeChunk::where('knowledge_document_id', $finding['document_id'])->update(['tenant_id' => 'another-tenant']);
        app(TenantContext::class)->set('another-tenant');
        Http::preventStrayRequests();
        app(ProgressiveAnswerPublisher::class)->publish($run, $sources, [$finding]);
        Http::assertNothingSent();
        $this->assertSame(0, $run->events()->count());
    }

    private function fixture(): array
    {
        config(['agent.progressive.enabled' => true, 'ai.providers.openrouter.key' => 'test']);
        app(TenantContext::class)->set('acme');
        $text = 'Il 3 luglio Michael Friedenberger conferma che il barcode è presente.';
        $document = KnowledgeDocument::create(['tenant_id' => 'acme', 'project_key' => 'orders', 'source_type' => 'markdown',
            'title' => 'Email del 3 luglio', 'source_path' => 'mail/reply', 'status' => 'active', 'document_hash' => hash('sha256', $text)]);
        $chunk = KnowledgeChunk::create(['tenant_id' => 'acme', 'project_key' => 'orders', 'knowledge_document_id' => $document->id,
            'chunk_order' => 0, 'chunk_text' => $text, 'chunk_hash' => hash('sha256', $text)]);
        $run = AgentRun::create(['run_id' => (string) Str::uuid(), 'tenant_id' => 'acme', 'project_key' => 'orders',
            'channel' => 'chat', 'actor_type' => 'user', 'actor_id' => '1', 'locale' => 'it', 'timezone' => 'UTC',
            'status' => AgentRun::STATUS_RUNNING, 'input_json' => ['question' => 'Che cosa sappiamo su Michael Friedenberger?']]);
        return [$run, [$document->id => ['excerpt' => $text,
            'candidate' => ['document' => ['id' => $document->id], 'chunk_id' => $chunk->id]]],
            ['document_id' => $document->id, 'text' => 'Il 3 luglio il cliente conferma di avere il barcode.', 'quote' => $text]];
    }
}
