<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentTurnContextBuilder;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AgentTurnContextBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_carries_previous_tool_ids_and_current_selection_into_a_follow_up(): void
    {
        app(TenantContext::class)->set('acme');
        $user = User::create([
            'name' => 'Context user',
            'email' => 'context@example.test',
            'password' => Hash::make('secret-pass-123'),
        ]);
        $conversation = Conversation::create([
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'title' => 'Customer orders',
            'project_key' => 'crm',
        ]);
        $conversation->messages()->create(['role' => 'user', 'content' => 'Cerca Riccardo Lorini']);
        $conversation->messages()->create(['role' => 'assistant', 'content' => 'Ho trovato il cliente e i suoi ordini.']);
        $previous = $this->createRun($user, $conversation, 'Quali ordini ha?');
        $previous->forceFill([
            'status' => AgentRun::STATUS_COMPLETED,
            'result_json' => [
                'response' => ['answer' => 'L’ordine più recente è O-16426.'],
                'evidence' => ['api_tools' => [[
                    'tool' => 'search-orders',
                    'display_name' => 'Search orders',
                    'arguments' => ['customer_id' => 778],
                    'result' => ['orders' => [[
                        'id' => 16426,
                        'customer_id' => 778,
                        'number' => 'O-16426',
                        'access_token' => 'must-not-leak',
                    ]]],
                ]]],
            ],
        ])->save();
        $currentMessage = $conversation->messages()->create(['role' => 'user', 'content' => 'Mi dai i dettagli dell’ultimo ordine?']);
        $current = $this->createRun($user, $conversation, 'Mi dai i dettagli dell’ultimo ordine?', [
            'source_message_id' => 99,
            'row_key' => '778',
            'label' => 'Riccardo Lorini',
            'record' => ['id' => 778, 'name' => 'Riccardo Lorini'],
        ], $currentMessage->id);

        $context = app(AgentTurnContextBuilder::class)->build($current);

        $this->assertSame(778, data_get($context, 'previous_runs.0.tool_results.0.arguments.customer_id'));
        $this->assertSame(16426, data_get($context, 'previous_runs.0.tool_results.0.result.orders.0.id'));
        $this->assertSame('[REDACTED]', data_get($context, 'previous_runs.0.tool_results.0.result.orders.0.access_token'));
        $this->assertSame(778, data_get($context, 'current_selection.record.id'));
        $this->assertStringContainsString('Ho trovato il cliente', data_get($context, 'conversation_messages.1.content'));
    }

    /** @param array<string,mixed>|null $selection */
    private function createRun(User $user, Conversation $conversation, string $question, ?array $selection = null, ?int $messageId = null): AgentRun
    {
        return AgentRun::create([
            'run_id' => Str::uuid()->toString(),
            'tenant_id' => 'acme',
            'project_key' => 'crm',
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'channel' => 'chat',
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'locale' => 'it-IT',
            'timezone' => 'Europe/Rome',
            'status' => AgentRun::STATUS_RUNNING,
            'input_json' => array_filter([
                'question' => $question,
                'selection' => $selection,
                'user_message_id' => $messageId,
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }
}
