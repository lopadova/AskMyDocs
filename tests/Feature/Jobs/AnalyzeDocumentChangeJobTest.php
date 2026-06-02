<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\AnalyzeDocumentChangeJob;
use App\Models\KbDocAnalysis;
use App\Models\KnowledgeDocument;
use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Notifications\ChannelRegistry;
use App\Services\Kb\Analysis\KbChangeAnalyzer;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\Support\Notifications\RecordingChannel;
use Tests\TestCase;

/**
 * v8.7/W3–W4 — `AnalyzeDocumentChangeJob` orchestration.
 *
 * The LLM/embedding plumbing lives in `KbChangeAnalyzer` (tested
 * separately); here the analyzer is mocked so the JOB's contract is
 * pinned: canonical-default ON, non-canonical opt-in (R26
 * `shouldNotReceive`), master kill-switch, debounce, persistence,
 * notification, and failure recording.
 */
final class AnalyzeDocumentChangeJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
        app(ChannelRegistry::class)->register(new RecordingChannel(NotificationPreference::CHANNEL_IN_APP));
        config()->set('kb.change_analysis.enabled', true);
        config()->set('kb.change_analysis.canonical_default', true);
        config()->set('kb.change_analysis.non_canonical_default', false);
        config()->set('kb.change_analysis.debounce_minutes', 60);
    }

    /**
     * @return array{enhancement_suggestions: list<string>, cross_references: array, impacted_docs: array}
     */
    private function sampleAnalysis(): array
    {
        return [
            'enhancement_suggestions' => ['Add a worked example', 'Link the runbook'],
            'cross_references' => [['slug' => 'dec-cache', 'title' => 'Cache decision', 'why' => 'related caching']],
            'impacted_docs' => [['slug' => 'old-cache', 'title' => 'Old cache', 'impact' => 'superseded', 'suggested_action' => 'deprecate it']],
        ];
    }

    private function mockAnalyzer(): Mockery\MockInterface
    {
        $mock = Mockery::mock(KbChangeAnalyzer::class);
        $this->app->instance(KbChangeAnalyzer::class, $mock);

        return $mock;
    }

    private function makeCanonicalDoc(string $project = 'proj-an'): KnowledgeDocument
    {
        return KnowledgeDocument::create([
            'project_key' => $project,
            'source_path' => 'docs/dec.md',
            'source_type' => 'markdown',
            'title' => 'Decision',
            'mime_type' => 'text/markdown',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', $project.'dec'),
            'version_hash' => hash('sha256', $project.'dec'),
            'metadata' => [],
            'indexed_at' => now(),
            'is_canonical' => true,
            'doc_id' => 'dec-1',
            'slug' => 'dec-1',
            'canonical_type' => 'decision',
            'canonical_status' => 'accepted',
        ]);
    }

    private function seedReviewer(string $project): User
    {
        $user = User::create([
            'name' => 'reviewer',
            'email' => 'rev-'.uniqid('', true).'@test.local',
            'password' => Hash::make('secret123'),
        ]);
        ProjectMembership::create([
            'user_id' => $user->id,
            'project_key' => $project,
            'role' => 'member',
            'scope_allowlist' => null,
        ]);
        NotificationPreference::create([
            'user_id' => $user->id,
            'event_type' => NotificationEvent::EVENT_KB_DOC_ANALYSIS_READY,
            'channel' => NotificationPreference::CHANNEL_IN_APP,
            'enabled' => true,
        ]);

        return $user;
    }

    public function test_canonical_doc_is_analysed_persisted_and_notified(): void
    {
        $doc = $this->makeCanonicalDoc();
        $reviewer = $this->seedReviewer('proj-an');

        $analyzer = $this->mockAnalyzer();
        $analyzer->shouldReceive('analyze')->once()
            ->andReturn(['analysis' => $this->sampleAnalysis(), 'provider' => 'test', 'model' => 'test-model']);

        (new AnalyzeDocumentChangeJob($doc->id, 'default'))->handle(
            app(TenantContext::class), $analyzer, app(\App\Notifications\NotificationPublisher::class), app(\App\Services\Kb\Analysis\ChangeAnalysisGate::class)
        );

        $row = KbDocAnalysis::where('knowledge_document_id', $doc->id)->sole();
        $this->assertSame(KbDocAnalysis::STATUS_COMPLETED, $row->status);
        $this->assertSame(2, $row->suggestion_count);
        $this->assertSame(1, $row->impacted_count);
        $this->assertSame('test-model', $row->model);

        $notif = NotificationEvent::where('event_type', NotificationEvent::EVENT_KB_DOC_ANALYSIS_READY)->sole();
        $this->assertSame($reviewer->id, $notif->user_id);
        $this->assertSame($row->id, $notif->payload['analysis_id'] ?? null);
    }

    public function test_non_canonical_doc_is_skipped_by_default(): void
    {
        $doc = $this->makeCanonicalDoc();
        $doc->update(['is_canonical' => false]);

        $analyzer = $this->mockAnalyzer();
        // R26 — the expensive LLM path must NOT run for opt-out doc types.
        $analyzer->shouldNotReceive('analyze');

        (new AnalyzeDocumentChangeJob($doc->id, 'default'))->handle(
            app(TenantContext::class), $analyzer, app(\App\Notifications\NotificationPublisher::class), app(\App\Services\Kb\Analysis\ChangeAnalysisGate::class)
        );

        $this->assertSame(0, KbDocAnalysis::count());
    }

    public function test_non_canonical_doc_runs_when_opted_in(): void
    {
        config()->set('kb.change_analysis.non_canonical_default', true);
        $doc = $this->makeCanonicalDoc();
        $doc->update(['is_canonical' => false]);

        $analyzer = $this->mockAnalyzer();
        $analyzer->shouldReceive('analyze')->once()
            ->andReturn(['analysis' => $this->sampleAnalysis(), 'provider' => 'test', 'model' => 'm']);

        (new AnalyzeDocumentChangeJob($doc->id, 'default'))->handle(
            app(TenantContext::class), $analyzer, app(\App\Notifications\NotificationPublisher::class), app(\App\Services\Kb\Analysis\ChangeAnalysisGate::class)
        );

        $this->assertSame(1, KbDocAnalysis::count());
    }

    public function test_master_kill_switch_skips_everything(): void
    {
        config()->set('kb.change_analysis.enabled', false);
        $doc = $this->makeCanonicalDoc();

        $analyzer = $this->mockAnalyzer();
        $analyzer->shouldNotReceive('analyze');

        (new AnalyzeDocumentChangeJob($doc->id, 'default'))->handle(
            app(TenantContext::class), $analyzer, app(\App\Notifications\NotificationPublisher::class), app(\App\Services\Kb\Analysis\ChangeAnalysisGate::class)
        );

        $this->assertSame(0, KbDocAnalysis::count());
    }

    public function test_debounce_skips_recently_analysed_doc(): void
    {
        $doc = $this->makeCanonicalDoc(); // slug 'dec-1'
        KbDocAnalysis::create([
            'project_key' => 'proj-an',
            'knowledge_document_id' => $doc->id,
            'doc_slug' => 'dec-1', // debounce keys on (project, slug) for canonical docs
            'trigger' => KbDocAnalysis::TRIGGER_INGESTED,
            'analysis_json' => ['enhancement_suggestions' => [], 'cross_references' => [], 'impacted_docs' => []],
            'status' => KbDocAnalysis::STATUS_COMPLETED,
        ]);

        $analyzer = $this->mockAnalyzer();
        $analyzer->shouldNotReceive('analyze');

        (new AnalyzeDocumentChangeJob($doc->id, 'default'))->handle(
            app(TenantContext::class), $analyzer, app(\App\Notifications\NotificationPublisher::class), app(\App\Services\Kb\Analysis\ChangeAnalysisGate::class)
        );

        // Still only the pre-seeded row.
        $this->assertSame(1, KbDocAnalysis::count());
    }

    public function test_debounce_keys_on_slug_so_a_reingest_is_skipped(): void
    {
        // A canonical re-ingest creates a NEW doc row (new id) with the SAME
        // (project, slug). Debounce must key on the stable slug, not the row
        // id, so the rapid re-ingest does NOT trigger a second LLM run.
        $doc = $this->makeCanonicalDoc(); // slug 'dec-1', project 'proj-an'
        KbDocAnalysis::create([
            'project_key' => 'proj-an',
            'knowledge_document_id' => 999999, // a prior version's row id
            'doc_slug' => 'dec-1',
            'trigger' => KbDocAnalysis::TRIGGER_INGESTED,
            'analysis_json' => ['enhancement_suggestions' => [], 'cross_references' => [], 'impacted_docs' => []],
            'status' => KbDocAnalysis::STATUS_COMPLETED,
        ]);

        $analyzer = $this->mockAnalyzer();
        $analyzer->shouldNotReceive('analyze');

        (new AnalyzeDocumentChangeJob($doc->id, 'default'))->handle(
            app(TenantContext::class), $analyzer, app(\App\Notifications\NotificationPublisher::class), app(\App\Services\Kb\Analysis\ChangeAnalysisGate::class)
        );

        $this->assertSame(1, KbDocAnalysis::count());
    }

    public function test_analysis_failure_records_a_failed_row(): void
    {
        $doc = $this->makeCanonicalDoc();

        $analyzer = $this->mockAnalyzer();
        $analyzer->shouldReceive('analyze')->once()->andThrow(new \RuntimeException('provider down'));

        (new AnalyzeDocumentChangeJob($doc->id, 'default'))->handle(
            app(TenantContext::class), $analyzer, app(\App\Notifications\NotificationPublisher::class), app(\App\Services\Kb\Analysis\ChangeAnalysisGate::class)
        );

        $row = KbDocAnalysis::where('knowledge_document_id', $doc->id)->sole();
        $this->assertSame(KbDocAnalysis::STATUS_FAILED, $row->status);
        $this->assertStringContainsString('provider down', (string) $row->error);
    }
}
