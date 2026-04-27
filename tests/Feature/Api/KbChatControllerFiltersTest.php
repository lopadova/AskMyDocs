<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Http\Controllers\Api\KbChatController;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Retrieval\RetrievalFilters;
use App\Services\Kb\Retrieval\SearchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

/**
 * T2.2 — exercises the new KbChatRequest validation contract +
 * KbChatController's threading of RetrievalFilters into
 * KbSearchService::searchWithContext().
 *
 * Mocks the downstream collaborators (KbSearchService, AiManager,
 * ChatLogManager) so the test focuses on the HTTP boundary +
 * Request → DTO conversion + the call-shape into the search service.
 *
 * Coverage:
 *  - legacy payload back-compat (`{question, project_key}` → 200 +
 *    project_keys=[project_key] derived in the captured filters)
 *  - new filters payload (`{question, filters: {...}}` → 200 + every
 *    filter dimension threaded into the captured RetrievalFilters)
 *  - 422 on invalid filter shapes (bad source_type, non-array
 *    project_keys, non-int doc_ids, date_to before date_from)
 *  - meta `filters_selected` echoed in the response
 */
final class KbChatControllerFiltersTest extends TestCase
{
    use RefreshDatabase;

    /** @var \App\Services\Kb\Retrieval\RetrievalFilters|null */
    private ?RetrievalFilters $capturedFilters = null;

    /** @var ?string */
    private ?string $capturedProjectKey = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum isn't loaded under Testbench; register the route raw
        // so the controller logic runs without auth middleware (mirrors
        // the existing pattern in KbIngestControllerTest::setUp).
        Route::post('/api/kb/chat', KbChatController::class)->name('api.kb.chat');

        // The prompt view (resources/views/prompts/kb_rag.blade.php)
        // is already wired in TestCase via `view.paths`, so it loads
        // naturally with the empty primary/expanded/rejected stubs from
        // the mocked KbSearchService. No view stub needed.

        // Capture-and-stub: KbSearchService::searchWithContext returns an
        // empty SearchResult and records the RetrievalFilters + projectKey
        // it received so each test can assert on the threading.
        $search = Mockery::mock(KbSearchService::class);
        $search->shouldReceive('searchWithContext')
            ->andReturnUsing(function (...$args) {
                $this->capturedProjectKey = $args[1] ?? null;
                $this->capturedFilters = $args[4] ?? null;
                return new SearchResult(
                    primary: collect(),
                    expanded: collect(),
                    rejected: collect(),
                    meta: [
                        'primary_count' => 0,
                        'expanded_count' => 0,
                        'rejected_count' => 0,
                        'project_key' => $this->capturedProjectKey,
                        'filters_selected' => $this->capturedFilters?->isEmpty() ? 0 : 1,
                    ],
                );
            });
        $this->app->instance(KbSearchService::class, $search);

