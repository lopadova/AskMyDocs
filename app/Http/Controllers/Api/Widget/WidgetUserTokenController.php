<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Widget;

use App\Models\WidgetKey;
use App\Services\Widget\WidgetUserTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Support\SupportedLocale;

final class WidgetUserTokenController extends Controller
{
    public function __invoke(Request $request, WidgetUserTokenService $tokens): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'origin' => ['required', 'url', 'max:255'],
            'locale' => [
                'sometimes',
                'string',
                'max:35',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! SupportedLocale::isSupported($value)) {
                        $fail("The {$attribute} is not supported.");
                    }
                },
            ],
        ]);

        $publicKey = (string) $request->header('X-Widget-Key', '');
        $secret = (string) ($request->bearerToken() ?? '');
        $key = WidgetKey::query()->where('public_key', $publicKey)->first();

        if ($key === null || ! $key->is_active || ! $key->user_auth_enabled
            || ! is_string($key->identity_secret_hash)
            || ! \Illuminate\Support\Facades\Hash::check($secret, $key->identity_secret_hash)) {
            return response()->json([
                'error' => 'identity_credentials_invalid',
                'message' => 'Invalid widget identity credentials.',
            ], 401);
        }

        if (! $key->originAllowed($data['origin'])) {
            return response()->json([
                'error' => 'origin_not_allowed',
                'message' => 'The requested origin is not allowed for this widget key.',
            ], 403);
        }

        $result = $tokens->issue(
            $key,
            $data['subject'],
            $data['origin'],
            is_string($data['locale'] ?? null) ? $data['locale'] : null,
        );

        return response()->json([
            'token' => $result['token'],
            'expires_at' => $result['expires_at'],
            'locale' => SupportedLocale::normalize(is_string($data['locale'] ?? null) ? $data['locale'] : null),
        ], 201);
    }
}
