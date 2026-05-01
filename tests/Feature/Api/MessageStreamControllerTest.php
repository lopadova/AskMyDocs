<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Ai\StreamChunk;
use App\Http\Controllers\Api\MessageStreamController;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Kb\KbSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

/**
 * v4.0/W3.3 — feature tests for the SSE streaming chat endpoint, pinned
 * to the SDK v6 `UIMessageChunk` wire format that `@ai-sdk/react`'s
 * `useChat()` parses verbatim. PR #87 introduced the 8 scenarios below
 * for the W3.1 wire format; PR #88/#89 migrated the FE; this PR realigns
 * the BE emit to match the SDK so chat works end-to-end in production.
 *
 * Maps to the 8 scenarios required by
 * `docs/v4-platform/PLAN-W3-vercel-chat-migration.md` §7.5:
 *
 *   1. Happy path: start → source-url → text envelope → data-confidence → finish
 *   2. Refusal: data-refusal events instead of text envelope
 *   3. Empty content → 422
 *   4. Filters round-trip (filters in body → filters reach search)
 *   5. R30 cross-tenant rejection (403 on conversation owned by another user)
 *   6. Streamed-flag persistence on Message metadata
 *   7. Provider streaming fallback (one text-delta inside a text-start/text-end envelope)
 *   8. SSE protocol drift — wire format byte-for-byte stable across the test
 *
 * The streaming endpoint runs inside a `StreamedResponse` callback that
 * fires when the response is "sent". In PHPUnit test mode we trigger
 * the callback explicitly via Laravel's `TestResponse::streamedContent()`
 * (called from {@see postStreamRaw()}) which captures the SSE output
 * buffer; {@see parseSseStream()} then turns the raw text into a list
 * of `StreamChunk` instances for assertion.
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
        // any test that mocks the underlying transport; W3.3 covers
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
        Mockery::close();
        parent::tearDown();
    }

    public function test_1_happy_path_emits_sdk_v6_envelope_in_canonical_order(): void
    {
        $this->mockSearchWithGroundedChunks();
        $this->mockAnthropicResponse(content: 'The remote work stipend applies after 90 days.');

        $chunks = $this->postStream('/conversations/' . $this->conversation->id . '/messages/stream', [
            'content' => 'How does the remote work stipend work?',
        ]);

        $types = array_map(fn (StreamChunk $c) => $c->type, $chunks);

        // SDK v6 UIMessageChunk envelope invariants:
        //   - `start` is the very first chunk
        //   - `source-url` chunks come BEFORE any `text-start`
        //   - text body lives inside a `text-start` / `text-delta` /
        //     `text-end` envelope (one matched id throughout)
        //   - `data-confidence` comes AFTER the text envelope and
        //     BEFORE the terminal `finish`
        //   - `finish` is the last chunk
        $this->assertSame(StreamChunk::TYPE_START, $types[0]);
        $this->assertContains(StreamChunk::TYPE_SOURCE_URL, $types);
        $this->assertContains(StreamChunk::TYPE_TEXT_START, $types);
        $this->assertContains(StreamChunk::TYPE_TEXT_DELTA, $types);
        $this->assertContains(StreamChunk::TYPE_TEXT_END, $types);
        $this->assertContains(StreamChunk::TYPE_DATA_CONFIDENCE, $types);
        $this->assertSame(StreamChunk::TYPE_FINISH, end($types));

        // Source-url precedes text-start (citation chips render before
        // the answer body starts streaming). Defensive guards: if
        // either chunk is missing `array_search()` returns `false`,
        // and `false < int` would coerce to `0 < int` and pass
        // spuriously — `assertNotFalse` catches the missing-chunk
        // case explicitly even though the `assertContains` calls
        // above already do. PHPUnit's `assertLessThan($expected,
        // $actual)` asserts `$actual < $expected`, so the
        // arguments below pin `sourceIdx < textStartIdx`.
        $sourceIdx = array_search(StreamChunk::TYPE_SOURCE_URL, $types, strict: true);
        $textStartIdx = array_search(StreamChunk::TYPE_TEXT_START, $types, strict: true);
        $this->assertNotFalse($sourceIdx, 'source-url chunk must be present before ordering is asserted');
        $this->assertNotFalse($textStartIdx, 'text-start chunk must be present before ordering is asserted');
        $this->assertLessThan($textStartIdx, $sourceIdx, 'source-url should precede text-start');

        // Text envelope is well-formed: text-start + text-end carry
        // the same id, and every text-delta in between matches that
        // id (SDK stitches deltas back into one rendered part by id).
        $textStart = $this->findChunkByType($chunks, StreamChunk::TYPE_TEXT_START);
        $textEnd = $this->findChunkByType($chunks, StreamChunk::TYPE_TEXT_END);
        $textId = $textStart->payload['id'];
        $this->assertSame($textId, $textEnd->payload['id']);
        foreach ($chunks as $chunk) {
            if ($chunk->type !== StreamChunk::TYPE_TEXT_DELTA) {
                continue;
            }
            $this->assertSame($textId, $chunk->payload['id'], 'every text-delta uses the same id');
        }

        // Finish reason is in the SDK union — Anthropic's `end_turn`
        // normalizes to `'stop'` upstream of the wire.
        $finish = end($chunks);
        $this->assertSame('stop', $finish->payload['finishReason']);

        // Assistant message persisted with full content.
        $assistant = $this->conversation->messages()->where('role', 'assistant')->first();
        $this->assertNotNull($assistant);
        $this->assertSame('The remote work stipend applies after 90 days.', $assistant->content);
        $this->assertNull($assistant->refusal_reason);
        $this->assertTrue((bool) ($assistant->metadata['streamed'] ?? false));
    }

    public function test_2_refusal_emits_data_refusal_instead_of_text_envelope(): void
    {
        // No grounded chunks (all below threshold) → refusal short-circuit.
        $this->mockSearchWithUngroundedChunks();
        // The LLM should NOT be called on the refusal path.
        Http::fake(['api.anthropic.com/*' => Http::response([], 500)]);

        $chunks = $this->postStream('/conversations/' . $this->conversation->id . '/messages/stream', [
            'content' => 'Tell me something completely unrelated.',
        ]);

        $types = array_map(fn (StreamChunk $c) => $c->type, $chunks);

        // Refusal stream variant: start → data-refusal → data-confidence(refused) → finish.
        // No text envelope (no text-start / text-delta / text-end).
        $this->assertSame(StreamChunk::TYPE_START, $types[0]);
        $this->assertNotContains(StreamChunk::TYPE_TEXT_START, $types);
        $this->assertNotContains(StreamChunk::TYPE_TEXT_DELTA, $types);
        $this->assertNotContains(StreamChunk::TYPE_TEXT_END, $types);
        $this->assertContains(StreamChunk::TYPE_DATA_REFUSAL, $types);
        $this->assertContains(StreamChunk::TYPE_DATA_CONFIDENCE, $types);
        $this->assertSame(StreamChunk::TYPE_FINISH, end($types));

        // SDK `data-*` chunks wrap their payload under `data`.
        $refusal = $this->findChunkByType($chunks, StreamChunk::TYPE_DATA_REFUSAL);
        $this->assertSame('no_relevant_context', $refusal->payload['data']['reason']);
        $this->assertIsString($refusal->payload['data']['body']);
        $this->assertNotEmpty($refusal->payload['data']['body']);

        $confidence = $this->findChunkByType($chunks, StreamChunk::TYPE_DATA_CONFIDENCE);
        $this->assertSame('refused', $confidence->payload['data']['tier']);
        $this->assertNull($confidence->payload['data']['confidence']);

        // Refusal turns close with `'stop'` per SDK FinishReason union.
        // The application-level "this was a refusal" signal lives on
        // the persisted Message's `refusal_reason` column AND in the
        // `data-refusal` chunk above — never on `finish.finishReason`.
        $finish = end($chunks);
        $this->assertSame('stop', $finish->payload['finishReason']);

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
        // Send the production SSE Accept header to exercise the
        // controller's explicit ValidationException catch + JsonResponse
        // path — without it, Laravel's default `$request->validate()`
        // would emit a 302 redirect to the previous page when
        // `expectsJson()` is false (the SSE-Accept case). Asserting 422
        // JSON here proves the explicit catch defends streaming clients
        // that don't naturally trigger Laravel's JSON path.
        $response = $this->actingAs($this->user)
            ->withHeaders(['Accept' => 'text/event-stream'])
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
        // rejection at the controller's auth check. SSE Accept header
        // exercises the explicit `JsonResponse(['message' =>
        // 'Forbidden.'], 403)` branch; without the explicit return,
        // Laravel's `abort(403)` under SSE-Accept would render an HTML
        // 403 page that the streaming client can't parse.
        $otherUser = $this->makeUser();

        $response = $this->actingAs($otherUser)
            ->withHeaders(['Accept' => 'text/event-stream'])
            ->postJson('/conversations/' . $this->conversation->id . '/messages/stream', [
                'content' => 'whatever',
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Forbidden.');

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

    public function test_7_fallback_streaming_emits_one_text_delta_inside_text_envelope(): void
    {
        // Anthropic uses FallbackStreaming → exactly one text-delta
        // chunk inside one text-start/text-end envelope. This proves
        // the fallback path satisfies the SDK v6 contract (matched
        // ids across the envelope) for FE consumption (the SDK
        // happily renders a single big delta as the complete answer).
        $this->mockSearchWithGroundedChunks();
        $this->mockAnthropicResponse(content: 'Single-chunk reply');

        $chunks = $this->postStream('/conversations/' . $this->conversation->id . '/messages/stream', [
            'content' => 'Q?',
        ]);

        $textStarts = array_values(array_filter($chunks, fn (StreamChunk $c) => $c->type === StreamChunk::TYPE_TEXT_START));
        $textDeltas = array_values(array_filter($chunks, fn (StreamChunk $c) => $c->type === StreamChunk::TYPE_TEXT_DELTA));
        $textEnds = array_values(array_filter($chunks, fn (StreamChunk $c) => $c->type === StreamChunk::TYPE_TEXT_END));

        $this->assertCount(1, $textStarts, 'fallback streaming opens exactly one text part');
        $this->assertCount(1, $textDeltas, 'fallback streaming yields exactly one text-delta');
        $this->assertCount(1, $textEnds, 'fallback streaming closes the text part exactly once');

        // SDK v6 shape: text-delta carries `delta` (not `textDelta`)
        // plus the matching `id`.
        $this->assertSame('Single-chunk reply', $textDeltas[0]->payload['delta']);
        $this->assertSame($textStarts[0]->payload['id'], $textDeltas[0]->payload['id']);
        $this->assertSame($textStarts[0]->payload['id'], $textEnds[0]->payload['id']);
    }

    public function test_8_sse_wire_format_is_stable_under_sdk_v6_envelope(): void
    {
        // Protocol-drift gate. Each SSE message starts with `data: `
        // and ends with `\n\n`. The JSON payload merges `type` at top
        // level (no nesting under `payload`). `text-delta` chunks
        // carry SDK v6 `delta` + `id` (NOT W3.1's `textDelta`).
        // Concatenating all `delta` values reconstructs the full
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

        // Reconstruct text from `delta` field on text-delta chunks
        // (SDK v6 shape — NOT W3.1's `textDelta`). The reconstruction
        // proves the streaming controller emits the SDK-canonical
        // field name end-to-end.
        $reconstructed = '';
        $textIdSeen = null;
        foreach ($events as $event) {
            $payload = json_decode(substr($event, strlen('data: ')), associative: true, flags: JSON_THROW_ON_ERROR);
            if ($payload['type'] === StreamChunk::TYPE_TEXT_DELTA) {
                $reconstructed .= (string) $payload['delta'];
                $this->assertArrayHasKey('id', $payload, 'text-delta carries SDK v6 `id` field');
                $textIdSeen ??= $payload['id'];
                $this->assertSame($textIdSeen, $payload['id'], 'all text-delta chunks share one id');
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
        // Send `Accept: text/event-stream` so the test exercises the
        // production SSE-client behaviour. With the default Accept
        // (application/json), Laravel's `expectsJson()` returns true
        // and validation/auth failures naturally land as JSON — but
        // the production caller sends `Accept: text/event-stream`,
        // and the controller's explicit JsonResponse(403/422) branches
        // exist precisely to handle that case. Test what production
        // actually sends.
        $response = $this->actingAs($this->user)
            ->withHeaders(['Accept' => 'text/event-stream'])
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
        // Production-shape associative array — `vector_score` below
        // the 0.45 threshold forces the refusal short-circuit.
        $search->shouldReceive('search')->andReturn(collect([
            [
                'chunk_id' => 1,
                'project_key' => 'hr-portal',
                'heading_path' => 'h',
                'chunk_text' => 'irrelevant',
                'metadata' => [],
                'vector_score' => 0.20,
                'document' => [
                    'id' => 1,
                    'title' => 'Doc',
                    'source_path' => 'docs/x.md',
                    'source_type' => 'markdown',
                    'doc_id' => null,
                    'slug' => null,
                    'is_canonical' => false,
                    'canonical_type' => null,
                    'canonical_status' => null,
                ],
            ],
        ]));
        $this->app->instance(KbSearchService::class, $search);
    }

    private function groundedChunkCollection(): \Illuminate\Support\Collection
    {
        // Mirror the actual shape returned by `KbSearchService::search()`
        // — associative arrays with `chunk_id` + `vector_score` and a
        // nested `document.id` (NOT a flat `knowledge_document_id`).
        // The streaming controller uses `data_get($c, 'vector_score')`
        // which works for both objects and arrays, but writing the
        // fixtures in the production shape catches future regressions
        // where the controller (or ConfidenceCalculator, etc.) starts
        // to depend on the array-only contract.
        return collect([
            [
                'chunk_id' => 1,
                'project_key' => 'hr-portal',
                'heading_path' => 'Stipend',
                'chunk_text' => 'Eligibility section',
                'metadata' => [],
                'vector_score' => 0.85,
                'document' => [
                    'id' => 1,
                    'title' => 'Remote work policy',
                    'source_path' => 'hr/policies/remote-work.md',
                    'source_type' => 'markdown',
                    'doc_id' => null,
                    'slug' => null,
                    'is_canonical' => false,
                    'canonical_type' => null,
                    'canonical_status' => null,
                ],
            ],
        ]);
    }
}
