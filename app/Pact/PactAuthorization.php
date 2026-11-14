<?php

declare(strict_types=1);

namespace App\Pact;

use App\Models\OAuthAccessToken;
use App\Models\User;
use App\Services\Auth\PactAuthService;
use Pact\PactError;
use Padosoft\PactAgent\Contracts\IdentityAuthorization;
use Padosoft\PactAgent\Contracts\InboundAuthenticator;

final class PactAuthorization implements InboundAuthenticator, IdentityAuthorization
{
    public function authenticate(string $authorization, string $agent): array
    {
        $grant = request()->attributes->get('pact.grant');
        $user = request()->user();
        if (! $grant instanceof OAuthAccessToken || ! $user instanceof User || $agent !== 'askmydocs') throw new PactError('AUTHENTICATION', 'PactAuth grant required');
        return ['tenant' => (string) $grant->tenant_id, 'user' => (string) $user->id,
            'context' => ['grant_id' => (string) $grant->id, 'project_key' => (string) $grant->pact_project_key]];
    }

    public function authorize(string $action, string $tenantId, string $userId, string $agentId, array $context = []): void
    {
        $principal = $context['principal_context'] ?? [];
        $grant = OAuthAccessToken::query()->with(['client', 'token'])->whereKey($principal['grant_id'] ?? null)->first();
        $user = User::query()->find($userId);
        $policy = app(PactAuthService::class);
        if ($agentId !== 'askmydocs' || ! $user instanceof User || ! $grant instanceof OAuthAccessToken || (string) $grant->tenant_id !== $tenantId || (string) $grant->user_id !== $userId
            || $grant->pact_project_key !== ($principal['project_key'] ?? null) || $grant->scopes !== [PactAuthService::SCOPE]
            || $grant->token === null || (string) $grant->token->tokenable_id !== $userId || $grant->token->expires_at === null || ! $grant->token->expires_at->isFuture()
            || ((int) config('sanctum.expiration') > 0 && $grant->token->created_at->lte(now()->subMinutes((int) config('sanctum.expiration'))))
            || ! $grant->token->can(PactAuthService::SCOPE) || $grant->client === null || ! $policy->clientAllowed($grant->client)
            || ! is_string($grant->pact_membership_hash) || ! hash_equals($grant->pact_membership_hash, (string) $policy->membership($user, $tenantId, (string) $grant->pact_project_key))) {
            throw new PactError('AUTHORIZATION', 'PactAuth grant is no longer valid');
        }
    }
}
