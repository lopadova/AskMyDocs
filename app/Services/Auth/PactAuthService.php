<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\OAuthClient;
use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;

/** Application OAuth profile. PACT contract negotiation is a separate boundary. */
final class PactAuthService
{
    public const SCOPE = 'pact:delegate';

    public function enabled(): bool
    {
        return (bool) config('pact-auth.enabled');
    }

    public function isRegistration(array $data): bool
    {
        return ($data['client_name'] ?? '') === 'Sorgente'
            || in_array(self::SCOPE, preg_split('/\s+/', trim($data['scope'] ?? '')), true);
    }

    public function register(array $data, bool $operator = false): array
    {
        if (! $this->enabled() || (! $operator && ! config('pact-auth.dynamic_registration'))) {
            $this->error('registration_not_supported', 'PactAuth client registration is unavailable.');
        }
        if (($data['token_endpoint_auth_method'] ?? 'none') !== 'none'
            || ($data['grant_types'] ?? ['authorization_code']) !== ['authorization_code']
            || ($data['response_types'] ?? ['code']) !== ['code']
            || (isset($data['scope']) && $data['scope'] !== self::SCOPE)) {
            $this->error('invalid_client_metadata', 'Use a public authorization-code client with pact:delegate only.');
        }
        foreach ($data['redirect_uris'] as $uri) {
            $this->checkRedirect($uri);
        }
        $client = OAuthClient::create([
            'name' => $data['client_name'] ?? 'Sorgente',
            'redirect_uris' => array_values(array_unique($data['redirect_uris'])),
            'scopes' => [self::SCOPE],
        ]);

        return ['client_id' => $client->id, 'client_name' => $client->name,
            'token_endpoint_auth_method' => 'none', 'redirect_uris' => $client->redirect_uris,
            'grant_types' => ['authorization_code'], 'response_types' => ['code'],
            'scope' => self::SCOPE, 'client_id_issued_at' => now()->timestamp];
    }

    public function checkRedirect(string $uri): void
    {
        $parts = parse_url($uri);
        $origin = is_array($parts) && isset($parts['scheme'], $parts['host'])
            ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '') : '';
        $https = ($parts['scheme'] ?? '') === 'https';
        $localHttp = app()->environment(['local', 'testing']) && config('pact-auth.allow_local_http')
            && ($parts['scheme'] ?? '') === 'http';
        if (strlen($uri) > 2048 || filter_var($uri, FILTER_VALIDATE_URL) === false || ! is_array($parts)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f\\\\]/', $uri) || (! $https && ! $localHttp)
            || ! in_array($origin, config('pact-auth.allowed_client_origins', []), true)
            || preg_match('#^/[a-z0-9]+(?:-[a-z0-9]+)*/integrations/pact-auth/askmydocs/callback$#D', $parts['path'] ?? '') !== 1) {
            $this->error('invalid_redirect_uri', 'Use the exact Sorgente company callback on an explicitly allowed client origin.');
        }
    }

    public function verified(User $user): void
    {
        if (! $this->enabled() || ! $user->is_active || $user->email_verified_at === null) {
            $this->error('access_denied', 'An active account with a verified email is required for PactAuth.', 403);
        }
    }

    public function clientAllowed(OAuthClient $client): bool
    {
        if (! $this->enabled() || ! $client->enabled || $client->scopes !== [self::SCOPE]) return false;
        try {
            foreach ($client->redirect_uris as $uri) $this->checkRedirect($uri);
        } catch (HttpResponseException) {
            return false;
        }
        return $client->redirect_uris !== [];
    }

    /** Returns a snapshot that invalidates the grant when its authority changes. */
    public function membership(User $user, string $tenant, string $project): ?string
    {
        if (! $this->enabled() || $user->email_verified_at === null || $project === '' || $project === '*'
            || ! app(OAuthAccessPolicy::class)->canUseTenant($user, $tenant)) {
            return null;
        }
        $membership = ProjectMembership::forTenant($tenant)->where('user_id', $user->id)
            ->where('project_key', $project)->first();
        if ($membership === null || ! in_array($membership->role, ['owner', 'admin', 'member'], true)) {
            return null;
        }
        return hash('sha256', json_encode([
            'role' => $membership->role, 'scope' => $membership->scope_allowlist,
            'permissions' => $user->getAllPermissions()->pluck('name')->sort()->values()->all(),
        ], JSON_THROW_ON_ERROR));
    }

    public function ttl(): int
    {
        return max(60, min(31536000, (int) config('pact-auth.token_ttl_seconds', 86400)));
    }

    public function metadata(string $origin): array
    {
        $parts = parse_url($origin);
        $expected = is_array($parts) && isset($parts['scheme'], $parts['host'])
            ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '') : '';
        if ($origin !== $expected || filter_var($origin, FILTER_VALIDATE_URL) === false
            || (($parts['scheme'] ?? '') !== 'https' && ! (app()->environment(['local', 'testing'])
                && config('pact-auth.allow_local_http') && ($parts['scheme'] ?? '') === 'http'))) {
            $this->error('server_error', 'PactAuth requires APP_URL to be an HTTPS origin.', 503);
        }
        return ['endpoint' => $origin.'/api/pact/a2a', 'card_url' => $origin.'/.well-known/agent-card.json',
            'producer_id' => config('pact-auth.producer_id'), 'transport_available' => false];
    }

    public function error(string $error, string $description, int $status = 400): never
    {
        throw new HttpResponseException(response()->json(['error' => $error, 'error_description' => $description],
            $status, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache', 'Referrer-Policy' => 'no-referrer']));
    }
}
