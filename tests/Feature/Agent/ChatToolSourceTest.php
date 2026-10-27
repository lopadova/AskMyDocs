<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Http\Controllers\Api\ChatToolSourceController;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ChatToolSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        Route::middleware('api')->get('/test-conversations/{conversation}/messages/{message}/tool-sources/{execution}', ChatToolSourceController::class);
        Route::middleware('api')->get('/test-conversations/{conversation}/messages', [\App\Http\Controllers\Api\MessageController::class, 'index']);
    }

    public function test_the_cited_call_is_read_from_history_with_credentials_redacted_and_no_http_execution(): void
    {
        [$user, $conversation, $answer, $execution] = $this->fixture();
        Http::preventStrayRequests();
        $response = $this->actingAs($user)->getJson($this->url($conversation, $answer, $execution));
        $response->assertOk()->assertJsonPath('tool', 'gescat_cerca_ordini')
            ->assertJsonPath('arguments.ord_codiceweb', 'ORDER-A')
            ->assertJsonPath('arguments.api_key', '[REDACTED]')
            ->assertJsonPath('request.method', 'GET')
            ->assertJsonPath('request.headers.Authorization', '[REDACTED]')
            ->assertJsonPath('response.data.0.ord_codiceweb', 'ORDER-A')
            ->assertJsonPath('response.data.0.access_token', '[REDACTED]');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('credential-value', $response->getContent());
        $this->assertStringNotContainsString('private_prompt', $response->getContent());
        Http::assertNothingSent();
    }

    public function test_executions_outside_this_answers_research_cannot_be_opened(): void
    {
        [$user, $conversation, $answer, $execution] = $this->fixture();
        $answer->update(['metadata' => ['tool_sources' => []]]);
        $otherRun = $execution->run->replicate();
        $otherRun->run_id = (string) Str::uuid();
        $otherRun->save();
        $execution->update(['agent_run_id' => $otherRun->id]);
        $this->actingAs($user)->getJson($this->url($conversation, $answer, $execution))->assertNotFound();
    }

    public function test_a_recorded_but_uncited_call_is_available_in_existing_history_and_labelled_separately(): void
    {
        [$user, $conversation, $answer, $execution] = $this->fixture();
        $answer->update(['metadata' => ['tool_sources' => []]]);
        $this->actingAs($user)->getJson("/test-conversations/{$conversation->id}/messages")
            ->assertOk()->assertJsonPath('0.metadata.tool_references.0.execution_id', $execution->id)
            ->assertJsonMissingPath('0.metadata.tool_references.0.response');
        $this->getJson($this->url($conversation, $answer, $execution))->assertOk()->assertJsonPath('used_in_answer', false);
        $this->assertArrayNotHasKey('tool_references', $answer->fresh()->metadata);
    }

    public function test_research_children_are_included_only_when_their_parent_and_owner_match(): void
    {
        [$user, $conversation, $answer, $execution] = $this->fixture();
        $parent = $execution->run;
        $child = $parent->replicate();
        $child->run_id = (string) Str::uuid();
        $child->input_json = ['research_parent_id' => $parent->id];
        $child->save();
        $execution->update(['agent_run_id' => $child->id]);
        $parent->update(['result_json' => ['research_runs' => [$child->id]]]);
        $answer->update(['metadata' => ['tool_sources' => []]]);
        $this->actingAs($user)->getJson($this->url($conversation, $answer, $execution))->assertOk();
        $child->update(['input_json' => ['research_parent_id' => -1]]);
        $this->getJson($this->url($conversation, $answer, $execution))->assertNotFound();
    }

    public function test_another_user_cannot_read_the_call(): void
    {
        [, $conversation, $answer, $execution] = $this->fixture();
        $intruder = User::create(['name' => 'Other', 'email' => 'other-source@example.test', 'password' => bcrypt('secret')]);
        $this->actingAs($intruder)->getJson($this->url($conversation, $answer, $execution))->assertNotFound();
    }

    public function test_current_project_permissions_are_required_to_read_a_previous_result(): void
    {
        [$user, $conversation, $answer, $execution] = $this->fixture();
        $user->projectMemberships()->delete();
        $this->actingAs($user)->getJson($this->url($conversation, $answer, $execution))->assertNotFound();
    }

    public function test_a_cited_id_cannot_cross_tenants_or_conversations(): void
    {
        [$user, $conversation, $answer, $execution] = $this->fixture();
        $run = $execution->run;
        $run->update(['tenant_id' => 'foreign-tenant']);
        $this->actingAs($user)->getJson($this->url($conversation, $answer, $execution))->assertNotFound();
        $other = Conversation::create(['tenant_id' => 'test-tenant', 'user_id' => $user->id, 'project_key' => 'orders']);
        $run->update(['tenant_id' => 'test-tenant', 'conversation_id' => $other->id]);
        $this->getJson($this->url($conversation, $answer, $execution))->assertNotFound();
    }

    public function test_historical_tool_calls_remain_readable_without_reconstructing_an_http_request(): void
    {
        [$user, $conversation, $answer, $execution] = $this->fixture();
        $execution->update(['tool_kind' => 'mcp', 'result_meta_json' => ['result' => ['content' => [['text' => 'Recorded result']]]]]);
        $this->actingAs($user)->getJson($this->url($conversation, $answer, $execution))
            ->assertOk()->assertJsonPath('request', null)->assertJsonPath('kind', 'mcp')
            ->assertJsonPath('response.content.0.text', 'Recorded result');
    }

    private function fixture(): array
    {
        $user = User::create(['name' => 'Reader', 'email' => 'tool-source@example.test', 'password' => bcrypt('secret')]);
        $user->projectMemberships()->create(['tenant_id' => 'test-tenant', 'project_key' => 'orders', 'role' => 'viewer']);
        $conversation = Conversation::create(['tenant_id' => 'test-tenant', 'user_id' => $user->id, 'project_key' => 'orders']);
        $run = AgentRun::create(['run_id' => (string) Str::uuid(), 'tenant_id' => 'test-tenant', 'conversation_id' => $conversation->id,
            'project_key' => 'orders', 'user_id' => $user->id, 'channel' => 'chat', 'actor_type' => 'user', 'actor_id' => (string) $user->id,
            'locale' => 'it', 'timezone' => 'Europe/Rome', 'status' => 'completed', 'input_json' => ['private_prompt' => 'never exposed']]);
        $execution = $run->toolExecutions()->create(['logical_index' => 1, 'tool_name' => 'gescat_cerca_ordini', 'tool_kind' => 'api',
            'status' => 'completed', 'arguments_json' => ['ord_codiceweb' => 'ORDER-A', 'api_key' => 'credential-value'],
            'result_meta_json' => ['request' => ['method' => 'GET', 'url' => 'https://erp.example.test/orders', 'headers' => ['Authorization' => 'Bearer credential-value']],
                'result' => ['data' => [['ord_codiceweb' => 'ORDER-A', 'access_token' => 'credential-value']]], 'complete' => true],
            'physical_request_count' => 1, 'latency_ms' => 42, 'completed_at' => now()]);
        $answer = $conversation->messages()->create(['agent_run_id' => $run->id, 'role' => 'assistant', 'content' => 'ORDER-A', 'metadata' => [
            'tool_sources' => [['execution_id' => $execution->id, 'tool' => 'gescat_cerca_ordini', 'kind' => 'api', 'display_name' => 'GESCAT · Ordini']],
        ]]);
        return [$user, $conversation, $answer, $execution];
    }

    private function url($conversation, $answer, $execution): string
    {
        return "/test-conversations/{$conversation->id}/messages/{$answer->id}/tool-sources/{$execution->id}";
    }
}
