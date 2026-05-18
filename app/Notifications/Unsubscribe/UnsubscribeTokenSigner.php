<?php

declare(strict_types=1);

namespace App\Notifications\Unsubscribe;

use RuntimeException;

/**
 * v8.0/W1.3 — HMAC-SHA256 signer for one-click unsubscribe links
 * embedded in `EmailChannel` notifications.
 *
 * The token encodes `(tenant_id, user_id, event_type)` plus an
 * HMAC signature, base64url-encoded. `verify()` rejects any token
 * whose signature does not match — defeats the "I changed user_id
 * in the URL to unsubscribe someone else" attack.
 *
 * Tokens are NOT single-use; clicking the link twice yields the
 * same idempotent result (`enabled` flips to `false` for matching
 * preference rows). This matches the standard email-list
 * unsubscribe UX and avoids the operational pain of stale tokens.
 *
 * The HMAC secret is `config('askmydocs.notifications.hmac_secret')`
 * — when unset, the signer fails closed (throws) so a misconfigured
 * deployment never ships unsigned links.
 */
final class UnsubscribeTokenSigner
{
    public static function sign(string $tenantId, int $userId, string $eventType): string
    {
        $secret = self::secret();
        $payload = sprintf('%s|%d|%s', $tenantId, $userId, $eventType);
        $sig = hash_hmac('sha256', $payload, $secret);

        return self::base64UrlEncode($payload . '|' . $sig);
    }

    /**
     * Decode + verify a token. Returns the `(tenantId, userId,
     * eventType)` triple on success, or `null` when the token is
     * malformed, the signature does not match, or the secret is
     * unset.
     *
     * @return array{tenant_id: string, user_id: int, event_type: string}|null
     */
    public static function verify(string $token): ?array
    {
        $decoded = self::base64UrlDecode($token);
        if ($decoded === null) {
            return null;
        }

        $parts = explode('|', $decoded);
        if (count($parts) !== 4) {
            return null;
        }
        [$tenantId, $userIdStr, $eventType, $providedSig] = $parts;
        if ($tenantId === '' || $userIdStr === '' || $eventType === '' || $providedSig === '') {
            return null;
        }
        if (! ctype_digit($userIdStr)) {
            return null;
        }

        try {
            $secret = self::secret();
        } catch (RuntimeException) {
            return null;
        }
        $expectedSig = hash_hmac(
            'sha256',
            sprintf('%s|%s|%s', $tenantId, $userIdStr, $eventType),
            $secret,
        );

        if (! hash_equals($expectedSig, $providedSig)) {
            return null;
        }

        return [
            'tenant_id' => $tenantId,
            'user_id' => (int) $userIdStr,
            'event_type' => $eventType,
        ];
    }

    private static function secret(): string
    {
        $secret = (string) config('askmydocs.notifications.hmac_secret', '');
        if ($secret === '') {
            throw new RuntimeException(
                'UnsubscribeTokenSigner: config("askmydocs.notifications.hmac_secret") is not set. '
                .'Generate a 32-byte secret and add it to .env (NOTIFICATIONS_HMAC_SECRET) '
                .'before any notification email leaves the system — unsigned links would be a security hole.'
            );
        }
        return $secret;
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $token): ?string
    {
        $padded = strtr($token, '-_', '+/');
        $padding = strlen($padded) % 4;
        if ($padding > 0) {
            $padded .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($padded, true);
        return $decoded === false ? null : $decoded;
    }
}
