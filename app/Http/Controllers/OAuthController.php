<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\OAuthAccessToken;
use App\Models\OAuthAuthorizationCode;
use App\Models\OAuthClient;
use App\Models\User;
use App\Services\Auth\OAuthAccessPolicy;
use App\Services\Auth\UserTeamsResolver;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

final class OAuthController extends Controller
{
    public function __construct(private readonly OAuthAccessPolicy $policy, private readonly UserTeamsResolver $teams) {}

    public function metadata(): JsonResponse
    {
        $issuer = $this->issuer();
        return response()->json([
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/oauth/authorize',
            'token_endpoint' => $issuer.'/oauth/token',
            'revocation_endpoint' => $issuer.'/oauth/revoke',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'revocation_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => array_keys(config('oauth.scopes')),
            'authorization_response_iss_parameter_supported' => true,
        ]);
    }

    public function authorize(Request $request): RedirectResponse
    {
        $data = $this->validate($request->query(), [
            'client_id' => ['required', 'string', 'max:64'],
            'redirect_uri' => ['required', 'string', 'max:2048'],
            'response_type' => ['required', 'in:code'],
            'state' => ['required', 'string', 'max:512'],
            'code_challenge' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/D'],
            'code_challenge_method' => ['required', 'in:S256'],
            'scope' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $client = $this->client($data['client_id']);
        $this->checkRedirect($client, $data['redirect_uri']);
        $data['scopes'] = $this->scopes($client, $data['scope'] ?? null);

        // Server-side parameters bind the consent to this browser. The SPA
        // receives only an opaque handle, never editable redirect/PKCE fields.
        $pending = array_filter((array) $request->session()->get('oauth.requests', []),
            static fn (array $item): bool => $item['expires_at'] > now()->timestamp);
        $pending = array_slice($pending, -9, null, true);
        $id = Str::random(48);
        $data['expires_at'] = now()->timestamp + (int) config('oauth.authorization_ttl_seconds');
        $pending[$id] = $data;
        $request->session()->put('oauth.requests', $pending);
        return redirect('/oauth/consent?request='.$id);
    }

    public function consent(Request $request, string $authorization): JsonResponse
    {
        [$data, $client] = $this->pending($request, $authorization);
        $user = $this->activeUser($request);
        return response()->json([
            'client' => ['name' => $client->name, 'redirect_uri' => $data['redirect_uri']],
            'scopes' => array_map(static fn (string $scope): array => [
                'id' => $scope, 'description' => config('oauth.scopes.'.$scope),
            ], $data['scopes']),
            'teams' => array_map(static fn (array $team): array => [
                'tenant_id' => $team['tenant_id'], 'name' => $team['name'],
            ], $this->teams->resolve($user)),
            'user' => ['name' => $user->name, 'email' => $user->email],
            'token_ttl_days' => (int) config('oauth.token_ttl_days'),
        ]);
    }

    public function approve(Request $request, string $authorization): JsonResponse
    {
        [$data, $client] = $this->pending($request, $authorization);
        $user = $this->activeUser($request);
        $choice = $this->validate($this->body($request), [
            'decision' => ['required', 'in:approve,deny'],
            'tenant_id' => ['required_if:decision,approve', 'nullable', 'string', 'max:64'],
        ]);
        $params = ['state' => $data['state'], 'iss' => $this->issuer()];
        if ($choice['decision'] === 'deny') {
            $params['error'] = 'access_denied';
        } else {
            if (! $this->policy->canUseTenant($user, $choice['tenant_id'])) {
                $this->error('access_denied', 'Choose an active company you belong to.', 403);
            }
            $code = Str::random(64);
            OAuthAuthorizationCode::create([
                'code_hash' => hash('sha256', $code), 'client_id' => $client->id,
                'user_id' => $user->id, 'tenant_id' => $choice['tenant_id'],
                'redirect_uri' => $data['redirect_uri'], 'scopes' => $data['scopes'],
                'code_challenge' => $data['code_challenge'],
                'expires_at' => now()->addSeconds((int) config('oauth.code_ttl_seconds')),
            ]);
            $params['code'] = $code;
        }
        $request->session()->forget('oauth.requests.'.$authorization);
        $redirect = $data['redirect_uri'].(str_contains($data['redirect_uri'], '?') ? '&' : '?')
            .http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        return response()->json(['redirect_to' => $redirect]);
    }

    public function token(Request $request): JsonResponse
    {
        if ($request->input('grant_type') !== 'authorization_code') {
            $this->error('unsupported_grant_type', 'Only authorization_code is supported.');
        }
        $data = $this->validate($this->body($request), [
            'grant_type' => ['required', 'in:authorization_code'],
            'client_id' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'size:64'],
            'redirect_uri' => ['required', 'string', 'max:2048'],
            'code_verifier' => ['required', 'string', 'regex:/^[A-Za-z0-9._~-]{43,128}$/D'],
        ]);
        $client = $this->client($data['client_id']);
        $this->checkRedirect($client, $data['redirect_uri']);
        return DB::transaction(function () use ($data, $client): JsonResponse {
            $code = OAuthAuthorizationCode::where('code_hash', hash('sha256', $data['code']))->lockForUpdate()->first();
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $data['code_verifier'], true)), '+/', '-_'), '=');
            if ($code === null || $code->consumed_at !== null || $code->expires_at->isPast()
                || $code->client_id !== $client->id || $code->redirect_uri !== $data['redirect_uri']
                || ! hash_equals($code->code_challenge, $challenge)
                || array_diff($code->scopes, $client->scopes) !== []) {
                $this->error('invalid_grant', 'The authorization code is invalid or expired.');
            }
            $user = User::find($code->user_id);
            if ($user === null || ! $this->policy->canUseTenant($user, $code->tenant_id)) {
                $this->error('invalid_grant', 'The authorization is no longer available.');
            }

