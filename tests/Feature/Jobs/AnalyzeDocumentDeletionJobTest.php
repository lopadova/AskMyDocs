<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\AnalyzeDocumentDeletionJob;
use App\Models\KbDocAnalysis;
use App\Models\KnowledgeDocument;
use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Notifications\ChannelRegistry;
use App\Notifications\NotificationPublisher;
use App\Services\Kb\Analysis\KbChangeAnalyzer;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\Support\Notifications\RecordingChannel;
use Tests\TestCase;

/**
 * v8.8/W2 — `AnalyzeDocumentDeletionJob` orchestration.
 *
 * The LLM/embedding plumbing lives in `KbChangeAnalyzer::analyzeDeletion()`
 * (tested separately); here the analyzer is mocked so the JOB's contract is
 * pinned: canonical-default ON, non-canonical opt-in (R26 `shouldNotReceive`),
 * master kill-switch, the delete-specific switch, persistence (trigger
 * `deleted`), notification, and failure recording — all driven off a
 * pre-delete SNAPSHOT (the document may be gone by the time the job runs).
 */
final class AnalyzeDocumentDeletionJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
        app(ChannelRegistry::class)->register(new RecordingChannel(NotificationPreference::CHANNEL_IN_APP));
        config()->set('kb.change_analysis.enabled', true);
        config()->set('kb.change_analysis.delete_enabled', true);
        config()->set('kb.change_analysis.canonical_default', true);
        config()->set('kb.change_analysis.non_canonical_default', false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{tenant_id: string, project_key: string, knowledge_document_id: int, doc_slug: ?string, title: string, source_path: string, is_canonical: bool, doc_text: string}
     */
    private function snapshot(array $overrides = []): array
    {
        return array_merge([
            'tenant_id' => 'default',
            'project_key' => 'proj-del',
            'knowledge_document_id' => 4242,
            'doc_slug' => 'dec-gone',
            'title' => 'Deprecated cache decision',
            'source_path' => 'docs/dec-gone.md',
            'is_canonical' => true,
            'doc_text' => 'We chose Redis. This is now removed.',
        ], $overrides);
    }

    /**
     * @return array{enhancement_suggestions: list<string>, cross_references: array, impacted_docs: array}
     */
    private function sampleDeletionAnalysis(): array
    {
        return [
            'enhancement_suggestions' => [], // always empty for a deletion
            'cross_references' => [['slug' => 'runbook-cache', 'title' => 'Cache runbook', 'why' => 'linked the decision']],
            'impacted_docs' => [['slug' => 'runbook-cache', 'title' => 'Cache runbook', 'impact' => 'dangling link to deleted decision', 'suggested_action' => 'update: drop the reference']],
        ];
    }

    private function mockAnalyzer(): Mockery\MockInterface
    {
        $mock = Mockery::mock(KbChangeAnalyzer::class);
        $this->app->instance(KbChangeAnalyzer::class, $mock);

        return $mock;
    }

    private function runJob(array $snapshot): void
    {
        (new AnalyzeDocumentDeletionJob($snapshot))->handle(
            app(TenantContext::class),
            app(KbChangeAnalyzer::class),
            app(NotificationPublisher::class),
        );
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

    public function test_canonical_deletion_is_analysed_persisted_and_notified(): void
    {
        $reviewer = $this->seedReviewer('proj-del');

        $analyzer = $this->mockAnalyzer();
        $analyzer->shouldReceive('analyzeDeletion')->once()
            ->andReturn(['analysis' => $this->sampleDeletionAnalysis(), 'provider' => 'test', 'model' => 'test-model']);

        $this->runJob($this->snapshot());

        $row = KbDocAnalysis::where('knowledge_document_id', 4242)->sole();
        $this->assertSame(KbDocAnalysis::TRIGGER_DELETED, $row->trigger);
        $this->assertSame(KbDocAnalysis::STATUS_COMPLETED, $row->status);
        $this->assertSame(0, $row->suggestion_count);
        $this->assertSame(1, $row->impacted_count);
        $this->assertSame('dec-gone', $row->doc_slug);

        $notif = NotificationEvent::where('event_type', NotificationEvent::EVENT_KB_DOC_ANALYSIS_READY)->sole();
        $this->assertSame($reviewer->id, $notif->user_id);
        $this->assertSame($row->id, $notif->payload['analysis_id'] ?? null);
    }

    public function test_non_canonical_deletion_is_skipped_by_default(): void
    {
        $analyzer = $this->mockAnalyzer();
        // R26 — the expensive LLM path must NOT run for opt-out doc types.
        $analyzer->shouldNotReceive('analyzeDeletion');

        $this->runJob($this->snapshot(['is_canonical' => false]));

        $this->assertSame(0, KbDocAnalysis::count());
    }

    public function test_non_canonical_deletion_runs_when_opted_in(): void
    {
        config()->set('kb.change_analysis.non_canonical_default', true);

        $analyzer = $this->mockAnalyzer();
        $analyzer->shouldReceive('analyzeDeletion')->once()
            ->andReturn(['analysis' => $this->sampleDeletionAnalysis(), 'provider' => 'test', 'model' => 'm']);

        $this->runJob($this->snapshot(['is_canonical' => false]));

        $this->assertSame(1, KbDocAnalysis::count());
    }

    public function test_master_kill_switch_skips_everything(): void
    {
        config()->set('kb.change_analysis.enabled', false);

        $analyzer = $this->mockAnalyzer();
        $analyzer->shouldNotReceive('analyzeDeletion');

        $this->runJob($this->snapshot());

        $this->assertSame(0, KbDocAnalysis::count());
    }

    public function test_delete_specific_switch_skips_when_off(): void
    {
        config()->set('kb.change_analysis.delete_enabled', false);

        $analyzer = $this->mockAnalyzer();
        $analyzer->shouldNotReceive('analyzeDeletion');

        $this->runJob($this->snapshot());

        $this->assertSame(0, KbDocAnalysis::count());
    }

    public function test_analysis_failure_records_a_failed_row(): void
    {
        $analyzer = $this->mockAnalyzer();
        $analyzer->shouldReceive('analyzeDeletion')->once()->andThrow(new \RuntimeException('provider down'));

        $this->runJob($this->snapshot());

        $row = KbDocAnalysis::where('knowledge_document_id', 4242)->sole();
        $this->assertSame(KbDocAnalysis::TRIGGER_DELETED, $row->trigger);
        $this->assertSame(KbDocAnalysis::STATUS_FAILED, $row->status);
        $this->assertStringContainsString('provider down', (string) $row->error);
    }

    public function test_notification_resolves_for_a_hard_deleted_doc_via_snapshot(): void
    {
        // No KnowledgeDocument row exists for id 4242 (hard-deleted) — the
        // publisher must still resolve recipients from the transient model
        // hydrated off the snapshot.
        $reviewer = $this->seedReviewer('proj-del');

        $analyzer = $this->mockAnalyzer();
        $analyzer->shouldReceive('analyzeDeletion')->once()
            ->andReturn(['analysis' => $this->sampleDeletionAnalysis(), 'provider' => 'test', 'model' => 'm']);

        $this->assertNull(KnowledgeDocument::withTrashed()->find(4242));

        $this->runJob($this->snapshot());

        $notif = NotificationEvent::where('event_type', NotificationEvent::EVENT_KB_DOC_ANALYSIS_READY)->sole();
        $this->assertSame($reviewer->id, $notif->user_id);
    }
}
