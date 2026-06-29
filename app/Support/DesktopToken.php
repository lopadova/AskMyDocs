<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Laravel\Sanctum\NewAccessToken;

/**
 * Single source of truth for the desktop (Tauri) Bearer-token policy.
 *
 * Both non-browser auth flows mint their token through here so the security
 * contract never drifts between them:
 *   - POST /api/auth/token          — Bearer login (existing credentials).
 *   - POST /api/auth/register-token — invite-only Bearer sign-up.
 *
 * The token is least-privilege (exactly the abilities the desktop client uses,
 * never the `['*']` wildcard) and carries a finite TTL so a token leaked from a
 * lost device self-revokes server-side (the global `sanctum.expiration` is
 * null). The consuming routes are gated with `token.ability:<ability>`.
 */
final class DesktopToken
{
    /**
     * Least-privilege ability scope for a desktop personal access token — the
     * Tauri client only ever calls the KB read endpoints (search + preview) and
     * the chat endpoint.
     *
     * @var list<string>
     */
    public const ABILITIES = ['kb:read', 'kb:chat'];

    /** Finite token lifetime, in days. */
    public const TTL_DAYS = 30;

    /** Server-side fallback label for clients that omit `device_name`. */
    public const DEFAULT_DEVICE_NAME = 'desktop-demo';

    /**
     * Mint a desktop Bearer token for the user with the shared ability scope +
     * TTL. A blank/absent device name falls back to {@see DEFAULT_DEVICE_NAME}.
     */
    public static function mint(User $user, ?string $deviceName = null): NewAccessToken
    {
        $name = trim((string) $deviceName);

        return $user->createToken(
            $name !== '' ? $name : self::DEFAULT_DEVICE_NAME,
            self::ABILITIES,
            now()->addDays(self::TTL_DAYS),
        );
    }
}
