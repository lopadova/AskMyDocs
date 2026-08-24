<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Models\WidgetIdentity;
use App\Models\WidgetKey;
use App\Models\WidgetSession;
use App\Models\WidgetSessionStep;
use App\Services\Widget\WidgetIdentityCredentialService;
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

    public function test_user_token_signs_and_restores_the_requested_locale(): void
    {
        $key = $this->key('pk_identity_locale', 'ik_locale');

        $response = $this->postJson('/api/widget/user-token', [
            'subject' => 'internal-user-42',
            'origin' => 'https://host.example',
            'locale' => 'it_it',
        ], [
            'X-Widget-Key' => $key->public_key,
            'Authorization' => 'Bearer ik_locale',
        ])->assertCreated()->assertJsonPath('locale', 'it-IT');

        $validated = app(\App\Services\Widget\WidgetUserTokenService::class)->validate(
            (string) $response->json('token'),
            'https://host.example',
        );

        $this->assertSame('it-IT', $validated['locale'] ?? null);
    }

    public function test_authenticated_history_and_replay_are_scoped_to_identity(): void
    {
        $key = $this->key('pk_history', 'ik_history');
        $alice = $this->token($key, 'alice', 'ik_history');
        $bob = $this->token($key, 'bob', 'ik_history');
        $aliceIdentity = WidgetIdentity::query()->orderBy('id')->firstOrFail();
        $bobIdentity = WidgetIdentity::query()->orderByDesc('id')->firstOrFail();

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
        $this->getJson('/api/widget/sessions/current', $headers)
            ->assertOk()
            ->assertJsonPath('data.id', $aliceSession->public_session_id);

        $this->getJson('/api/widget/sessions/'.$aliceSession->public_session_id.'/replay', $headers)
            ->assertOk()
            ->assertJsonPath('steps.0.args_json.content', 'hello');

        $this->getJson(
            '/api/widget/sessions/'.$aliceSession->public_session_id.'/replay',
            ['Origin' => 'https://host.example', 'Authorization' => 'Bearer '.$bob],
        )->assertNotFound();
        $this->getJson('/api/widget/sessions/current', [
            'Origin' => 'https://host.example',
            'Authorization' => 'Bearer '.$bob,
        ])->assertOk()->assertJsonMissing(['data' => ['id' => $aliceSession->public_session_id]]);
    }

    public function test_authenticated_history_and_replay_are_also_scoped_to_the_keys_project(): void
    {
        $key = $this->key('pk_project_scope', 'ik_project_scope');
        $token = $this->token($key, 'alice', 'ik_project_scope');
        $identity = WidgetIdentity::query()->firstOrFail();
        $ownedSession = $this->makeWidgetSession($key, $identity->id);
        $foreignProjectSession = WidgetSession::query()->create([
            'tenant_id' => $key->tenant_id,
            'widget_key_id' => $key->id,
            'widget_identity_id' => $identity->id,
            'project_key' => 'different-project',
            'public_session_id' => (string) Str::uuid(),
            'status' => WidgetSession::STATUS_ACTIVE,
            'skill' => $key->skill,
        ]);
        $headers = [
            'Origin' => 'https://host.example',
            'Authorization' => 'Bearer '.$token,
        ];

        $this->getJson('/api/widget/sessions', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownedSession->public_session_id);

        $this->getJson(
            '/api/widget/sessions/'.$foreignProjectSession->public_session_id.'/replay',
            $headers,
        )->assertNotFound();
    }

    public function test_user_token_rejects_a_different_origin_and_anonymous_list_is_denied(): void
    {
        $key = $this->key('pk_origin', 'ik_origin');
        $token = $this->token($key, 'alice', 'ik_origin');

        $this->getJson('/api/widget/sessions', [
            'Origin' => 'https://evil.example',
            'Authorization' => 'Bearer '.$token,
        ])->assertUnauthorized()->assertJsonPath('error', 'user_token_invalid');

        $this->getJson('/api/widget/sessions', [
            'Origin' => 'https://host.example',
            'X-Widget-Key' => $key->public_key,
        ])->assertUnauthorized()->assertJsonPath('error', 'user_auth_required');

        $this->getJson('/api/widget/sessions/current', [
            'Origin' => 'https://host.example',
            'X-Widget-Key' => $key->public_key,
        ])->assertUnauthorized()->assertJsonPath('error', 'user_auth_required');
    }

    public function test_removing_an_origin_immediately_invalidates_previously_issued_user_tokens(): void
    {
        $key = $this->key('pk_origin_revoked', 'ik_origin_revoked');
        $token = $this->token($key, 'alice', 'ik_origin_revoked');

        // Revoca operativa dell'host: il claim cifrato conserva la vecchia
        // origin, ma la policy corrente della key non la ammette più.
        $key->forceFill(['allowed_origins' => ['https://replacement.example']])->save();

        $this->getJson('/api/widget/setup', [
            'Origin' => 'https://host.example',
            'Authorization' => 'Bearer '.$token,
        ])->assertUnauthorized()->assertJsonPath('error', 'user_token_invalid');
    }

    public function test_expired_and_tampered_user_tokens_fail_closed(): void
    {
        config()->set('widget.user_token_ttl_minutes', 1);
        $key = $this->key('pk_invalid_user_token', 'ik_invalid_user_token');
        $token = $this->token($key, 'alice', 'ik_invalid_user_token');

        $this->getJson('/api/widget/setup', [
            'Origin' => 'https://host.example',
            'Authorization' => 'Bearer '.$token.'tampered',
        ])->assertUnauthorized()->assertJsonPath('error', 'user_token_invalid');

        try {
            $this->travel(2)->minutes();

            $this->getJson('/api/widget/setup', [
                'Origin' => 'https://host.example',
                'Authorization' => 'Bearer '.$token,
            ])->assertUnauthorized()->assertJsonPath('error', 'user_token_invalid');
        } finally {
            $this->travelBack();
        }
    }

    public function test_disabling_user_auth_revokes_existing_user_and_identity_session_tokens(): void
    {
        $key = $this->key('pk_user_auth_revoked', 'ik_user_auth_revoked');
        $userToken = $this->token($key, 'alice', 'ik_user_auth_revoked');
        $identity = WidgetIdentity::query()->firstOrFail();
        $session = $this->makeWidgetSession($key, $identity->id);

        $mint = $this->postJson('/api/widget/session-token', [
            'session_id' => $session->public_session_id,
        ], [
            'Origin' => 'https://host.example',
            'Authorization' => 'Bearer '.$userToken,
        ])->assertCreated();
        $sessionToken = (string) $mint->json('token');

        app(WidgetIdentityCredentialService::class)->disable(
            $key->id,
            'default',
            0,
            null,
            WidgetIdentityCredentialService::SURFACE_CLI,
        );

        $this->getJson('/api/widget/setup', [
            'Origin' => 'https://host.example',
            'Authorization' => 'Bearer '.$userToken,
        ])->assertUnauthorized()->assertJsonPath('error', 'user_token_invalid');

        $this->getJson('/api/widget/sessions/'.$session->public_session_id.'/replay', [
            'Origin' => 'https://host.example',
            'Authorization' => 'Bearer '.$sessionToken,
        ])->assertUnauthorized()->assertJsonPath('error', 'session_token_invalid');

        $this->assertDatabaseHas('widget_session_tokens', [
            'token' => hash('sha256', $sessionToken),
            'consumed_at' => null,
        ]);

        $reenabled = app(WidgetIdentityCredentialService::class)->enable(
            $key->id,
            'default',
            1,
            null,
            WidgetIdentityCredentialService::SURFACE_CLI,
        );
        $this->assertSame(2, $reenabled->key->identity_credential_version);
        $this->assertSame(1, $reenabled->key->identity_access_epoch);

        // Disable advances the access epoch. Re-enabling must not resurrect a
        // previously issued bearer that has not reached its wall-clock TTL.
        $this->getJson('/api/widget/setup', [
            'Origin' => 'https://host.example',
            'Authorization' => 'Bearer '.$userToken,
        ])->assertUnauthorized()->assertJsonPath('error', 'user_token_invalid');
        $this->getJson('/api/widget/sessions/'.$session->public_session_id.'/replay', [
            'Origin' => 'https://host.example',
            'Authorization' => 'Bearer '.$sessionToken,
        ])->assertUnauthorized()->assertJsonPath('error', 'session_token_invalid');

        $this->postJson('/api/widget/user-token', [
            'subject' => 'alice',
            'origin' => 'https://host.example',
        ], [
            'X-Widget-Key' => $key->public_key,
            'Authorization' => 'Bearer '.$reenabled->plainSecret,
        ])->assertCreated();
    }

    public function test_rotating_identity_secret_rejects_old_minting_but_preserves_issued_user_tokens_until_ttl(): void
    {
        $key = $this->key('pk_identity_rotation', 'ik_before_rotation');
        $issuedUserToken = $this->token($key, 'alice', 'ik_before_rotation');

        $rotated = app(WidgetIdentityCredentialService::class)->rotate(
            $key->id,
            'default',
            0,
            null,
            WidgetIdentityCredentialService::SURFACE_CLI,
        );

        $this->postJson('/api/widget/user-token', [
            'subject' => 'bob',
            'origin' => 'https://host.example',
        ], [
            'X-Widget-Key' => $key->public_key,
            'Authorization' => 'Bearer ik_before_rotation',
        ])->assertUnauthorized()->assertJsonPath('error', 'identity_credentials_invalid');

        $this->getJson('/api/widget/setup', [
            'Origin' => 'https://host.example',
            'Authorization' => 'Bearer '.$issuedUserToken,
        ])->assertOk();

        $this->postJson('/api/widget/user-token', [
            'subject' => 'bob',
            'origin' => 'https://host.example',
        ], [
            'X-Widget-Key' => $key->public_key,
            'Authorization' => 'Bearer '.$rotated->plainSecret,
        ])->assertCreated();
    }

    public function test_current_session_is_found_beyond_the_first_history_page(): void
    {
        $key = $this->key('pk_current_beyond_page', 'ik_current_beyond_page');
        $token = $this->token($key, 'alice', 'ik_current_beyond_page');
        $identity = WidgetIdentity::query()->firstOrFail();
        $open = $this->makeWidgetSession($key, $identity->id);
        $open->forceFill([
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ])->save();

        foreach (range(1, 25) as $offset) {
            $closed = $this->makeWidgetSession($key, $identity->id);
            $closed->forceFill([
                'status' => WidgetSession::STATUS_COMPLETED,
                'created_at' => now()->subMinutes($offset),
                'updated_at' => now()->subMinutes($offset),
            ])->save();
        }

        $headers = [
            'Origin' => 'https://host.example',
            'Authorization' => 'Bearer '.$token,
        ];
        $this->getJson('/api/widget/sessions?per_page=20', $headers)
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonMissing(['id' => $open->public_session_id]);

        $this->getJson('/api/widget/sessions/current', $headers)
            ->assertOk()
            ->assertJsonPath('data.id', $open->public_session_id);
    }

    public function test_current_session_uses_updated_at_then_id_and_returns_204_when_empty(): void
    {
        $key = $this->key('pk_current_order', 'ik_current_order');
        $token = $this->token($key, 'alice', 'ik_current_order');
        $identity = WidgetIdentity::query()->firstOrFail();
        $first = $this->makeWidgetSession($key, $identity->id);
        $second = $this->makeWidgetSession($key, $identity->id);
        $sameTime = now()->subMinute()->startOfSecond();
        $first->forceFill([
            'status' => WidgetSession::STATUS_WAITING_USER,
            'updated_at' => $sameTime,
        ])->save();
        $second->forceFill([
            'status' => WidgetSession::STATUS_WAITING_TOOL,
            'updated_at' => $sameTime,
        ])->save();
        $headers = [
            'Origin' => 'https://host.example',
            'Authorization' => 'Bearer '.$token,
        ];

        $this->getJson('/api/widget/sessions/current', $headers)
            ->assertOk()
            ->assertJsonPath('data.id', $second->public_session_id);

        WidgetSession::query()->update(['status' => WidgetSession::STATUS_COMPLETED]);

        $this->getJson('/api/widget/sessions/current', $headers)
            ->assertNoContent();
    }

    public function test_anonymous_and_different_identity_callers_cannot_mint_a_token_for_an_authenticated_session(): void
    {
        $key = $this->key('pk_session_hijack', 'ik_session_hijack');
        $aliceToken = $this->token($key, 'alice', 'ik_session_hijack');
        $aliceIdentity = WidgetIdentity::query()->firstOrFail();
        $bobToken = $this->token($key, 'bob', 'ik_session_hijack');
        $aliceSession = $this->makeWidgetSession($key, $aliceIdentity->id);

        // Attacco reale: il caller conosce l'UUID pubblico di Alice ma possiede
        // soltanto pk_. Prima del fix riceveva un wt_ collegato alla sua identità.
        $this->postJson('/api/widget/session-token', [
            'session_id' => $aliceSession->public_session_id,
        ], [
            'Origin' => 'https://host.example',
            'X-Widget-Key' => $key->public_key,
        ])->assertNotFound()->assertJsonPath('error', 'widget_session_not_found');

        // Anche un wu_ valido ma appartenente a Bob non può trasferire
        // l'identità di Alice nel token single-use.
        $this->postJson('/api/widget/session-token', [
            'session_id' => $aliceSession->public_session_id,
        ], [
            'Origin' => 'https://host.example',
            'Authorization' => 'Bearer '.$bobToken,
        ])->assertNotFound()->assertJsonPath('error', 'widget_session_not_found');

        $this->assertDatabaseCount('widget_session_tokens', 0);

        // Il token Alice iniziale è stato davvero coniato e usato come caller;
        // evita che la regressione passi senza aver allestito le credenziali.
        $this->assertStringStartsWith('wu_', $aliceToken);
    }

    public function test_matching_identity_can_mint_and_use_a_token_for_its_authenticated_session(): void
    {
        $key = $this->key('pk_session_owner', 'ik_session_owner');
        $aliceToken = $this->token($key, 'alice', 'ik_session_owner');
        $aliceIdentity = WidgetIdentity::query()->firstOrFail();
        $aliceSession = $this->makeWidgetSession($key, $aliceIdentity->id);
        $aliceSession->steps()->create([
            'step_index' => 0,
            'kind' => WidgetSessionStep::KIND_USER_MESSAGE,
            'args_json' => ['content' => 'owner-only history'],
        ]);

        $mint = $this->postJson('/api/widget/session-token', [
            'session_id' => $aliceSession->public_session_id,
        ], [
            'Origin' => 'https://host.example',
            'Authorization' => 'Bearer '.$aliceToken,
        ])->assertCreated();

        $sessionToken = (string) $mint->json('token');
        $this->assertStringStartsWith('wt_', $sessionToken);
        $this->assertDatabaseHas('widget_session_tokens', [
            'token' => hash('sha256', $sessionToken),
            'widget_key_id' => $key->id,
            'widget_session_id' => $aliceSession->id,
        ]);

        // Il token viene consumato sul percorso reale del middleware e ottiene
        // esclusivamente la sessione/identità che il caller possedeva al mint.
        $this->getJson('/api/widget/sessions/'.$aliceSession->public_session_id.'/replay', [
            'Origin' => 'https://host.example',
            'Authorization' => 'Bearer '.$sessionToken,
        ])->assertOk()->assertJsonPath('steps.0.args_json.content', 'owner-only history');
    }

    public function test_anonymous_session_token_mint_remains_compatible_for_public_key_callers(): void
    {
        $key = $this->key('pk_anonymous_session', 'ik_anonymous_session');
        $session = WidgetSession::query()->create([
            'tenant_id' => 'default',
            'widget_key_id' => $key->id,
            'widget_identity_id' => null,
            'project_key' => $key->project_key,
            'public_session_id' => (string) Str::uuid(),
            'status' => WidgetSession::STATUS_ACTIVE,
            'skill' => $key->skill,
        ]);

        $mint = $this->postJson('/api/widget/session-token', [
            'session_id' => $session->public_session_id,
        ], [
            'Origin' => 'https://host.example',
            'X-Widget-Key' => $key->public_key,
        ])->assertCreated();

        $sessionToken = (string) $mint->json('token');
        $this->assertDatabaseHas('widget_session_tokens', [
            'token' => hash('sha256', $sessionToken),
            'widget_session_id' => $session->id,
        ]);

        $this->getJson('/api/widget/sessions/'.$session->public_session_id.'/replay', [
            'Origin' => 'https://host.example',
            'Authorization' => 'Bearer '.$sessionToken,
        ])->assertOk()->assertJsonPath('steps', []);
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

    private function token(WidgetKey $key, string $subject, string $identitySecret): string
    {
        return (string) $this->postJson('/api/widget/user-token', [
            'subject' => $subject,
            'origin' => 'https://host.example',
        ], [
            'X-Widget-Key' => $key->public_key,
            'Authorization' => 'Bearer '.$identitySecret,
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
