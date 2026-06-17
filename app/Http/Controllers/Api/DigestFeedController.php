<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\DigestPreference;
use App\Models\EngagementDigestFeedEntry;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * v8.15/W3 — the in-app digest feed (R44 HTTP surface).
 *
 * `/api/me/digest/latest` — the latest generated rich digest for the caller's
 * tenant ("This week in your KB"), plus the caller's section preferences so the
 * SPA can honour their toggles client-side. Tenant-scoped (R30); auth:sanctum.
 */
final class DigestFeedController extends Controller
{
    public function __construct(private readonly TenantContext $tenants)
    {
    }

    public function latest(Request $request): JsonResponse
    {
        $entry = EngagementDigestFeedEntry::query()
            ->where('tenant_id', $this->tenants->current())
            ->latestEntry()
            ->first();

        $pref = DigestPreference::query()
            ->where('tenant_id', $this->tenants->current())
            ->where('user_id', (int) $request->user()->getKey())
            ->first();

        return response()->json([
            'has_digest' => $entry !== null,
            'digest' => $entry?->payload,
            'generated_at' => $entry?->created_at?->toIso8601String(),
            // The user's enabled sections so the SPA can filter the card.
            'enabled_sections' => $pref?->sections ?? DigestPreference::SECTIONS,
        ]);
    }
}
