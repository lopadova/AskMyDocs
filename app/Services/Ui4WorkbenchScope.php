<?php

namespace App\Services;

use App\Support\TenantContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use RuntimeException;

final class Ui4WorkbenchScope
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @return array{scope: string, tenant_id: string, user_id: int}
     */
    public function current(Request $request): array
    {
        $user = $request->user();

        if (! is_object($user) || ! method_exists($user, 'getAuthIdentifier')) {
            throw new AuthenticationException('UI4 requires an authenticated host identity.');
        }

        return $this->forUser($user);
    }

    /**
     * @return array{scope: string, tenant_id: string, user_id: int}
     */
    public function forUser(object $user): array
    {
        if (! method_exists($user, 'getAuthIdentifier')) {
            throw new AuthenticationException('UI4 requires an authenticated host identity.');
        }

        $tenantId = $this->tenants->current();
        $userId = (int) $user->getAuthIdentifier();

        if ($userId <= 0) {
            throw new AuthenticationException('UI4 requires a stable host identity.');
        }

        return [
            'scope' => 'ui4:'.hash_hmac('sha256', $tenantId.'|'.$userId, (string) config('app.key')),
            'tenant_id' => $tenantId,
            'user_id' => $userId,
        ];
    }

    /**
     * @return array{scope: string, tenant_id: string, user_id: int}
     */
    public function requireCurrent(string $actor, Request $request): array
    {
        $identity = $this->current($request);

        if (! hash_equals($identity['scope'], $actor)) {
            throw new RuntimeException('not_authorized');
        }

        return $identity;
    }
}
