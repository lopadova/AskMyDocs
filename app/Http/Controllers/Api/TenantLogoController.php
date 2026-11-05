<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Admin\TenantBrandingService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TenantLogoController extends Controller
{
    public function __construct(private readonly TenantBrandingService $branding) {}

    public function __invoke(Request $request, string $slug): StreamedResponse
    {
        $logo = $this->branding->forViewer($request->user(), $slug);
        abort_if($logo === null, 404);

        return Storage::disk($this->branding->disk())->response(
            $logo->logo_path,
            null,
            [
                'Content-Type' => $logo->mime_type,
                'Cache-Control' => 'private, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
