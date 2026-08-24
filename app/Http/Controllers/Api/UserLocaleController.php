<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Support\SupportedLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class UserLocaleController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        return response()->json([
            'locale' => SupportedLocale::normalize($user->locale),
            'supported' => SupportedLocale::supportedCatalogs(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $validated = $request->validate([
            'locale' => [
                'required',
                'string',
                'max:35',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! SupportedLocale::isSupported($value)) {
                        $fail("The {$attribute} is not supported.");
                    }
                },
            ],
        ]);

        $user->locale = SupportedLocale::normalize($validated['locale']);
        $user->save();

        return response()->json([
            'locale' => $user->locale,
            'supported' => SupportedLocale::supportedCatalogs(),
        ]);
    }
}
