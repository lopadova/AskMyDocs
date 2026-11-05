<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Services\Admin\TenantBrandingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

final class TeamLogoController extends Controller
{
    public function __construct(private readonly TenantBrandingService $branding) {}

    public function store(Request $request, string $slug): JsonResponse
    {
        $validated = $request->validate([
            'logo' => [
                'required',
                'file',
                'image',
                'mimes:png,jpg,jpeg,webp',
                'max:'.(int) config('tenant-branding.max_kilobytes', 2048),
                Rule::dimensions()->maxWidth((int) config('tenant-branding.max_width', 2400))
                    ->maxHeight((int) config('tenant-branding.max_height', 1200)),
            ],
        ]);

        return response()->json([
            'data' => $this->branding->store($request->user(), $slug, $validated['logo']),
        ]);
    }

    public function destroy(Request $request, string $slug): JsonResponse
    {
        $this->branding->delete($request->user(), $slug);

        return response()->json(['data' => ['logo_url' => null]]);
    }
}
