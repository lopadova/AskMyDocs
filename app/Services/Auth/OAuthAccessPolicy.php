<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Compliance\TenantContextBridge;
use App\Models\OAuthAccessToken;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use App\Support\SystemTenantRegistry;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as ConnectorTenantContext;

final class OAuthAccessPolicy
{
    // Remains on the PAT even if its OAuth metadata is missing: fail closed.
    public const TOKEN_MARKER = 'oauth:external';

    public function canUseTenant(User $user, string $tenantId): bool
    {
        if (! $user->is_active || SystemTenantRegistry::isReserved($tenantId)
            || ! ProjectMembership::forTenant($tenantId)->where('user_id', $user->id)->exists()) {
            return false;
        }
        $tenant = Schema::hasTable('tenants') ? Tenant::where('slug', $tenantId)->first() : null;
        return $tenant === null || ($tenant->status === 'active' && ! (bool) $tenant->getAttribute('is_system'));
    }

    public function validate(PersonalAccessToken $token, bool $valid, Request $request): bool
    {
        if (! in_array(self::TOKEN_MARKER, $token->abilities ?? [], true)) {
            return $valid;
        }

        if (! $valid || ! $token->tokenable instanceof User) {
            return false;
        }

        $grant = OAuthAccessToken::query()->with('client')
            ->where('personal_access_token_id', $token->id)->first();
        if ($grant === null || ! $grant->client?->enabled || $grant->user_id !== $token->tokenable->id
            || ! $this->canUseTenant($token->tokenable, $grant->tenant_id)
            || ($request->hasHeader('X-Tenant-Id') && $request->header('X-Tenant-Id') !== $grant->tenant_id)) {
            return false;
        }

        // Protects even an admin's token from routes without token.ability.
        $scope = match (true) {
            $request->isMethod('GET') && $request->is('oauth/userinfo') => null,
            $request->isMethod('POST') && $request->is('api/auth/token/revoke') => null,
            $request->isMethod('POST') && $request->is('api/kb/chat') => 'kb:chat',
            $request->isMethod('GET') && ($request->is('api/kb/documents/search', 'api/kb/tree')
                || preg_match('#^api/kb/documents/[0-9]+/preview$#D', $request->path()) === 1) => 'kb:read',
            default => false,
        };
        if ($scope === false || ($scope !== null && (! $token->can($scope)
            || ! in_array($scope, $grant->scopes, true) || ! in_array($scope, $grant->client->scopes, true)))) {
            return false;
        }

        app(TenantContext::class)->set($grant->tenant_id);
        app(ConnectorTenantContext::class)->set($grant->tenant_id);
        app(TenantContextBridge::class)->syncFromHost();

        return true;
    }
}
