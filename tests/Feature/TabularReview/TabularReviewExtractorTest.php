<?php

declare(strict_types=1);

namespace Tests\Feature\TabularReview;

use App\Ai\AiManager;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\TabularCell;
use App\Models\TabularReview;
use App\Models\User;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Retrieval\SearchResult;
use App\Services\TabularReview\TabularReviewExtractor;
use App\Support\TabularReview\CellFlag;
use App\Support\TabularReview\CellStatus;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * v4.7/W1 — TabularReviewExtractor unit-feature tests.
 *
 * The KbSearchService dependency is stubbed via the container so the
 * test does not invoke real vector queries against pgvector / SQLite
 * fallbacks. AI calls are stubbed via Http::fake() — same transport
 * AiInsightsService tests use.
 */
final class TabularReviewExtractorTest extends TestCase
{
    use RefreshDatabase;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ai.default', 'openai');
        config()->set('ai.providers.openai.api_key', 'test-key');
        config()->set('ai.providers.openai.chat.model', 'gpt-4o-mini');

        // Seed a user up-front so review.user_id FK is satisfied.
        $this->userId = User::create([
            'name' => 'X',
            'email' => 'x-'.uniqid().'@demo.local',
            'password' => Hash::make('secret'),
        ])->id;
    }

    public function test_extract_returns_cells_for_each_column(): void
    {
        $review = $this->makeReview([
            ['name' => 'Title', 'prompt' => 'Doc title?', 'format' => 'text'],
            ['name' => 'Status', 'prompt' => 'What status?', 'format' => 'enum_status'],
        ]);
        $doc = $this->makeDoc($review->project_key);
        $this->makeChunk($doc, 'Heading A', 'Title is Foo and status is done.');

        $this->stubSearch($doc);
        $this->stubAi(
            "{\"column_index\":0,\"summary\":\"Foo\",\"flag\":\"green\",\"reasoning\":\"\",\"citations\":[{\"chunk_id\":\"1\",\"quote\":\"Foo\"}]}\n"
            ."{\"column_index\":1,\"summary\":\"done\",\"flag\":\"green\",\"reasoning\":\"\",\"citations\":[]}"
        );

        $cells = $this->extractor()->extract($review, $doc);

        $this->assertCount(2, $cells);
        $this->assertSame('Foo', $cells[0]->content['summary']);
        $this->assertSame('done', $cells[1]->content['summary']);
        $this->assertSame(CellStatus::READY->value, $cells[0]->status);
        $this->assertSame(CellFlag::GREEN->value, $cells[0]->flag);
    }

    public function test_extract_invokes_on_cell_callback(): void
    {
        $review = $this->makeReview([
            ['name' => 'Title', 'prompt' => 'What title?', 'format' => 'text'],
        ]);
        $doc = $this->makeDoc($review->project_key);
        $this->makeChunk($doc, 'H', 'Body.');

        $this->stubSearch($doc);
        $this->stubAi('{"column_index":0,"summary":"Foo","flag":"green","reasoning":"","citations":[]}');

        $received = [];
        $this->extractor()->extract($review, $doc, function (TabularCell $c) use (&$received): void {
            $received[] = $c->column_index;
        });

        $this->assertSame([0], $received);
    }

    public function test_extract_refuses_with_red_flag_when_no_chunks(): void
    {
        $review = $this->makeReview([
            ['name' => 'X', 'prompt' => 'p', 'format' => 'text'],
        ]);
        $doc = $this->makeDoc($review->project_key);
        // NO chunks — and stub the search to return empty.

        $this->stubSearch($doc, chunks: []);
        // The LLM MUST NOT be called when no chunks are available — R26.
        Http::fake([
            '*' => Http::response(['error' => 'should_not_be_called'], 500),
        ]);

        $cells = $this->extractor()->extract($review, $doc);

        $this->assertCount(1, $cells);
        $this->assertSame(CellStatus::FAILED->value, $cells[0]->status);
        $this->assertSame(CellFlag::RED->value, $cells[0]->flag);
        $this->assertNull($cells[0]->content['summary']);
        $this->assertStringContainsString('No evidence', $cells[0]->content['reasoning']);

        Http::assertNothingSent();
    }

    public function test_extract_refuses_when_llm_returns_empty_summary(): void
    {
        $review = $this->makeReview([
            ['name' => 'X', 'prompt' => 'p', 'format' => 'text'],
        ]);
        $doc = $this->makeDoc($review->project_key);
        $this->makeChunk($doc, 'H', 'Body.');

        $this->stubSearch($doc);
        $this->stubAi('{"column_index":0,"summary":null,"flag":"red","reasoning":"none","citations":[]}');

        $cells = $this->extractor()->extract($review, $doc);

        $this->assertCount(1, $cells);
        $this->assertSame(CellStatus::FAILED->value, $cells[0]->status);
        $this->assertSame(CellFlag::RED->value, $cells[0]->flag);
    }

    public function test_extract_uses_json_path_shortcut_without_calling_llm(): void
    {
        $review = $this->makeReview([
            [
                'name' => 'Status',
                'prompt' => null,
                'format' => 'json_path',
                'json_path' => '$.status',
            ],
        ]);
        $doc = $this->makeDoc($review->project_key);
        $this->makeChunk($doc, '', 'Body', metadata: ['status' => 'In Progress']);

        // LLM MUST NOT be called.
        Http::fake([
            '*' => Http::response(['error' => 'should_not_be_called'], 500),
        ]);

        $cells = $this->extractor()->extract($review, $doc);

        $this->assertCount(1, $cells);
        $this->assertSame('In Progress', $cells[0]->content['summary']);
        $this->assertSame(CellFlag::GREY->value, $cells[0]->flag);

        Http::assertNothingSent();
    }

    public function test_extract_json_path_falls_back_to_red_when_path_missing(): void
    {
        $review = $this->makeReview([
            [
                'name' => 'Status',
                'prompt' => null,
                'format' => 'json_path',
                'json_path' => '$.nope.does.not.exist',
            ],
        ]);
        $doc = $this->makeDoc($review->project_key);
        $this->makeChunk($doc, '', 'Body', metadata: ['status' => 'irrelevant']);

        Http::fake([
            '*' => Http::response(['error' => 'should_not_be_called'], 500),
        ]);

        $cells = $this->extractor()->extract($review, $doc);

        $this->assertCount(1, $cells);
        $this->assertSame(CellStatus::FAILED->value, $cells[0]->status);
        $this->assertNull($cells[0]->content['summary']);
        Http::assertNothingSent();
    }

    public function test_extract_persists_cell_via_upsert_no_duplicates_on_rerun(): void
    {
        $review = $this->makeReview([
            ['name' => 'X', 'prompt' => 'p', 'format' => 'text'],
        ]);
        $doc = $this->makeDoc($review->project_key);
        $this->makeChunk($doc, 'H', 'Body.');

        $this->stubSearch($doc);
        // Use Http::fakeSequence so the second call's fake actually replaces
        // the first one's residual response. With a plain "*" → response
        // mapping the second Http::fake() call still inherits the first's
        // queued response under some interception paths.
        $line1 = '{"column_index":0,"summary":"first","flag":"green","reasoning":"","citations":[]}';
        $line2 = '{"column_index":0,"summary":"second","flag":"green","reasoning":"","citations":[]}';
        Http::fake([
            '*' => Http::sequence()
                ->push($this->aiPayload($line1), 200)
                ->push($this->aiPayload($line2), 200),
        ]);

        $this->extractor()->extract($review, $doc);
        $this->stubSearch($doc); // re-bind the KbSearchService mock for the 2nd call
        $this->extractor()->extract($review, $doc);

        // Exactly one row, value updated to "second".
        $count = TabularCell::where('review_id', $review->id)
            ->where('document_id', $doc->id)
            ->where('column_index', 0)
            ->count();
        $this->assertSame(1, $count);
        $cell = TabularCell::where('review_id', $review->id)->first();
        $this->assertSame('second', $cell->content['summary']);
    }

    public function test_extract_multi_column_batched_into_single_llm_call(): void
    {
        $review = $this->makeReview([
            ['name' => 'A', 'prompt' => 'pa', 'format' => 'text'],
            ['name' => 'B', 'prompt' => 'pb', 'format' => 'text'],
            ['name' => 'C', 'prompt' => 'pc', 'format' => 'text'],
        ]);
        $doc = $this->makeDoc($review->project_key);
        $this->makeChunk($doc, 'H', 'Body.');

        $this->stubSearch($doc);

        $callCount = 0;
        Http::fake([
            '*' => function () use (&$callCount) {
                $callCount++;
                return Http::response([
                    'choices' => [[
                        'message' => [
                            'content' => "{\"column_index\":0,\"summary\":\"a\",\"flag\":\"green\",\"reasoning\":\"\",\"citations\":[]}\n"
                                ."{\"column_index\":1,\"summary\":\"b\",\"flag\":\"green\",\"reasoning\":\"\",\"citations\":[]}\n"
                                ."{\"column_index\":2,\"summary\":\"c\",\"flag\":\"green\",\"reasoning\":\"\",\"citations\":[]}",
                        ],
                        'finish_reason' => 'stop',
                    ]],
                    'model' => 'gpt-4o-mini',
                    'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
                ], 200);
            },
        ]);

        $cells = $this->extractor()->extract($review, $doc);

        $this->assertCount(3, $cells);
        $this->assertSame(1, $callCount, 'Multi-column extraction must batch into a single LLM call.');
    }

    public function test_extract_handles_llm_http_error_with_red_flag(): void
    {
        $review = $this->makeReview([
            ['name' => 'X', 'prompt' => 'p', 'format' => 'text'],
        ]);
        $doc = $this->makeDoc($review->project_key);
        $this->makeChunk($doc, 'H', 'Body.');

        $this->stubSearch($doc);
        Http::fake([
            '*' => Http::response(['error' => 'boom'], 500),
        ]);

        $cells = $this->extractor()->extract($review, $doc);

        $this->assertCount(1, $cells);
        $this->assertSame(CellStatus::FAILED->value, $cells[0]->status);
        $this->assertSame(CellFlag::RED->value, $cells[0]->flag);
    }

    public function test_extract_skips_invalid_json_lines_and_refuses_missing_columns(): void
    {
        $review = $this->makeReview([
            ['name' => 'A', 'prompt' => 'pa', 'format' => 'text'],
            ['name' => 'B', 'prompt' => 'pb', 'format' => 'text'],
        ]);
        $doc = $this->makeDoc($review->project_key);
        $this->makeChunk($doc, 'H', 'Body.');

        $this->stubSearch($doc);
        // Column 0 valid, garbage line, column 1 missing entirely.
        $this->stubAi(
            "{\"column_index\":0,\"summary\":\"a\",\"flag\":\"green\",\"reasoning\":\"\",\"citations\":[]}\n"
            ."not-json-at-all"
        );

        $cells = $this->extractor()->extract($review, $doc);

        $this->assertCount(2, $cells);
        $this->assertSame(CellStatus::READY->value, $cells[0]->status);
        $this->assertSame(CellStatus::FAILED->value, $cells[1]->status);
    }

    public function test_extract_emits_no_cells_when_columns_config_empty(): void
    {
        $review = TabularReview::create([
            'project_key' => 'hr',
            'user_id' => $this->userId,
            'title' => 'X',
            'columns_config' => [],
        ]);
        $doc = $this->makeDoc('hr');

        Http::fake();
        $cells = $this->extractor()->extract($review, $doc);

        $this->assertSame([], $cells);
    }

    // ── helpers ─────────────────────────────────────────────────────

    private function extractor(): TabularReviewExtractor
    {
        return app(TabularReviewExtractor::class);
    }

    private function stubSearch(KnowledgeDocument $doc, ?array $chunks = null): void
    {
        $allChunks = $chunks ?? KnowledgeChunk::where('knowledge_document_id', $doc->id)->get()->all();
        $primary = new Collection($allChunks);
        $result = new SearchResult(
            primary: $primary,
            expanded: collect(),
            rejected: collect(),
            meta: [],
        );

        $mock = $this->createMock(KbSearchService::class);
        $mock->method('searchWithContext')->willReturn($result);

        $this->app->instance(KbSearchService::class, $mock);
    }

    private function stubAi(string $content): void
    {
        Http::fake([
            '*' => Http::response($this->aiPayload($content), 200),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function aiPayload(string $content): array
    {
        return [
            'choices' => [[
                'message' => ['content' => $content],
                'finish_reason' => 'stop',
            ]],
            'model' => 'gpt-4o-mini',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     */
    private function makeReview(array $columns): TabularReview
    {
        return TabularReview::create([
            'project_key' => 'hr',
            'user_id' => $this->userId,
            'title' => 'R',
            'columns_config' => $columns,
        ]);
    }

    private function makeDoc(string $project): KnowledgeDocument
    {
        return KnowledgeDocument::create([
            'project_key' => $project,
            'source_type' => 'markdown',
            'title' => 'Sample',
            'source_path' => 'sample-'.uniqid().'.md',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => str_repeat('b', 64),
            'metadata' => [],
            'status' => 'indexed',
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function makeChunk(KnowledgeDocument $doc, string $heading, string $text, array $metadata = []): KnowledgeChunk
    {
        return KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id,
            'project_key' => $doc->project_key,
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', $heading.$text.uniqid()),
            'heading_path' => $heading,
            'chunk_text' => $text,
            'metadata' => $metadata,
            'embedding' => [],
        ]);
    }
}
