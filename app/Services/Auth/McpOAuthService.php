<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\McpProjectConnection;
use App\Models\OAuthAccessToken;
use App\Models\OAuthClient;
use App\Models\OAuthRefreshToken;
use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** MCP OAuth extends the existing session/consent authority; no second identity store. */
final class McpOAuthService
{
    public const SCOPES = ['mcp:read', 'mcp:ingest'];

    // Some desktop MCP clients use the literal localhost name for their callback.
    // Never accept suffixes, subdomains or arbitrary HTTP hosts here.
    public const LOOPBACK_HOSTS = ['127.0.0.1', '[::1]', 'localhost'];

    public function resource(string $resource): McpProjectConnection
    {
        $prefix = rtrim((string) config('app.url'), '/').'/mcp/knowledge/';
        $id = str_starts_with($resource, $prefix) ? substr($resource, strlen($prefix)) : '';
        if (! Str::isUuid($id) || $id !== strtolower($id)) {
            $this->error('invalid_target', 'This resource is not an AskMyDocs project connection.');
        }
        $connection = McpProjectConnection::find($id);
        if ($connection === null) {
            $this->error('invalid_target', 'This project connection is unavailable.');
        }
        return $connection;
    }

    public function bind(McpProjectConnection $connection, User $user, string $tenant, string $project): void
    {
        if (! app(OAuthAccessPolicy::class)->canUseTenant($user, $tenant)
            || ! ProjectMembership::forTenant($tenant)->where('user_id', $user->id)->where('project_key', $project)->exists()) {
            $this->error('access_denied', 'Choose a project you can access in the selected company.', 403);
        }
        $fresh = McpProjectConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
        if (($fresh->user_id !== null && (int) $fresh->user_id !== (int) $user->id)
            || ($fresh->project_key !== null && ($fresh->tenant_id !== $tenant || $fresh->project_key !== $project))) {
            $this->error('access_denied', 'This URL is already bound to another project. Create another connection.', 403);
        }
        $fresh->update(['user_id' => $user->id, 'tenant_id' => $tenant, 'project_key' => $project]);
    }

    public function register(array $data): array
    {
        foreach ($data['redirect_uris'] as $uri) {
            $parts = parse_url($uri);
            parse_str($parts['query'] ?? '', $query);
            $loopback = in_array($parts['host'] ?? '', self::LOOPBACK_HOSTS, true);
            if ($parts === false || filter_var($uri, FILTER_VALIDATE_URL) === false
                || array_intersect(['code', 'state', 'error', 'error_description', 'iss'], array_keys($query)) !== [] || (isset($parts['user']) || isset($parts['pass'])) || isset($parts['fragment'])
                || ! isset($parts['host']) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                || ($parts['scheme'] !== 'https' && ! $loopback)
                || str_contains($uri, '\\') || preg_match('/[\x00-\x20\x7f]/', $uri)) {
                $this->error('invalid_redirect_uri', 'Use HTTPS or an HTTP loopback callback.');
            }
        }
        $scopes = isset($data['scope']) ? preg_split('/\s+/', trim($data['scope'])) : self::SCOPES;
        if ($scopes === [] || array_diff($scopes, self::SCOPES) !== [] || ! in_array('mcp:read', $scopes, true)) {
            $this->error('invalid_scope', 'Only MCP read and document import permissions are available.');
        }
        $client = OAuthClient::create([
            'name' => $data['client_name'] ?? 'MCP application',
            'redirect_uris' => array_values(array_unique($data['redirect_uris'])), 'scopes' => $scopes,
        ]);
        return ['client_id' => $client->id, 'client_name' => $client->name,
            'redirect_uris' => $client->redirect_uris, 'scope' => implode(' ', $scopes),
            'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none', 'client_id_issued_at' => now()->timestamp];
    }

    public function refreshToken(OAuthAccessToken $grant): string
    {
        $plain = Str::random(96);
        OAuthRefreshToken::create(['token_hash' => hash('sha256', $plain),
            'oauth_access_token_id' => $grant->id, 'expires_at' => $grant->created_at->copy()->addDays(90)]);
        return $plain;
    }

    public function refresh(array $data): array
    {
        // Lock the grant (the family), not just one refresh token: sibling replays
        // serialize with rotation. Returning an error AFTER commit preserves revocation.
        $result = DB::transaction(function () use ($data): array {
            $refresh = OAuthRefreshToken::where('token_hash', hash('sha256', $data['refresh_token']))->first();
            $grant = $refresh ? OAuthAccessToken::with('client')->whereKey($refresh->oauth_access_token_id)->lockForUpdate()->first() : null;
            if ($grant === null || $grant->client_id !== $data['client_id']) {
                return ['error' => true];
            }
            $refresh->refresh();
            $connection = McpProjectConnection::find($grant->mcp_connection_id);
            $user = User::find($grant->user_id);
            if ($refresh->consumed_at !== null || $refresh->expires_at->lte(now()) || ! $grant->client?->enabled
                || $connection === null || $connection->resource() !== $data['resource'] || $user === null
                || ! app(OAuthAccessPolicy::class)->canUseTenant($user, $grant->tenant_id)
                || ! ProjectMembership::forTenant($grant->tenant_id)->where('user_id', $user->id)->where('project_key', $connection->project_key)->exists()
                || array_diff($grant->scopes, $grant->client->scopes) !== []) {
                $grant->token?->delete(); // cascades grant + entire refresh family
                return ['error' => true];
            }
            if (isset($data['scope']) && array_diff(preg_split('/\s+/', trim($data['scope'])), $grant->scopes) !== []) {
                return ['error' => true];
            }
            if (isset($data['scope'])) {
                $scopes = array_values(array_unique(preg_split('/\s+/', trim($data['scope']))));
                if (! in_array('mcp:read', $scopes, true)) return ['error' => true];
                $grant->scopes = $scopes;
            }
            $old = $grant->token;
            $expires = now()->addHour();
            $sanctumTtl = (int) config('sanctum.expiration');
            if ($sanctumTtl > 0) $expires = $expires->min(now()->addMinutes($sanctumTtl));
            $token = $user->createToken('OAuth: '.$grant->client->name, [...$grant->scopes, OAuthAccessPolicy::TOKEN_MARKER], $expires);
            $grant->update(['personal_access_token_id' => $token->accessToken->id]);
            $refresh->update(['consumed_at' => now()]);
            $next = $this->refreshToken($grant);
            $old?->delete();
            return ['access_token' => $token->plainTextToken, 'refresh_token' => $next,
                'token_type' => 'Bearer', 'expires_in' => max(0, $expires->timestamp - now()->timestamp), 'scope' => implode(' ', $grant->scopes)];
        });
        if (isset($result['error'])) {
            $this->error('invalid_grant', 'This refresh authorization is unavailable. Reconnect the application.');
        }
        return $result;
    }

    public function error(string $error, string $description, int $status = 400): never
    {
        throw new HttpResponseException(response()->json(['error' => $error, 'error_description' => $description],
            $status, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']));
    }
}
