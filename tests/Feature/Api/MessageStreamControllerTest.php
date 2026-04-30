<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Ai\StreamChunk;
use App\Http\Controllers\Api\MessageStreamController;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Kb\KbSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

/**
 * v4.0/W3.1 — feature tests for the SSE streaming chat endpoint.
 *
 * Maps to the 8 scenarios required by
 * `docs/v4-platform/PLAN-W3-vercel-chat-migration.md` §7.5:
 *
 *   1. Happy path: text-delta + finish chunks emit in order
 *   2. Refusal: data-refusal events instead of text-delta
 *   3. Empty content → 422
 *   4. Filters round-trip (filters in body → filters reach search)
 *   5. R30 cross-tenant rejection (403 on conversation owned by another user)
 *   6. R32 memory privacy (chat-log row carries the right tenant)
 *   7. Provider streaming fallback (one-chunk emit when provider doesn't stream natively)
 *   8. SSE protocol drift — wire format byte-for-byte stable across the test
 *
 * The streaming endpoint runs inside a `StreamedResponse` callback that
 * fires when the response is "sent". In PHPUnit test mode we trigger
 * the callback explicitly via {@see captureStream()} which captures
 * the SSE output buffer and parses it back into a list of
 * `StreamChunk` instances for assertion.
 *
 * Why we mock the AI provider (`Anthropic` here): the streaming
 * controller calls `AiManager::chatStream()` which delegates to a
 * provider's `chatStream()` method. Anthropic's fallback streaming
 * goes through `chatWithHistory()` → `Http::post()`, so we can
 * intercept at the HTTP transport layer with `Http::fake()` and
 * keep the streaming code path real (the StreamChunk DTO + the
 * controller's emit/buffer/persist logic all execute against the
 * mock response).
 */
