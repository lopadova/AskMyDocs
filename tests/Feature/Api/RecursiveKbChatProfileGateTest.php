<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\KbChatController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\MessageStreamController;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\Investigation\KbInvestigationResult;
use App\Services\Kb\Investigation\KbInvestigationService;
use App\Services\Kb\Retrieval\SearchResult;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

/** The three chat surfaces fail closed in exactly the same way before a profile exists. */
final class RecursiveKbChatProfileGateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('kb.investigation.enabled', true);
        config()->set('chat-log.enabled', false);
        config()->set('ai.default', 'anthropic');
        config()->set('ai.providers.anthropic', [
            'driver' => 'anthropic',
            'name' => 'anthropic',
            'key' => 'sk-ant-test',
            'url' => 'https://api.anthropic.com/v1',
            'api_version' => '2023-06-01',
            'temperature' => 0,
            'max_tokens' => 2048,
            'timeout' => 30,
            'models' => ['text' => ['default' => 'claude-sonnet-4-20250514']],
        ]);
        app(TenantContext::class)->set('profile-gate-tenant');
        Route::post('/kb/chat', KbChatController::class);
        Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store'])
            ->middleware(SubstituteBindings::class);
        Route::post('/conversations/{conversation}/messages/stream', [MessageStreamController::class, 'store'])
            ->middleware(SubstituteBindings::class);

        $this->user = User::create([
            'name' => 'Profile gate',
            'email' => 'profile-gate@example.test',
            'password' => Hash::make('secret'),
        ]);
        $this->conversation = Conversation::create([
            'tenant_id' => 'profile-gate-tenant',
            'user_id' => $this->user->id,
            'project_key' => 'orders',
            'title' => 'Profile gate',
        ]);
        $retrieval = Mockery::mock(ChatRetrievalService::class);
        $retrieval->shouldNotReceive('retrieve');
        $this->app->instance(ChatRetrievalService::class, $retrieval);
    }

    public function test_sync_stateless_and_streaming_chat_require_the_same_profile_without_searching(): void
    {
        $this->actingAs($this->user)
            ->postJson('/kb/chat', ['question' => 'Cercami l’ordine di Tizio', 'project_key' => 'orders', 'depth' => 3])
            ->assertOk()
            ->assertJsonPath('refusal_reason', 'retrieval_profile_required');

        $this->actingAs($this->user)
            ->postJson('/conversations/'.$this->conversation->id.'/messages', ['content' => 'Cercami l’ordine di Tizio', 'depth' => 3])
            ->assertOk()
            ->assertJsonPath('refusal_reason', 'retrieval_profile_required');

        $response = $this->actingAs($this->user)
            ->withHeaders(['Accept' => 'text/event-stream'])
            ->postJson('/conversations/'.$this->conversation->id.'/messages/stream', ['content' => 'Cercami l’ordine di Tizio', 'depth' => 3]);
        $response->assertOk();
        $this->assertStringContainsString('retrieval_profile_required', $response->streamedContent());
    }

    public function test_all_chat_channels_render_only_the_sources_selected_by_the_shared_investigation(): void
    {
        // The profile-gate test above deliberately binds a strict retrieval
        // mock. These happy paths only need its pure citation helpers, so
        // restore the concrete service (no vector search is invoked here).
        $this->app->forgetInstance(ChatRetrievalService::class);
        $selected = [
            'chunk_id' => 101,
            'chunk_hash' => 'selected-evidence',
            'chunk_text' => 'Ordine #42 di Tizio: consegnato il 24 settembre.',
            'project_key' => 'orders',
            'vector_score' => 0.92,
            'rerank_score' => 0.92,
            'document' => [
                'id' => 11,
                'title' => 'Esito ordine Tizio',
                'source_path' => 'mail/orders/tizio-42',
                'source_type' => 'text',
            ],
        ];
        $investigation = Mockery::mock(KbInvestigationService::class);
        $investigation->shouldReceive('investigate')->times(3)->andReturnUsing(
            fn () => new KbInvestigationResult(
                'ready',
                'sufficient_evidence',
                new SearchResult(collect([$selected]), collect(), collect(), [
                    'investigation' => [
                        'queries' => ['ordine cliente Tizio stato consegna'],
                        'stop_reason' => 'sufficient_evidence',
                        'selected_documents' => 1,
                        'supported_facts' => ['Ordine #42 consegnato'],
                        'missing_facts' => [],
                    ],
                ]),
                [$selected],
                ['ordine cliente Tizio stato consegna'],
            ),
        );
        $this->app->instance(KbInvestigationService::class, $investigation);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-sonnet-4-20250514',
            'content' => [['type' => 'text', 'text' => 'L’ordine #42 di Tizio risulta consegnato.']],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
            'stop_reason' => 'end_turn',
        ], 200)]);

        $stateless = $this->actingAs($this->user)
            ->postJson('/kb/chat', ['question' => 'Cercami l’ordine di Tizio', 'project_key' => 'orders']);
        $stateless->assertOk()->assertJsonPath('citations.0.source_path', 'mail/orders/tizio-42');
        $this->assertSame(['mail/orders/tizio-42'], array_column($stateless->json('citations'), 'source_path'));

        $sync = $this->actingAs($this->user)
            ->postJson('/conversations/'.$this->conversation->id.'/messages', ['content' => 'Cercami l’ordine di Tizio']);
        $sync->assertOk()->assertJsonPath('metadata.citations.0.source_path', 'mail/orders/tizio-42');
        $this->assertSame(['mail/orders/tizio-42'], array_column($sync->json('metadata.citations'), 'source_path'));

        $stream = $this->actingAs($this->user)
            ->withHeaders(['Accept' => 'text/event-stream'])
            ->postJson('/conversations/'.$this->conversation->id.'/messages/stream', ['content' => 'Cercami l’ordine di Tizio']);
        $stream->assertOk();
        $stream->streamedContent();
        $streamedAssistant = $this->conversation->fresh()->messages()->where('role', 'assistant')->latest('id')->firstOrFail();
        $this->assertSame(['mail/orders/tizio-42'], array_column($streamedAssistant->metadata['citations'], 'source_path'));
    }
}