        // Stub AiManager → fake AiResponse so chat() doesn't hit a real
        // provider.
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chat')->andReturn(new AiResponse(
            content: 'fake answer',
            provider: 'fake',
            model: 'fake-model',
            promptTokens: 10,
            completionTokens: 20,
            totalTokens: 30,
        ));
        $this->app->instance(AiManager::class, $ai);

        // ChatLogManager is final → can't be Mockery-mocked. Instead,
        // disable chat-log via config so the real instance's log() exits
        // early (line 17 in app/Services/ChatLog/ChatLogManager.php
        // checks `config('chat-log.enabled', false)` and returns).
        config()->set('chat-log.enabled', false);
    }

    protected function tearDown(): void
    {
        $this->capturedFilters = null;
        $this->capturedProjectKey = null;
        Mockery::close();
        parent::tearDown();
    }

    public function test_legacy_project_key_payload_still_works_and_wraps_into_filters(): void
    {
        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'What is X?',
            'project_key' => 'hr-portal',
        ]);

        $resp->assertStatus(200)
            ->assertJsonStructure(['answer', 'citations', 'meta'])
            ->assertJsonPath('meta.provider', 'fake');

        $this->assertSame('hr-portal', $this->capturedProjectKey);
        $this->assertNotNull($this->capturedFilters);
        $this->assertSame(['hr-portal'], $this->capturedFilters->projectKeys);
    }

    public function test_new_filters_payload_threads_every_dimension_into_retrieval_filters(): void
    {
        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'What is X?',
            'filters' => [
                'project_keys' => ['hr-portal', 'engineering'],
                'tag_slugs' => ['policy', 'security'],
                'source_types' => ['markdown', 'pdf'],
                'canonical_types' => ['decision', 'runbook'],
                'connector_types' => ['local', 'google-drive'],
                'doc_ids' => [42, 99],
                'folder_globs' => ['hr/policies/**'],
                'date_from' => '2026-01-01',
                'date_to' => '2026-12-31',
                'languages' => ['it', 'en'],
            ],
        ]);

        $resp->assertStatus(200);

        $f = $this->capturedFilters;
        $this->assertNotNull($f);
        $this->assertSame(['hr-portal', 'engineering'], $f->projectKeys);
        $this->assertSame(['policy', 'security'], $f->tagSlugs);
        $this->assertSame(['markdown', 'pdf'], $f->sourceTypes);
        $this->assertSame(['decision', 'runbook'], $f->canonicalTypes);
        $this->assertSame(['local', 'google-drive'], $f->connectorTypes);
        $this->assertSame([42, 99], $f->docIds);
        $this->assertSame(['hr/policies/**'], $f->folderGlobs);
        $this->assertSame('2026-01-01', $f->dateFrom);
        $this->assertSame('2026-12-31', $f->dateTo);
        $this->assertSame(['it', 'en'], $f->languages);
    }

    public function test_filters_project_keys_takes_precedence_over_legacy_project_key(): void
    {
        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'X?',
            'project_key' => 'legacy-tenant',
            'filters' => ['project_keys' => ['new-tenant-A', 'new-tenant-B']],
        ]);

        $resp->assertStatus(200);
        $this->assertSame(['new-tenant-A', 'new-tenant-B'], $this->capturedFilters->projectKeys);
        // Effective project_key for chat-log + meta is the FIRST element
        // of filters.project_keys (not the legacy field).
        $this->assertSame('new-tenant-A', $this->capturedProjectKey);
    }

    public function test_meta_filters_selected_is_echoed_in_response(): void
    {
        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'X?',
            'filters' => ['source_types' => ['pdf']],
        ]);

        $resp->assertStatus(200)
            ->assertJsonPath('meta.filters_selected', 1);
    }

    public function test_rejects_invalid_source_type_with_422(): void
    {
        $this->postJson('/api/kb/chat', [
            'question' => 'X',
            'filters' => ['source_types' => ['not-a-real-type']],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['filters.source_types.0']);
    }

    public function test_rejects_non_array_project_keys_with_422(): void
    {
        $this->postJson('/api/kb/chat', [
            'question' => 'X',
            'filters' => ['project_keys' => 'not-an-array'],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['filters.project_keys']);
    }

    public function test_rejects_non_integer_doc_ids_with_422(): void
    {
        $this->postJson('/api/kb/chat', [
            'question' => 'X',
            'filters' => ['doc_ids' => ['not-an-int', 42]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['filters.doc_ids.0']);
    }

    public function test_rejects_date_to_before_date_from_with_422(): void
    {
        $this->postJson('/api/kb/chat', [
            'question' => 'X',
            'filters' => [
                'date_from' => '2026-12-31',
                'date_to' => '2026-01-01',
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['filters.date_to']);
    }

    public function test_empty_filters_payload_falls_back_to_no_filters(): void
    {
        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'X?',
            'filters' => [],
        ]);

        $resp->assertStatus(200);
        $this->assertNotNull($this->capturedFilters);
        $this->assertTrue($this->capturedFilters->isEmpty());
    }
}