            // The conditional update also covers engines where a row lock
            // alone does not prevent two requests from observing an unused code.
            if (OAuthAuthorizationCode::whereKey($code->id)->whereNull('consumed_at')
                ->where('expires_at', '>', now())->update(['consumed_at' => now()]) !== 1) {
                $this->error('invalid_grant', 'The authorization code has already been used.');
            }
            $expires = now()->addDays((int) config('oauth.token_ttl_days'));
            $sanctumTtl = (int) config('sanctum.expiration');
            if ($sanctumTtl > 0) {
                $expires = $expires->min(now()->addMinutes($sanctumTtl));
            }
            $token = $user->createToken('OAuth: '.$client->name,
                [...$code->scopes, OAuthAccessPolicy::TOKEN_MARKER], $expires);
            OAuthAccessToken::create([
                'personal_access_token_id' => $token->accessToken->id, 'client_id' => $client->id,
                'user_id' => $user->id, 'tenant_id' => $code->tenant_id, 'scopes' => $code->scopes,
            ]);
            return response()->json([
                'access_token' => $token->plainTextToken, 'token_type' => 'Bearer',
                'expires_in' => max(0, $expires->timestamp - now()->timestamp),
                'scope' => implode(' ', $code->scopes), 'tenant_id' => $code->tenant_id,
            ]);
        });
    }

    public function revoke(Request $request): Response
    {
        $data = $this->validate($this->body($request), [
            'client_id' => ['required', 'string', 'max:64'], 'token' => ['required', 'string', 'max:512'],
        ]);
        $token = PersonalAccessToken::findToken($data['token']);
        if ($token !== null && OAuthAccessToken::where('personal_access_token_id', $token->id)
            ->where('client_id', $data['client_id'])->exists()) {
            $token->delete();
        }
        // RFC 7009: invalid/already-revoked tokens have the same response.
        return response('', 200);
    }

    public function userinfo(Request $request): JsonResponse
    {
        $user = $this->activeUser($request);
        $token = $user->currentAccessToken();
        $grant = $token instanceof PersonalAccessToken
            ? OAuthAccessToken::where('personal_access_token_id', $token->id)->first() : null;
        if ($grant === null) {
            $this->error('invalid_token', 'An OAuth access token is required.', 401);
        }
        return response()->json([
            'sub' => (string) $user->id, 'name' => $user->name, 'email' => $user->email,
            'tenant_id' => $grant->tenant_id, 'scope' => implode(' ', $grant->scopes),
        ]);
    }

    public function connections(Request $request): JsonResponse
    {
        $user = $this->activeUser($request);
        $items = OAuthAccessToken::with(['client', 'token'])->where('user_id', $user->id)->latest()->get();
        return response()->json(['connections' => $items->map(static fn (OAuthAccessToken $grant): array => [
            'id' => $grant->id, 'name' => $grant->client->name, 'tenant_id' => $grant->tenant_id,
            'scopes' => $grant->scopes, 'created_at' => $grant->created_at->toIso8601String(),
            'expires_at' => $grant->token?->expires_at?->toIso8601String(),
            'last_used_at' => $grant->token?->last_used_at?->toIso8601String(),
        ])]);
    }

    public function disconnect(Request $request, int $connection): Response
    {
        $grant = OAuthAccessToken::where('user_id', $this->activeUser($request)->id)->findOrFail($connection);
        $grant->token?->delete();
        $grant->delete();
        return response()->noContent();
    }

    private function pending(Request $request, string $id): array
    {
        $data = $request->session()->get('oauth.requests.'.$id);
        if (! is_array($data) || $data['expires_at'] <= now()->timestamp) {
            $this->error('invalid_request', 'This connection request has expired. Start again from the external application.');
        }
        $client = $this->client($data['client_id']);
        $this->checkRedirect($client, $data['redirect_uri']);
        $this->scopes($client, implode(' ', $data['scopes']));
        return [$data, $client];
    }

    private function activeUser(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->is_active) {
            $this->error('access_denied', 'An active account is required.', 403);
        }
        return $user;
    }

    private function client(string $id): OAuthClient
    {
        $client = OAuthClient::whereKey($id)->where('enabled', true)->first();
        if ($client === null) {
            $this->error('invalid_client', 'The external application is unavailable.');
        }
        return $client;
    }

    private function checkRedirect(OAuthClient $client, string $uri): void
    {
        if (! in_array($uri, $client->redirect_uris, true)) {
            $this->error('invalid_request', 'The callback URL is not registered for this application.');
        }
    }

    private function scopes(OAuthClient $client, ?string $scope): array
    {
        $scopes = $scope === null ? $client->scopes : array_values(array_unique(preg_split('/\s+/', trim($scope))));
        if ($scopes === [] || array_diff($scopes, $client->scopes) !== []
            || array_diff($scopes, array_keys(config('oauth.scopes'))) !== []) {
            $this->error('invalid_scope', 'The requested permissions are unavailable.');
        }
        return $scopes;
    }

    private function validate(array $input, array $rules): array
    {
        $validator = Validator::make($input, $rules);
        if ($validator->fails()) {
            $this->error('invalid_request', $validator->errors()->first());
        }
        return $validator->validated();
    }

    private function body(Request $request): array
    {
        return $request->isJson() ? $request->json()->all() : $request->request->all();
    }

    private function issuer(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    private function error(string $error, string $description, int $status = 400): never
    {
        throw new HttpResponseException(response()->json([
            'error' => $error, 'error_description' => $description,
        ], $status, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache', 'Referrer-Policy' => 'no-referrer']));
    }
}
