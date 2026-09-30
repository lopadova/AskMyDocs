<?php

namespace Tests\Feature\Chat;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\MessageStreamController;
use App\Models\Conversation;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FocusChannelParityTest extends TestCase
{
    use RefreshDatabase;

    public static function channels(): array
    {
        return [['sync', MessageController::class], ['stream', MessageStreamController::class]];
    }

    #[DataProvider('channels')]
    public function test_ambiguous_focus_is_persisted_and_asks_before_retrieval_on_both_channels(string $channel, string $controller): void
    {
        app(TenantContext::class)->set('default');
        config(['reasoning.enabled' => true, 'kb.investigation.enabled' => false, 'chat-log.enabled' => false]);
        Route::post('/focus/{conversation}', [$controller, 'store'])->middleware(SubstituteBindings::class);
        $user = User::create(['name' => 'Test', 'email' => 'focus@example.test', 'password' => bcrypt('test')]);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_key' => 'demo']);
        $focus = ['topic' => 'shipment', 'identifiers' => [], 'aspect' => 'status', 'fields' => ['status']];
        $ai = Mockery::mock(AiManager::class);
        $provider = Mockery::mock(\App\Ai\AiProviderInterface::class);
        $provider->shouldReceive('name')->andReturn('fake');
        $ai->shouldReceive('provider')->andReturn($provider);
        $ai->shouldReceive('chatWithProvider')->once()->andReturn(new AiResponse(content: json_encode([
            'language' => 'it', 'intent' => 'Clarify shipment', 'action' => 'clarify', 'kb_queries' => ['shipment'], 'mentions' => [],
            'references_previous_turn' => true, 'transition' => 'continue', 'focus' => $focus, 'subquestions' => [$focus],
            'resolved_references' => [], 'needs_clarification' => true, 'clarification' => 'A quale delle due spedizioni ti riferisci?',
        ]), provider: 'openrouter', model: 'test'));
        $this->app->instance(AiManager::class, $ai);
        $response = $this->actingAs($user)->postJson('/focus/'.$conversation->id, ['content' => 'E la spedizione?']);
        $response->assertOk();
        if ($channel === 'stream') {
            $response->streamedContent();
        }
        $this->assertSame('shipment', $conversation->fresh()->reasoning_state['focus']['topic']);
        $answer = $conversation->messages()->where('role', 'assistant')->latest('id')->firstOrFail();
        $this->assertSame('A quale delle due spedizioni ti riferisci?', $answer->content);
        $this->assertSame('focus_ambiguous', $answer->refusal_reason);
        $turn = $conversation->messages()->where('role', 'user')->firstOrFail();
        $this->assertTrue(data_get($turn->metadata, 'reasoning.understanding.needs_clarification'));
        $this->assertSame('clarify', data_get($turn->metadata, 'reasoning.understanding.action'));
    }
}