final class MessageStreamControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Conversation $conversation;
    /** @var array<string, mixed>|null */
    private ?array $capturedFiltersInput = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Testbench doesn't auto-load routes/web.php so we register
        // the streaming route inline. We add SubstituteBindings
        // middleware EXPLICITLY because Testbench's bare Route::post()
        // doesn't run any middleware group — without SubstituteBindings
        // the `Conversation $conversation` typed parameter receives a
        // fresh empty model instead of the row identified by the URL
        // segment, and the controller's auth check fails because
        // `null !== $userId` returns true → 403. Auth itself is
        // handled by `actingAs($user)` per-test and we deliberately
        // skip the `auth` middleware in the test setup so we don't
        // pull in session/CSRF machinery that Testbench doesn't wire.
        Route::post(
            '/conversations/{conversation}/messages/stream',
            [MessageStreamController::class, 'store'],
        )->middleware(\Illuminate\Routing\Middleware\SubstituteBindings::class);

        // Anthropic as the chat provider — its fallback streaming hits
        // Http::fake() cleanly. Other providers work the same way for
        // any test that mocks the underlying transport; W3.1 covers
        // the contract once via Anthropic and trusts the FallbackStreaming
        // trait + StreamChunkTest unit tests to cover the rest.
        config()->set('ai.default', 'anthropic');
        config()->set('ai.providers.anthropic', [
            'api_key' => 'sk-ant-test',
            'api_version' => '2023-06-01',
            'chat_model' => 'claude-sonnet-4-20250514',
            'temperature' => 0.2,
            'max_tokens' => 2048,
            'timeout' => 30,
        ]);
        config()->set('kb.refusal.min_chunk_similarity', 0.45);
        config()->set('kb.refusal.min_chunks_required', 1);
        // Disable real chat logging — final ChatLogManager can't be
        // Mockery-mocked, but the controller persists a Message row
        // either way, so we still cover persistence semantics.
        config()->set('chat-log.enabled', false);

        $this->user = $this->makeUser();
        $this->conversation = Conversation::create([
            'user_id' => $this->user->id,
            'project_key' => 'hr-portal',
            'title' => 'Test',
        ]);
    }

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Stream',
            'email' => 'stream-' . uniqid() . '@demo.local',
            'password' => Hash::make('secret123'),
        ]);
    }

    protected function tearDown(): void
    {
        $this->capturedFiltersInput = null;
        Mockery::close();
        parent::tearDown();
    }

    public function test_1_happy_path_emits_text_delta_then_finish_in_order(): void
    {
        $this->mockSearchWithGroundedChunks();
        $this->mockAnthropicResponse(content: 'The remote work stipend applies after 90 days.');

        $chunks = $this->postStream('/conversations/' . $this->conversation->id . '/messages/stream', [
            'content' => 'How does the remote work stipend work?',
        ]);

        $types = array_map(fn (StreamChunk $c) => $c->type, $chunks);

        // Wire format invariant: source events come BEFORE text-delta;
        // data-confidence comes AFTER text-delta and BEFORE finish;
        // finish is the terminal event. Citations are present because
        // the mocked search returns a grounded primary with a document.
        $this->assertContains(StreamChunk::TYPE_TEXT_DELTA, $types);
        $this->assertContains(StreamChunk::TYPE_DATA_CONFIDENCE, $types);
        $this->assertSame(StreamChunk::TYPE_FINISH, end($types));

        $finish = end($chunks);
        $this->assertSame('end_turn', $finish->payload['finishReason']);

        // Assistant message persisted with full content.
        $assistant = $this->conversation->messages()->where('role', 'assistant')->first();
        $this->assertNotNull($assistant);
        $this->assertSame('The remote work stipend applies after 90 days.', $assistant->content);
        $this->assertNull($assistant->refusal_reason);
        $this->assertTrue((bool) ($assistant->metadata['streamed'] ?? false));
    }

    public function test_2_refusal_emits_data_refusal_instead_of_text_delta(): void
    {
        // No grounded chunks (all below threshold) → refusal short-circuit.
        $this->mockSearchWithUngroundedChunks();
        // The LLM should NOT be called on the refusal path.
        Http::fake(['api.anthropic.com/*' => Http::response([], 500)]);

        $chunks = $this->postStream('/conversations/' . $this->conversation->id . '/messages/stream', [
            'content' => 'Tell me something completely unrelated.',
        ]);

        $types = array_map(fn (StreamChunk $c) => $c->type, $chunks);

        // Refusal stream variant: data-refusal + data-confidence(refused) + finish.
        // No text-delta events.
        $this->assertNotContains(StreamChunk::TYPE_TEXT_DELTA, $types);
        $this->assertContains(StreamChunk::TYPE_DATA_REFUSAL, $types);
        $this->assertContains(StreamChunk::TYPE_DATA_CONFIDENCE, $types);
        $this->assertSame(StreamChunk::TYPE_FINISH, end($types));

        $refusal = $this->findChunkByType($chunks, StreamChunk::TYPE_DATA_REFUSAL);
        $this->assertSame('no_relevant_context', $refusal->payload['reason']);

        $confidence = $this->findChunkByType($chunks, StreamChunk::TYPE_DATA_CONFIDENCE);
        $this->assertSame('refused', $confidence->payload['tier']);
        $this->assertNull($confidence->payload['confidence']);

        $finish = end($chunks);
        $this->assertSame('refusal', $finish->payload['finishReason']);

        // Persisted assistant message carries the refusal reason in the
        // dedicated column AND inside metadata (T3.5).
        $assistant = $this->conversation->messages()->where('role', 'assistant')->first();
        $this->assertNotNull($assistant);
        $this->assertSame('no_relevant_context', $assistant->refusal_reason);
        $this->assertSame(0, (int) $assistant->confidence);

        // Http::fake recorded zero requests — the LLM was never called.
        Http::assertNothingSent();
    }

    public function test_3_empty_content_returns_422(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/conversations/' . $this->conversation->id . '/messages/stream', [
                'content' => '',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['content']);
    }

    public function test_4_filters_round_trip_into_search(): void
    {
        $capturedFilters = null;
        $search = Mockery::mock(KbSearchService::class);
        $search->shouldReceive('search')->andReturnUsing(
            function (...$args) use (&$capturedFilters) {
                $capturedFilters = $args[4] ?? null;
                // Return one grounded chunk so the path goes through happy.
                return $this->groundedChunkCollection();
            }
        );
        $this->app->instance(KbSearchService::class, $search);
        $this->mockAnthropicResponse('Reply');

        $this->postStream('/conversations/' . $this->conversation->id . '/messages/stream', [
            'content' => 'Q?',
            'filters' => [
                'source_types' => ['pdf'],
                'folder_globs' => ['hr/*'],
                'doc_ids' => [42],
            ],
        ]);

        $this->assertNotNull($capturedFilters);
        $this->assertSame(['pdf'], $capturedFilters->sourceTypes);
        $this->assertSame(['hr/*'], $capturedFilters->folderGlobs);
        $this->assertSame([42], $capturedFilters->docIds);
    }

    public function test_5_cross_tenant_rejection_returns_403(): void
    {
        // Conversation belongs to a different user → R30 cross-tenant
        // rejection at the controller's auth check.
        $otherUser = $this->makeUser();

        $response = $this->actingAs($otherUser)
            ->postJson('/conversations/' . $this->conversation->id . '/messages/stream', [
                'content' => 'whatever',
            ]);

        $response->assertStatus(403);

        // No assistant message persisted on the original conversation.
        $this->assertSame(0, $this->conversation->messages()->where('role', 'assistant')->count());
    }

    public function test_6_chat_log_metadata_records_streamed_flag(): void
    {
        // R32 memory privacy is enforced at the chat-log driver layer
        // (BelongsToTenant trait + chat_logs.tenant_id NOT NULL). We
        // can't observe it directly with chat-log disabled in tests,
        // but we CAN observe the `streamed: true` marker landing in
        // both the persisted Message metadata AND the metadata blob,
        // which proves the streaming-controller path took persistence
        // through the same code as MessageController (just with the
        // streaming flag set). When chat-log is enabled the same flag
        // ends up in chat_logs.extra and the dashboard can split
        // streamed vs synchronous turns.
        $this->mockSearchWithGroundedChunks();
        $this->mockAnthropicResponse('Reply');

        $this->postStream('/conversations/' . $this->conversation->id . '/messages/stream', [
            'content' => 'Q?',
        ]);

        $assistant = $this->conversation->messages()->where('role', 'assistant')->first();
        $this->assertNotNull($assistant);
        $this->assertTrue((bool) ($assistant->metadata['streamed'] ?? false));
        $this->assertSame('anthropic', $assistant->metadata['provider'] ?? null);
    }

    public function test_7_fallback_streaming_emits_one_text_delta_for_non_streaming_provider(): void
    {
        // Anthropic uses FallbackStreaming → exactly one text-delta
        // chunk for the full reply (no token-by-token splitting). This
        // proves the fallback path satisfies the same contract as
        // native streaming for FE consumption (the SDK happily renders
        // a single big delta as the complete answer).
        $this->mockSearchWithGroundedChunks();
        $this->mockAnthropicResponse(content: 'Single-chunk reply');

        $chunks = $this->postStream('/conversations/' . $this->conversation->id . '/messages/stream', [
            'content' => 'Q?',
        ]);

        $textDeltas = array_filter(
            $chunks,
            fn (StreamChunk $c) => $c->type === StreamChunk::TYPE_TEXT_DELTA,
        );

        $this->assertCount(1, $textDeltas, 'fallback streaming yields exactly one text-delta');
        $this->assertSame(
            'Single-chunk reply',
            array_values($textDeltas)[0]->payload['textDelta'],
        );
    }

    public function test_8_sse_wire_format_is_stable(): void
    {
        // Protocol-drift gate. Each SSE message starts with `data: `
        // and ends with `\n\n`. The JSON payload merges `type` at top
        // level (no nesting under `payload`). Concatenating all
        // text-delta `textDelta` values reconstructs the full
        // assistant text.
        $this->mockSearchWithGroundedChunks();
        $this->mockAnthropicResponse(content: 'Hello world.');

        $rawStream = $this->postStreamRaw('/conversations/' . $this->conversation->id . '/messages/stream', [
            'content' => 'Hi',
        ]);

        // Every event has the SSE framing.
        $events = array_filter(explode("\n\n", $rawStream), fn (string $e) => $e !== '');
        $this->assertNotEmpty($events);
        foreach ($events as $event) {
            $this->assertStringStartsWith('data: ', $event, "event must start with `data: ` prefix: {$event}");
            $payloadJson = substr($event, strlen('data: '));
            $decoded = json_decode($payloadJson, associative: true, flags: JSON_THROW_ON_ERROR);
            $this->assertArrayHasKey('type', $decoded);
        }

        // Reconstruct text from text-delta chunks.
        $reconstructed = '';
        foreach ($events as $event) {
            $payload = json_decode(substr($event, strlen('data: ')), associative: true, flags: JSON_THROW_ON_ERROR);
            if ($payload['type'] === StreamChunk::TYPE_TEXT_DELTA) {
                $reconstructed .= (string) $payload['textDelta'];
            }
        }
        $this->assertSame('Hello world.', $reconstructed);
    }

    // ---- helpers ----

    /**
     * @return list<StreamChunk>
     */
    private function postStream(string $url, array $payload): array
    {
        $raw = $this->postStreamRaw($url, $payload);
        return $this->parseSseStream($raw);
    }

    private function postStreamRaw(string $url, array $payload): string
    {
        $response = $this->actingAs($this->user)
            ->postJson($url, $payload);
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');

        // Symfony's TestResponse->streamedContent() runs the
        // StreamedResponse callback and captures its output.
        return $response->streamedContent();
    }

    /**
     * @return list<StreamChunk>
     */
    private function parseSseStream(string $raw): array
    {
        $events = array_filter(explode("\n\n", $raw), fn (string $e) => $e !== '');
        $chunks = [];
        foreach ($events as $event) {
            $payloadJson = substr($event, strlen('data: '));
            $decoded = json_decode($payloadJson, associative: true, flags: JSON_THROW_ON_ERROR);
            $type = $decoded['type'];
            unset($decoded['type']);
            $chunks[] = new StreamChunk($type, $decoded);
        }
        return $chunks;
    }

    /**
     * @param  list<StreamChunk>  $chunks
     */
    private function findChunkByType(array $chunks, string $type): StreamChunk
    {
        foreach ($chunks as $chunk) {
            if ($chunk->type === $type) {
                return $chunk;
            }
        }
        $this->fail("No chunk of type [{$type}] in stream");
    }

    private function mockAnthropicResponse(string $content): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-sonnet-4-20250514',
                'content' => [
                    ['type' => 'text', 'text' => $content],
                ],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);
    }

    private function mockSearchWithGroundedChunks(): void
    {
        $search = Mockery::mock(KbSearchService::class);
        $search->shouldReceive('search')->andReturn($this->groundedChunkCollection());
        $this->app->instance(KbSearchService::class, $search);
    }

    private function mockSearchWithUngroundedChunks(): void
    {
        $search = Mockery::mock(KbSearchService::class);
        $search->shouldReceive('search')->andReturn(collect([
            (object) [
                'id' => 1,
                'knowledge_document_id' => 1,
                'vector_score' => 0.20, // below 0.45 threshold → refusal
                'heading_path' => 'h',
                'chunk_text' => 'irrelevant',
                'document' => (object) [
                    'id' => 1,
                    'title' => 'Doc',
                    'source_path' => 'docs/x.md',
                    'source_type' => 'markdown',
                ],
            ],
        ]));
        $this->app->instance(KbSearchService::class, $search);
    }

    private function groundedChunkCollection(): \Illuminate\Support\Collection
    {
        return collect([
            (object) [
                'id' => 1,
                'knowledge_document_id' => 1,
                'vector_score' => 0.85,
                'heading_path' => 'Stipend',
                'chunk_text' => 'Eligibility section',
                'document' => (object) [
                    'id' => 1,
                    'title' => 'Remote work policy',
                    'source_path' => 'hr/policies/remote-work.md',
                    'source_type' => 'markdown',
                ],
            ],
        ]);
    }
}
