<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\DigestPreference;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * v8.15/W3 — per-user rich-digest preferences (R44 HTTP surface).
 *
 * `/api/me/digest-preferences` — the authenticated user's own cadence + section
 * toggles. auth:sanctum + tenant.authorize (no admin role); tenant-scoped (R30)
 * via TenantContext + the per-user row.
 */
final class DigestPreferenceController extends Controller
{
    public function __construct(private readonly TenantContext $tenants)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $pref = $this->resolve($request);

        return response()->json([
            'frequency' => $pref?->frequency ?? DigestPreference::FREQ_WEEKLY,
            'sections' => $pref?->sections ?? DigestPreference::SECTIONS,
            // R18 — the option domains are derived from the model, not hard-coded in the FE.
            'available_frequencies' => DigestPreference::frequencies(),
            'available_sections' => DigestPreference::SECTIONS,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'frequency' => ['required', 'string', 'in:'.implode(',', DigestPreference::frequencies())],
            'sections' => ['nullable', 'array'],
            'sections.*' => ['string', 'in:'.implode(',', DigestPreference::SECTIONS)],
        ]);

        $userId = (int) $request->user()->getKey();
        $tenantId = $this->tenants->current();

        // Distinguish "not configured" (null = all sections) from "explicitly
        // none" ([]): omitted/null → null (all); an array → canonical-ordered
        // subset, and an empty array is preserved as "none" so unchecking every
        // box in the UI honestly means "no sections" (not silently "all").
        if (! array_key_exists('sections', $validated) || $validated['sections'] === null) {
            $sections = null;
        } else {
            $sections = array_values(array_filter(
                DigestPreference::SECTIONS,
                static fn (string $s): bool => in_array($s, $validated['sections'], true),
            ));
        }

        $pref = DigestPreference::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'user_id' => $userId],
            ['frequency' => $validated['frequency'], 'sections' => $sections],
        );

        return response()->json([
            'frequency' => $pref->frequency,
            'sections' => $pref->sections ?? DigestPreference::SECTIONS,
            'available_frequencies' => DigestPreference::frequencies(),
            'available_sections' => DigestPreference::SECTIONS,
        ]);
    }

    private function resolve(Request $request): ?DigestPreference
    {
        return DigestPreference::query()
            ->where('tenant_id', $this->tenants->current())
            ->where('user_id', (int) $request->user()->getKey())
            ->first();
    }
}
