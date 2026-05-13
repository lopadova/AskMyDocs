<?php

declare(strict_types=1);

namespace Tests\Feature\TabularReview;

use App\Models\KnowledgeDocument;
use App\Models\TabularReview;
use App\Models\User;
use App\Services\TabularReview\TabularReviewExtractor;
use App\Support\TabularReview\CellFlag;
use App\Support\TabularReview\CellStatus;
use App\Support\TenantContext;
use App\Models\TabularCell;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v4.7/W3 — Coverage for the SSE streaming variant of
 * `TabularReviewController::generate()`.
 *
 * The extractor is stubbed via an anonymous subclass injected through
 * `$this->app->instance(TabularReviewExtractor::class, new class extends
 * TabularReviewExtractor { ... })`. Mockery is NOT used here — the
 * subclass approach avoids Mockery's "cannot mock final/typed
 * dependencies" restriction and gives precise control over the
 * `$onCell` callback semantics. The streamed body is captured via
 * `StreamedResponse::sendContent()` and split into individual SSE
 * frames.
 */
final class TabularReviewStreamControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Cache::flush() avoids Spatie permission-cache artifacts leaking
        // across test methods under Testbench; explicit tenant reset
        // keeps the controller's `TenantContext::current()` deterministic
        // even if a prior test mutated the singleton.
        Cache::flush();
        $this->seed(RbacSeeder::class);
        app(TenantContext::class)->set('default');
    }

    public function test_stream_emits_start_document_cell_done_events(): void
    {
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc('hr');
        $review = TabularReview::create([
            'project_key' => 'hr',
            'user_id' => $admin->id,
            'title' => 'NDA review',
            'columns_config' => [
                ['name' => 'Status', 'prompt' => 'What status?', 'format' => 'text'],
            ],
        ]);

        $this->mockExtractorEmitsOneCell($doc->id, $review->id);

        $resp = $this->actingAs($admin)
            ->postJson('/api/admin/tabular-reviews/'.$review->id.'/generate-stream');

        $resp->assertOk();
        $body = $this->captureStream($resp);

        $this->assertStringContainsString('event: start', $body);
        $this->assertStringContainsString('event: document', $body);
        $this->assertStringContainsString('event: cell', $body);
        $this->assertStringContainsString('event: done', $body);
        $this->assertStringContainsString('"flag":"green"', $body);
        $this->assertStringContainsString('"status":"ready"', $body);
    }

    public function test_stream_returns_404_when_review_missing(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->postJson('/api/admin/tabular-reviews/9999/generate-stream')
            ->assertStatus(404);
    }

    public function test_stream_rejects_unauthenticated(): void
    {
        $this->postJson('/api/admin/tabular-reviews/1/generate-stream')->assertStatus(401);
    }

    public function test_stream_rejects_viewer_role(): void
    {
        $viewer = User::create([
            'name' => 'V',
            'email' => 'v-'.uniqid().'@demo.local',
            'password' => Hash::make('secret'),
        ]);
        $viewer->assignRole('viewer');

        $review = TabularReview::create([
            'project_key' => 'hr',
            'user_id' => $viewer->id,
            'title' => 'X',
            'columns_config' => [['name' => 'X', 'format' => 'text']],
        ]);

        $this->actingAs($viewer)
            ->postJson('/api/admin/tabular-reviews/'.$review->id.'/generate-stream')
            ->assertStatus(403);
    }

    public function test_stream_emits_error_event_when_extractor_throws(): void
    {
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc('hr');
        $review = TabularReview::create([
            'project_key' => 'hr',
            'user_id' => $admin->id,
            'title' => 'X',
            'columns_config' => [['name' => 'X', 'format' => 'text']],
        ]);

        // Bind a stub that throws — exercises the SSE error envelope
        // without mocking the extractor's dependencies.
        $this->app->instance(TabularReviewExtractor::class, new class extends TabularReviewExtractor {
            public function __construct() {}
            public function extract($review, $doc, ?\Closure $onCell = null): array
            {
                throw new \RuntimeException('boom');
            }
        });

        $resp = $this->actingAs($admin)
            ->postJson('/api/admin/tabular-reviews/'.$review->id.'/generate-stream');

        $resp->assertOk();
        $body = $this->captureStream($resp);

        $this->assertStringContainsString('event: error', $body);
        // The server-side message is masked to avoid leaking SQL
        // fragments / hostnames / internal stack traces. The
        // correlation id surfaces in the SSE payload so the operator
        // can pivot to the log line; the raw exception message
        // ("boom") does NOT.
        $this->assertStringContainsString('correlation_id', $body);
        $this->assertStringNotContainsString('"boom"', $body);
    }

    public function test_stream_emits_json_403_for_viewer_under_sse_accept_header(): void
    {
        $viewer = User::create([
            'name' => 'V',
            'email' => 'v-sse-'.uniqid().'@demo.local',
            'password' => Hash::make('secret'),
        ]);
        $viewer->assignRole('viewer');

        $review = TabularReview::create([
            'project_key' => 'hr',
            'user_id' => $viewer->id,
            'title' => 'X',
            'columns_config' => [['name' => 'X', 'format' => 'text']],
        ]);

        // SSE clients send `Accept: text/event-stream`. The pre-stream
        // guard must STILL respond with JSON 403 (not an HTML page /
        // 302) so the fetch-based SSE client can parse the failure
        // before unwrapping the stream (native EventSource is GET-only
        // and is not used here).
        $resp = $this->actingAs($viewer)->withHeaders([
            'Accept' => 'text/event-stream',
        ])->post('/api/admin/tabular-reviews/'.$review->id.'/generate-stream');

        $resp->assertStatus(403);
        $resp->assertHeader('Content-Type', 'application/json');
    }

    public function test_stream_respects_max_documents_cap(): void
    {
        $admin = $this->makeAdmin();
        $this->makeDoc('hr');
        $this->makeDoc('hr');
        $review = TabularReview::create([
            'project_key' => 'hr',
            'user_id' => $admin->id,
            'title' => 'X',
            'columns_config' => [['name' => 'X', 'format' => 'text']],
        ]);

        $stub = new class extends TabularReviewExtractor {
            public int $callCount = 0;
            public function __construct() {}
            public function extract($review, $doc, ?\Closure $onCell = null): array
            {
                $this->callCount++;
                return [];
            }
        };
        $this->app->instance(TabularReviewExtractor::class, $stub);

        $resp = $this->actingAs($admin)->postJson(
            '/api/admin/tabular-reviews/'.$review->id.'/generate-stream',
            ['max_documents' => 1]
        );
        $resp->assertOk();
        // StreamedResponse holds the callback until sendContent() runs;
        // capture the stream to fire it so the assertion below sees the
        // call count.
        $this->captureStream($resp);

        $this->assertSame(1, $stub->callCount);
    }

    private function mockExtractorEmitsOneCell(int $docId, int $reviewId): void
    {
        $this->app->instance(TabularReviewExtractor::class, new class($docId, $reviewId) extends TabularReviewExtractor {
            public function __construct(private int $docId, private int $reviewId) {}

            public function extract($review, $doc, ?\Closure $onCell = null): array
            {
                $cell = new TabularCell();
                $cell->review_id = $this->reviewId;
                $cell->document_id = $this->docId;
                $cell->column_index = 0;
                $cell->content = [
                    'summary' => 'ok',
                    'reasoning' => 'because',
                    'citations' => [],
                ];
                $cell->flag = CellFlag::GREEN;
                $cell->status = CellStatus::READY;
                if ($onCell !== null) {
                    $onCell($cell);
                }
                return [$cell];
            }
        });
    }

    /**
     * Capture the streamed body by wrapping `sendContent()` in TWO
     * nested output buffers. The controller's per-frame `ob_flush()`
     * pushes content into the OUTER buffer (so it survives flushing),
     * and we read the merged result via `ob_get_clean()` on both
     * levels.
     */
    private function captureStream($resp): string
    {
        $sym = $resp->baseResponse;
        ob_start(); // outer — receives flushed frames
        ob_start(); // inner — what the controller's `echo` writes to
        $sym->sendContent();
        $inner = (string) ob_get_clean();
        $outer = (string) ob_get_clean();
        return $outer.$inner;
    }

    private function makeAdmin(): User
    {
        $user = User::create([
            'name' => 'A',
            'email' => 'a-'.uniqid().'@demo.local',
            'password' => Hash::make('secret'),
        ]);
        $user->assignRole('admin');
        return $user;
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
}
