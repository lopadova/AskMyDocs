<?php

declare(strict_types=1);

/**
 * Host configuration for padosoft/laravel-invitations.
 *
 * This overrides the package default (vendor/padosoft/laravel-invitations/
 * config/invitations.php) so the invite routes carry AskMyDocs's real auth +
 * tenant + RBAC stack instead of the package's vendor-neutral `['web','auth']`
 * placeholder. Everything else inherits the package's safe production posture.
 *
 * R32: `admin_middleware` gates the `/api/admin/invitations/*` surface behind
 * the same SPA-session + Sanctum + tenant + role stack as every other admin
 * route; `manageInvitations` (super-admin + admin) is defined in
 * AppServiceProvider and asserted in AdminAuthorizationMatrixTest.
 *
 * R43: `invitation_required` defaults FALSE (env INVITE_REQUIRED) so existing
 * signup is unchanged — closed-beta posture is strictly opt-in.
 */
return [
    // The host user/account model (implements Padosoft\Invitations\Contracts\
    // InvitedAccount via the InteractsWithInvitations trait).
    'user_model' => env('INVITATIONS_USER_MODEL', \App\Models\User::class),

    // Tenant fallback. The active tenant is resolved at runtime through the
    // host TenantContext (bound to Padosoft\Invitations\Contracts\TenantResolver
    // in AppServiceProvider), so this only matters for the single-tenant default.
    'default_tenant' => env('INVITATIONS_DEFAULT_TENANT', 'default'),

    'routes' => [
        'enabled' => (bool) env('INVITATIONS_ROUTES_ENABLED', true),
        'prefix' => env('INVITATIONS_ROUTES_PREFIX', 'api'),

        // Any authenticated AskMyDocs account may redeem a code. SPA-session
        // cookie stack + Sanctum + tenant scope (R30). No role gate — redeeming
        // is a self-service action.
        'user_middleware' => [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Session\Middleware\StartSession::class,
            'auth:sanctum',
            'tenant.authorize',
        ],

        // Admin management (campaigns / code generation / metrics / direct
        // invitations) — same stack PLUS the manageInvitations RBAC gate (R32).
        'admin_middleware' => [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Session\Middleware\StartSession::class,
            'auth:sanctum',
            'tenant.authorize',
            'can:manageInvitations',
        ],
    ],

    // R43 — closed-beta signup gate. Default FALSE: registration proceeds
    // without an invite code unless an operator opts in via INVITE_REQUIRED=true.
    'invitation_required' => (bool) env('INVITE_REQUIRED', false),

    'codes' => [
        'alphabet' => '0123456789ABCDEFGHJKMNPQRSTVWXYZ',
        'default_length' => (int) env('INVITE_CODE_LENGTH', 8),
        'max_attempts' => (int) env('INVITE_CODE_MAX_ATTEMPTS', 5),
        'reserved' => ['ADMIN', 'API', 'ROOT', 'SYSTEM', 'NULL', 'TEST'],
    ],

    'token_bytes' => (int) env('INVITE_TOKEN_BYTES', 32),
    'invitation_ttl_days' => (int) env('INVITE_INVITATION_TTL_DAYS', 7),
    'signing_key' => env('INVITE_SIGNING_KEY'),
    'pending_session_key' => 'invitations.pending_redemption',

    'pii' => [
        'hash_salt' => env('INVITE_PII_SALT'),
        'retention_days' => (int) env('INVITE_PII_RETENTION_DAYS', 90),
        'store_network_fields' => (bool) env('INVITE_STORE_NETWORK_FIELDS', false),
    ],

    'anti_abuse' => [
        'enabled' => (bool) env('INVITE_ANTI_ABUSE_ENABLED', true),
        'thresholds' => [
            'flag' => 25,
            'throttle' => 50,
            'block' => 80,
        ],
        'retry_after' => (int) env('INVITE_ABUSE_RETRY_AFTER', 900),
        'velocity' => [
            'account' => ['max' => 5, 'window' => 86400, 'score' => 30],
            'ip' => ['max' => 10, 'window' => 3600, 'score' => 25],
            'fingerprint' => ['max' => 8, 'window' => 3600, 'score' => 30],
        ],
        'disposable_domains' => ['mailinator.com', 'tempmail.com', 'guerrillamail.com', '10minutemail.com'],
        'disposable_score' => 40,
        'blocklist' => [
            'ip_hashes' => [],
            'emails' => [],
            'domains' => [],
            'accounts' => [],
        ],
        'allowlist' => [
            'ips' => [],
            'domains' => [],
            'accounts' => [],
        ],
    ],
];
