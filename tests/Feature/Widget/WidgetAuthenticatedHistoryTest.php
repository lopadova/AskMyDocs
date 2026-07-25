<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Models\WidgetKey;
use App\Models\WidgetSession;
use App\Models\WidgetSessionStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WidgetAuthenticatedHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../routes/api.php');
    }

    public function test_server_credential_issues_origin_bound_pseudonymous_token(): void
    {
        $key = $this->key('pk_identity', 'ik_secret');

        $response = $this->postJson('/api/widget/user-token', [
            'subject' => 'internal-user-42',
            'origin' => 'https://host.example',
        ], [
            'X-Widget-Key' => $key->public_key,
            'Authorization' => 'Bearer ik_secret',
        ])->assertCreated();

        $this->assertStringStartsWith('wu_', $response->json('token'));
        $this->assertDatabaseCount('widget_identities', 1);
        $this->assertDatabaseMissing('widget_identities', [
            'subject_hash' => 'internal-user-42',
        ]);
        $this->assertStringNotContainsString('internal-user-42', $response->json('token'));
    }

    public function test_authenticated_history_and_replay_are_scoped_to_identity(): void
    {
        $key = $this->key('pk_history', 'ik_history');
        $alice = $this->token($key, 'alice');
        $bob = $this->token($key, 'bob');
        $aliceIdentity = \App\Models\WidgetIdentity::query()->orderBy('id')->firstOrFail();
        $bobIdentity = \App\Models\WidgetIdentity::query()->orderByDesc('id')->firstOrFail();

        $aliceSession = $this->makeWidgetSession($key, $aliceIdentity->id);
        $this->makeWidgetSession($key, $bobIdentity->id);
        $aliceSession->steps()->create([
            'step_index' => 0,
            'kind' => WidgetSessionStep::KIND_USER_MESSAGE,
            'args_json' => ['content' => 'hello'],
        ]);

        $headers = ['Origin' => 'https://host.example', 'Authorization' => 'Bearer '.$alice];
        $this->getJson('/api/widget/sessions', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $aliceSession->public_session_id);

        $this->getJson('/api/widget/sessions/'.$aliceSession->public_session_id.'/replay', $headers)
            ->assertOk()
            ->assertJsonPath('steps.0.args_json.content', 'hello');

        $this->getJson(
            '/api/widget/sessions/'.$aliceSession->public_session_id.'/replay',
            ['Origin' => 'https://host.example', 'Authorization' => 'Bearer '.$bob],
        )->assertNotFound();
    }

    public function test_user_token_rejects_a_different_origin_and_anonymous_list_is_denied(): void
    {
        $key = $this->key('pk_origin', 'ik_origin');
        $token = $this->token($key, 'alice');

        $this->getJson('/api/widget/sessions', [
            'Origin' => 'https://evil.example',
            'Authorization' => 'Bearer '.$token,
        ])->assertUnauthorized()->assertJsonPath('error', 'user_token_invalid');

        $this->getJson('/api/widget/sessions', [
            'Origin' => 'https://host.example',
            'X-Widget-Key' => $key->public_key,
        ])->assertUnauthorized()->assertJsonPath('error', 'user_auth_required');
    }

    private function key(string $publicKey, string $identitySecret): WidgetKey
    {
        return WidgetKey::query()->create([
            'tenant_id' => 'default',
            'project_key' => 'docs',
            'public_key' => $publicKey,
            'secret_hash' => Hash::make('sk_proxy'),
            'identity_secret_hash' => Hash::make($identitySecret),
            'label' => $publicKey,
            'allowed_origins' => ['https://host.example'],
            'rate_limit' => 1000,
            'skill' => 'askmydocs-assistant@1',
            'user_auth_enabled' => true,
            'is_active' => true,
        ]);
    }

    private function token(WidgetKey $key, string $subject): string
    {
        return (string) $this->postJson('/api/widget/user-token', [
            'subject' => $subject,
            'origin' => 'https://host.example',
        ], [
            'X-Widget-Key' => $key->public_key,
            'Authorization' => 'Bearer '.($key->public_key === 'pk_history' ? 'ik_history' : 'ik_origin'),
        ])->assertCreated()->json('token');
    }

    private function makeWidgetSession(WidgetKey $key, int $identityId): WidgetSession
    {
        return WidgetSession::query()->create([
            'tenant_id' => 'default',
            'widget_key_id' => $key->id,
            'widget_identity_id' => $identityId,
            'project_key' => $key->project_key,
            'public_session_id' => (string) Str::uuid(),
            'status' => WidgetSession::STATUS_ACTIVE,
            'skill' => $key->skill,
        ]);
    }
}
