<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * v8.0.1 / deep-review F5 — per-user chat preferences endpoint.
 *
 * Backs the previously browser-local `localStorage` counterfactual
 * toggle so the choice survives multi-device use, fresh sessions,
 * and cache wipes. The shape is intentionally open-ended: the BE
 * stores a JSON blob and returns it verbatim; the FE owns the keys
 * (`counterfactual_enabled` today, future toggles tomorrow). New
 * keys land FE-first without a BE deploy.
 *
 * Endpoints (Sanctum-gated):
 *   GET   /api/me/chat-preferences
 *   PATCH /api/me/chat-preferences
 *     body: { "preferences": { "<key>": <bool|"0"|"1"|"true"|"false"|null>, ... } }
 *
 * The PATCH path is additive — the BE merges `preferences` over the
 * existing preferences map. A key with value `null` deletes the
 * entry (lets the FE fall back to its default). Wire format accepts
 * BOTH native JSON booleans AND their string equivalents (FE clients
 * preserving payload size send `'0'`/`'1'`; the BE coerces).
 *
 * Cross-tenant by design: chat preferences belong to user identity,
 * not to a tenant boundary. The user crossing tenants keeps their
 * chat ergonomics.
 */
final class ChatPreferencesController extends Controller
{
    /**
     * Default preference values returned alongside the persisted map
     * so the FE can render a sensible UI on first load without a
     * round-trip to the BE. The contract is "BE returns the merged
     * view of (defaults + user-set values)" — the FE never has to
     * reason about missing keys.
     *
     * @var array<string, scalar>
     */
    private const DEFAULTS = [
        'counterfactual_enabled' => true,
    ];

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        return response()->json([
            'preferences' => $this->merged($user->chat_preferences ?? []),
            'defaults' => self::DEFAULTS,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        $validated = $request->validate([
            // The schema is open-ended (FE-owned keys) but we still
            // require the body to be a flat associative array of
            // boolean values (or their string equivalents) plus
            // explicit `null` to delete — anything else would bloat
            // the column or smuggle nested shape into storage.
            'preferences' => ['required', 'array'],
            'preferences.*' => [
                'nullable',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || is_bool($value)) {
                        return;
                    }
                    if (is_string($value) && in_array($value, ['0', '1', 'true', 'false'], true)) {
                        return;
                    }
                    $fail("{$attribute} must be a boolean, the string '0'/'1'/'true'/'false', or null.");
                },
            ],
        ]);

        $current = is_array($user->chat_preferences) ? $user->chat_preferences : [];
        foreach ($validated['preferences'] as $key => $rawValue) {
            if ($rawValue === null) {
                unset($current[$key]);
                continue;
            }
            // Accept BOTH native booleans (JSON `true`/`false`) and
            // their string equivalents (`'1'`/`'true'` etc.) so the
            // FE wire format can pick whichever is cheaper without
            // breaking the contract.
            if (is_bool($rawValue)) {
                $current[$key] = $rawValue;
                continue;
            }
            $current[$key] = in_array($rawValue, ['1', 'true'], true);
        }

        $user->chat_preferences = $current;
        $user->save();

        return response()->json([
            'preferences' => $this->merged($current),
            'defaults' => self::DEFAULTS,
        ]);
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private function merged(array $stored): array
    {
        return array_merge(self::DEFAULTS, $stored);
    }
}
