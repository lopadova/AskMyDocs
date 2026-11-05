<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentRunCancelledException;
use App\Agent\AgentResearchCoordinator;
use App\Agent\Debug\RunDiagnosticSnapshot;
use App\Agent\Evidence\AgentEvidenceFactory;
use App\Agent\Debug\RunDiagnostics;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Ai\EmbeddingsResponse;
use App\Models\AgentDiagnosticSpan;
use App\Models\AgentRun;
use App\Models\KbRetrievalProfile;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Chat\ChatQuestionPreprocessor;
use App\Services\Chat\QuestionUnderstanding;
use App\Services\Chat\Reasoning\ResearchFailure;
use App\Services\Chat\Reasoning\ResearchFanout;
use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\EmbeddingCacheService;
use App\Services\Kb\Investigation\KbInvestigationService;
use App\Services\Kb\Investigation\KbSourceReader;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Reranker;
use App\Services\Kb\Retrieval\SearchResult;
use App\Services\Kb\Retrieval\SynonymExpander;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Laravel\SerializableClosure\SerializableClosure;
use Mockery;
use Tests\TestCase;

final class KnowledgeRunDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        app(TenantContext::class)->set('diagnostics-tenant');
        config(['kb.synonyms.enabled' => false, 'kb.investigation.enabled' => true]);
        KbRetrievalProfile::create(['tenant_id' => 'diagnostics-tenant', 'project_key' => 'demo', 'company_context' => 'Fixture']);
    }

    public function test_embedding_phase_is_durable_before_the_provider_or_cache_returns(): void
    {
        $run = $this->runRecord();
        $embeddings = Mockery::mock(EmbeddingCacheService::class);
        $embeddings->shouldReceive('generate')->once()->with(['private search words'])->andReturnUsing(function () use ($run) {
            // A fresh DB read while the operation has not returned is the
            // same evidence available to a concurrent downloadable snapshot.
            $pending = AgentDiagnosticSpan::query()->where('agent_run_id', $run->id)->where('phase', 'kb.embedding')->sole();
            $this->assertSame('started', $pending->status);
            $this->assertNull($pending->ended_at);
            $this->assertSame('embedding_cache_or_provider', $pending->metadata_json['wait_reason']);
            $this->assertStringNotContainsString('private search words', $pending->toJson());
            $snapshot = app(RunDiagnosticSnapshot::class)->build($run->fresh()->load(['events', 'toolExecutions']), now(), collect([$run]));
            $this->assertSame('kb.embedding', $snapshot['waiting']['observed_phase']);
            $this->assertSame('unknown', $snapshot['waiting']['cause']);
            $this->assertSame('embedding_cache_or_provider', $snapshot['waiting']['reported_wait_reason']);
            $this->assertSame($pending->id, $snapshot['open_spans'][0]['id']);

            return new EmbeddingsResponse([[.1, .2, .3]], 'fake', 'fake-embedding');
        });
        $search = new KbSearchService($embeddings, new Reranker(), synonymExpander: new SynonymExpander());
        $result = (new ChatRetrievalService($search))->retrieve('private search words', 'demo', includeAuxiliaryContext: false,
            diagnostics: app(RunDiagnostics::class)->observer($run));

        $this->assertTrue($result->primary->isEmpty());
        $spans = $this->spans($run);
        foreach (['kb.embedding', 'kb.primary_search', 'kb.vector_query', 'kb.rerank'] as $phase) {
            $span = $spans->firstWhere('phase', $phase);
            $this->assertNotNull($span, $phase);
            $this->assertSame('completed', $span->status);
            $this->assertNotNull($span->duration_ms);
        }
        $this->assertStringNotContainsString('private search words', $spans->toJson());
        Http::assertNothingSent();
    }

    public function test_absorbed_lookup_exception_is_failed_and_contains_only_the_error_class(): void
    {
        $run = $this->runRecord();
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldNotReceive('chat');
        $retrieval = Mockery::mock(ChatRetrievalService::class);
        $retrieval->shouldReceive('retrieve')->once()->andThrow(new \RuntimeException('Bearer private-token query secret@example.test'));
        $reader = Mockery::mock(KbSourceReader::class);
        $reader->shouldNotReceive('readCandidate');

        $result = $this->investigation($ai, $retrieval, $reader)->investigate('private search words', 'demo',
            preparedUnderstanding: $this->understanding(), diagnostics: app(RunDiagnostics::class)->observer($run));

        $this->assertSame('retrieval_error', $result->stopReason);
        $span = $this->spans($run)->firstWhere('phase', 'kb.semantic_lookup');
        $this->assertSame('failed', $span->status);
        $this->assertTrue($span->metadata_json['error_handled']);
        $this->assertSame(\RuntimeException::class, $span->metadata_json['error_class']);
        $this->assertSame(1, $span->metadata_json['iteration']);
        foreach (['private-token', 'private search words', 'secret@example.test', 'Bearer'] as $private) {
            $this->assertStringNotContainsString($private, $this->spans($run)->toJson());
        }
        Http::assertNothingSent();
    }

    public function test_assessment_provider_failure_remains_visible_when_the_investigation_returns_normally(): void
    {
        $run = $this->runRecord();
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chat')->once()->andThrow(new \RuntimeException('password=private-password'));
        $retrieval = Mockery::mock(ChatRetrievalService::class);
        $retrieval->shouldReceive('retrieve')->once()->andReturn($this->search());
        $reader = Mockery::mock(KbSourceReader::class);
        $reader->shouldReceive('readCandidate')->once()->andReturn($this->source());

        $result = $this->investigation($ai, $retrieval, $reader)->investigate('private search words', 'demo',
            preparedUnderstanding: $this->understanding(), diagnostics: app(RunDiagnostics::class)->observer($run));

        $this->assertSame('assessment_failed', $result->stopReason);
        $provider = $this->spans($run)->firstWhere('phase', 'kb.assessment_provider');
        $this->assertSame('failed', $provider->status);
        $this->assertTrue($provider->metadata_json['error_handled']);
        $this->assertSame('initial', $provider->metadata_json['operation']);
        $this->assertSame('completed', $this->spans($run)->firstWhere('phase', 'kb.assessment')->status);
        $this->assertStringNotContainsString('private-password', $this->spans($run)->toJson());
        Http::assertNothingSent();
    }

    public function test_existing_absorbed_cancellation_is_labelled_without_changing_the_retrieval_policy(): void
    {
        $run = $this->runRecord();
        $retrieval = Mockery::mock(ChatRetrievalService::class);
        $retrieval->shouldReceive('retrieve')->once()->andThrow(new AgentRunCancelledException($run->run_id));
        $result = $this->investigation(Mockery::mock(AiManager::class), $retrieval, Mockery::mock(KbSourceReader::class))
            ->investigate('private search words', 'demo', preparedUnderstanding: $this->understanding(),
                diagnostics: app(RunDiagnostics::class)->observer($run));

        $this->assertSame('retrieval_error', $result->stopReason);
        $span = $this->spans($run)->firstWhere('phase', 'kb.semantic_lookup');
        $this->assertSame('cancelled', $span->status);
        $this->assertSame('cancelled', $span->metadata_json['error_kind']);
        $this->assertTrue($span->metadata_json['error_handled']);
        Http::assertNothingSent();
    }

    public function test_serialized_observer_survives_research_branches_and_correlates_each_flow(): void
    {
        config(['reasoning.parallel_research' => true]);
        $run = $this->runRecord();
        $observer = app(RunDiagnostics::class)->observer($run);
        $serialized = serialize(new SerializableClosure($observer));
        $this->assertStringNotContainsString('private search words', $serialized);
        $observer = unserialize($serialized)->getClosure();
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chat')->twice()->andReturn(new AiResponse(json_encode([
            'selected_document_ids' => [11], 'supported_facts' => [], 'complete' => true,
        ]), 'fake', 'fake-model'));
        $retrieval = Mockery::mock(ChatRetrievalService::class);
        $retrieval->shouldReceive('retrieve')->twice()->andReturn($this->search());
        $reader = Mockery::mock(KbSourceReader::class);
        $reader->shouldReceive('readCandidate')->twice()->andReturn($this->source());
        $service = $this->investigation($ai, $retrieval, $reader);
        $this->app->instance(KbInvestigationService::class, $service);
        $fanout = Mockery::mock(ResearchFanout::class);
        $fanout->shouldReceive('run')->once()->andReturnUsing(static function (array $tasks): array {
            $results = [];
            foreach ($tasks as $key => $task) {
                // Exercise the same nested closure/payload serialization as
                // process dispatch, without starting another application.
                $transported = unserialize(serialize(new SerializableClosure($task)))->getClosure();
                $results[$key] = $transported();
            }
            return $results;
        });
        $this->app->instance(ResearchFanout::class, $fanout);
        $understanding = new QuestionUnderstanding('en', 'Independent questions', ['first', 'second'], [], false,
            subquestions: [
                ['question' => 'first', 'topic' => 'order', 'identifiers' => [], 'aspect' => 'status', 'fields' => [], 'kb_queries' => ['first']],
                ['question' => 'second', 'topic' => 'order', 'identifiers' => [], 'aspect' => 'status', 'fields' => [], 'kb_queries' => ['second']],
            ]);

        $result = $service->investigate('private search words', 'demo', preparedUnderstanding: $understanding, diagnostics: $observer);

        $this->assertSame('ready', $result->status);
        $spans = $this->spans($run);
        $branches = $spans->where('phase', 'kb.research_branch');
        $this->assertCount(2, $branches);
        $this->assertSame([0, 1], $branches->map(fn ($span) => $span->metadata_json['research_flow_id'])->sort()->values()->all());
        $this->assertSame(['completed'], $branches->pluck('status')->unique()->values()->all());
        foreach ($spans->where('phase', 'kb.semantic_lookup') as $span) {
            $this->assertContains($span->metadata_json['research_flow_id'], [0, 1]);
            $this->assertSame(1, $span->metadata_json['iteration']);
        }
        $this->assertSame('inline_test', $spans->firstWhere('phase', 'kb.research_fanout')->metadata_json['driver']);
        $this->assertStringNotContainsString('private search words', $spans->toJson());
        Http::assertNothingSent();
    }

    public function test_fanout_handoff_is_observable_before_children_return_and_caught_failure_is_explicit(): void
    {
        $run = $this->runRecord();
        $understanding = new QuestionUnderstanding('en', 'Two questions', [], [], false, subquestions: [
            ['question' => 'first', 'topic' => 'order', 'identifiers' => [], 'aspect' => 'status', 'fields' => [], 'kb_queries' => ['first']],
            ['question' => 'second', 'topic' => 'order', 'identifiers' => [], 'aspect' => 'status', 'fields' => [], 'kb_queries' => ['second']],
        ]);
        $run->forceFill(['result_json' => ['question_understanding' => $understanding->toArray()]])->save();
        $fanout = Mockery::mock(ResearchFanout::class);
        $fanout->shouldReceive('run')->once()->andReturnUsing(function (array $tasks) use ($run) {
            $this->assertCount(2, $tasks);
            $pending = $this->spans($run)->where('phase', 'research.child_wait');
            $this->assertCount(2, $pending);
            $this->assertSame(['started'], $pending->pluck('status')->unique()->values()->all());
            foreach ($pending as $span) {
                $child = AgentRun::findOrFail($span->metadata_json['child_run_id']);
                $this->assertSame('queued', $child->status);
                $this->assertSame($run->id, $child->input_json['research_parent_id']);
                $this->assertNotNull($span->parent_span_id);
            }
            throw new \RuntimeException('private fanout exception');
        });
        $this->app->instance(ResearchFanout::class, $fanout);

        $outcome = app(AgentResearchCoordinator::class)->collect($run, app(AgentEvidenceFactory::class)->empty(), $understanding);

        $this->assertSame('partial', $outcome->decision);
        $fanoutSpan = $this->spans($run)->firstWhere('phase', 'research.fanout');
        $this->assertSame('failed', $fanoutSpan->status);
        $this->assertTrue($fanoutSpan->metadata_json['error_handled']);
        foreach ($this->spans($run)->where('phase', 'research.child_wait') as $span) {
            $this->assertSame('completed', $span->status);
            $this->assertSame('incomplete_after_fanout', $span->metadata_json['operation']);
        }
        $this->assertStringNotContainsString('private fanout exception', $this->spans($run)->toJson());
        Http::assertNothingSent();
    }

    public function test_process_dispatch_wait_timeout_and_unsuccessful_result_are_distinct_without_running_processes(): void
    {
        config(['reasoning.research_timeout_seconds' => 1, 'reasoning.research_concurrency' => 4]);
        $run = $this->runRecord();
        $timedOut = Mockery::mock(\Illuminate\Process\InvokedProcess::class);
        $unsuccessful = Mockery::mock(\Illuminate\Process\InvokedProcess::class);
        $successful = Mockery::mock(\Illuminate\Process\InvokedProcess::class);
        $timeoutProcess = new \Symfony\Component\Process\Process(['fixture']);
        $timeoutProcess->setTimeout(1);
        $timeout = new \Illuminate\Process\Exceptions\ProcessTimedOutException(
            new \Symfony\Component\Process\Exception\ProcessTimedOutException($timeoutProcess,
                \Symfony\Component\Process\Exception\ProcessTimedOutException::TYPE_GENERAL),
            Mockery::mock(\Illuminate\Contracts\Process\ProcessResult::class),
        );
        $timedOut->shouldReceive('wait')->once()->andReturnUsing(function () use ($run, $timeout) {
            $pending = $this->spans($run)->where('phase', 'research.process_wait')->firstWhere('status', 'started');
            $this->assertNotNull($pending);
            $this->assertSame('process_result', $pending->metadata_json['wait_reason']);
            throw $timeout;
        });
        $timedOut->shouldReceive('stop')->once();
        $badResult = Mockery::mock(\Illuminate\Contracts\Process\ProcessResult::class);
        $badResult->shouldReceive('output')->once()->andReturn('{"successful":false}');
        $badResult->shouldReceive('successful')->once()->andReturn(false);
        $unsuccessful->shouldReceive('wait')->once()->andReturn($badResult);
        $okResult = Mockery::mock(\Illuminate\Contracts\Process\ProcessResult::class);
        $okResult->shouldReceive('output')->once()->andReturn(json_encode(['successful' => true, 'result' => serialize('sibling result')]));
        $okResult->shouldReceive('successful')->once()->andReturn(true);
        $successful->shouldReceive('wait')->once()->andReturn($okResult);
        $pending = Mockery::mock(\Illuminate\Process\PendingProcess::class);
        $factory = Mockery::mock(\Illuminate\Process\Factory::class);
        $factory->shouldReceive('path')->times(4)->with(base_path())->andReturn($pending);
        $pending->shouldReceive('env')->times(4)->andReturnSelf();
        $pending->shouldReceive('timeout')->times(4)->with(1)->andReturnSelf();
        $starts = [new \RuntimeException('private start error'), $timedOut, $unsuccessful, $successful];
        $pending->shouldReceive('start')->times(4)->andReturnUsing(static function () use (&$starts) {
            $result = array_shift($starts);
            if ($result instanceof \Throwable) {
                throw $result;
            }
            return $result;
        });
        $previous = Process::getFacadeRoot();
        try {
            Process::swap($factory);
            $results = app(ResearchFanout::class)->run(array_fill(0, 4, static fn () => 'unused fixture'), forceProcess: true,
                diagnostics: app(RunDiagnostics::class)->observer($run));
        } finally {
            Process::swap($previous);
        }

        foreach ([0, 1, 2] as $index) {
            $this->assertInstanceOf(ResearchFailure::class, $results[$index]);
        }
        $this->assertSame('sibling result', $results[3]);
        $dispatch = $this->spans($run)->where('phase', 'research.process_dispatch')->values();
        $this->assertSame(['failed', 'completed', 'completed', 'completed'], $dispatch->pluck('status')->all());
        $this->assertTrue($dispatch[0]->metadata_json['error_handled']);
        $waits = $this->spans($run)->where('phase', 'research.process_wait')->values();
        $this->assertSame(['failed', 'failed', 'completed'], $waits->pluck('status')->all());
        $this->assertSame('timeout', $waits[0]->metadata_json['error_kind']);
        $this->assertSame('research_branch_failed', $waits[1]->metadata_json['operation']);
        $this->assertStringNotContainsString('private start error', $this->spans($run)->toJson());
        Http::assertNothingSent();
    }

    public function test_original_source_storage_error_is_traced_even_when_reader_rejects_the_candidate(): void
    {
        $run = $this->runRecord();
        $document = KnowledgeDocument::create([
            'tenant_id' => $run->tenant_id, 'project_key' => 'demo', 'source_type' => 'text', 'status' => 'active',
            'title' => 'Private source title', 'source_path' => 'private/source', 'mime_type' => 'text/plain',
            'document_hash' => str_repeat('a', 64), 'version_hash' => bin2hex(random_bytes(16)),
            'metadata' => ['connector' => 'imap', 'disk' => 'fixture', 'email_triage' => [
                'evaluation_id' => 1, 'original_hash' => hash('sha256', 'private original'),
            ]],
        ]);
        $chunk = KnowledgeChunk::create([
            'tenant_id' => $run->tenant_id, 'project_key' => 'demo', 'knowledge_document_id' => $document->id,
            'chunk_order' => 0, 'chunk_text' => 'private original', 'chunk_hash' => hash('sha256', 'private original'),
        ]);
        $disk = Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('exists')->once()->andThrow(new \RuntimeException('private storage credentials'));
        $previous = Storage::getFacadeRoot();
        try {
            Storage::shouldReceive('disk')->once()->with('fixture')->andReturn($disk);
            $result = app(KbSourceReader::class)->readCandidate(['document' => ['id' => $document->id], 'chunk_id' => $chunk->id],
                diagnostics: app(RunDiagnostics::class)->observer($run));
        } finally {
            Storage::swap($previous);
        }

        $this->assertNull($result);
        $span = $this->spans($run)->sole();
        $this->assertSame('kb.original_source_read', $span->phase);
        $this->assertSame('failed', $span->status);
        $this->assertTrue($span->metadata_json['error_handled']);
        foreach (['private storage credentials', 'Private source title', 'private original', 'private/source'] as $private) {
            $this->assertStringNotContainsString($private, $span->toJson());
        }
        Http::assertNothingSent();
    }

    private function investigation(AiManager $ai, ChatRetrievalService $retrieval, KbSourceReader $reader): KbInvestigationService
    {
        return new KbInvestigationService($ai, $retrieval, $reader, app(TenantContext::class), new ChatQuestionPreprocessor($ai));
    }

    private function runRecord(): AgentRun
    {
        return AgentRun::create([
            'tenant_id' => 'diagnostics-tenant', 'run_id' => (string) Str::uuid(), 'project_key' => 'demo',
            'channel' => 'chat', 'actor_type' => 'user', 'actor_id' => '1', 'locale' => 'en', 'timezone' => 'UTC',
            'status' => 'running', 'started_at' => now(), 'input_json' => ['question' => 'private search words'],
        ]);
    }

    private function understanding(): QuestionUnderstanding
    {
        return new QuestionUnderstanding('en', 'private search words', ['private search words'], [], false);
    }

    private function spans(AgentRun $run): \Illuminate\Support\Collection
    {
        return AgentDiagnosticSpan::query()->where('agent_run_id', $run->id)->orderBy('started_at')->get();
    }

    private function search(): SearchResult
    {
        return new SearchResult(collect([['chunk_id' => 110, 'chunk_text' => 'private source text',
            'document' => ['id' => 11, 'title' => 'Private fixture']]]), collect(), collect());
    }

    private function source(): array
    {
        return ['document_id' => 11, 'title' => 'Private fixture', 'source_path' => 'fixture/private', 'source_type' => 'text',
            'excerpt' => 'private source text', 'complete_source' => true, 'chunk_ids' => [110], 'candidate' => []];
    }
}
