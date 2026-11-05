<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Appearance\InstallationAppearance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

final class AppearanceController extends Controller
{
    public function show(InstallationAppearance $appearance): JsonResponse
    {
        return response()->json(['data' => ['theme' => $appearance->theme()]])
            ->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, InstallationAppearance $appearance): JsonResponse
    {
        $validated = $request->validate([
            'theme' => ['required', 'string', Rule::in(InstallationAppearance::THEMES)],
        ]);

        return response()->json(['data' => [
            'theme' => $appearance->setTheme($validated['theme'], $request->user()),
        ]])->header('Cache-Control', 'no-store');
    }
}
