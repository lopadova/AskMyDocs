<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\NotificationPreference;
use App\Notifications\Unsubscribe\UnsubscribeTokenSigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * v8.0/W1.3 — one-click unsubscribe endpoint for the HMAC-signed
 * link embedded in every `EmailChannel` notification.
 *
 * `GET /notifications/unsubscribe/{token}` decodes the token,
 * verifies the HMAC signature, and flips every matching
 * `notification_preferences` row to `enabled=false`. The operation
 * is idempotent: hitting the same link twice has no observable
 * difference from hitting it once. A tampered or invalid token
 * returns 403 — the response never reveals whether the token was
 * unknown vs forged (mitigates user-id enumeration).
 *
 * The route is public (no Sanctum / session auth) because users
 * read email outside the browser session — the HMAC IS the auth.
 */
final class NotificationUnsubscribeController extends Controller
{
    public function __invoke(string $token): JsonResponse
    {
        $decoded = UnsubscribeTokenSigner::verify($token);
        if ($decoded === null) {
            return response()->json([
                'error' => 'invalid_token',
                'message' => 'This unsubscribe link is invalid or has been tampered with.',
            ], 403);
        }

        // Disable every (tenant, user, event_type) channel pref.
        // Returning the affected channel count lets the operator
        // diagnose "I unsubscribed but I still got an email" by
        // verifying which preferences flipped.
        $affected = NotificationPreference::query()
            ->where('tenant_id', $decoded['tenant_id'])
            ->where('user_id', $decoded['user_id'])
            ->where('event_type', $decoded['event_type'])
            ->update(['enabled' => false]);

        return response()->json([
            'status' => 'unsubscribed',
            'event_type' => $decoded['event_type'],
            'channels_disabled' => $affected,
        ], 200);
    }
}
