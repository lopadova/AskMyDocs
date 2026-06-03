<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\KbSearchFailure;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * v8.8/W4 — admin "Content Gaps": the questions the KB could NOT answer.
 *
 * Ranks the `kb_search_failures` rollup so editors can see what to write next
 * and hand a gap to the promotion-suggest flow. `resolve` dismisses a gap once
 * an article covers it. Read/write tenant-scoped (R30).
 *
 * Auth: `auth:sanctum` + `role:admin|super-admin` (route group). R32 — covered
 * by the AdminAuthorizationMatrix (`/api/admin/kb/content-gaps`).
 */
final class KbContentGapController extends Controller
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * GET /api/admin/kb/content-gaps?project_keys[]=&reason=&include_resolved=&per_page=
     *
     * Ranked by occurrences desc (most-asked unanswered question first).
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_keys' => ['nullable', 'array'],
            'project_keys.*' => ['string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:40'],
            'include_resolved' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = KbSearchFailure::query()
            ->forTenant($this->tenant->current())
            ->orderByDesc('occurrences')
            ->orderByDesc('last_seen_at');

        // $request->boolean — a validated 'boolean' field is still the raw
        // string ('false' is truthy in PHP), so cast it properly or
        // include_resolved=false would wrongly include resolved gaps (Copilot).
        if (! $request->boolean('include_resolved')) {
            $query->whereNull('resolved_at');
        }
        if (! empty($validated['project_keys'])) {
            $query->whereIn('project_key', $validated['project_keys']);
        }
        if (! empty($validated['reason'])) {
            $query->where('reason', $validated['reason']);
        }

        $page = $query->paginate((int) ($validated['per_page'] ?? 20));

        $data = collect($page->items())->map(fn (KbSearchFailure $row): array => [
            'id' => $row->id,
            'project_key' => $row->project_key,
            'query_text' => $row->query_text,
            'reason' => $row->reason,
            'occurrences' => $row->occurrences,
            'last_seen_at' => $row->last_seen_at,
            'resolved_at' => $row->resolved_at,
        ])->all();

        // R18 — derive the reason filter options from the real DB domain so the
        // FE dropdown reflects the actual set of recorded reasons, not a
        // hard-coded literal subset that silently omits future reason codes.
        $availableReasons = KbSearchFailure::query()
            ->forTenant($this->tenant->current())
            ->distinct()
            ->orderBy('reason')
            ->pluck('reason')
            ->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'available_reasons' => $availableReasons,
            ],
        ]);
    }

    /**
     * PATCH /api/admin/kb/content-gaps/{id}/resolve — dismiss a gap.
     *
     * IDOR-safe: the row is resolved tenant-scoped, so an operator can only
     * ever dismiss a gap in the active tenant.
     */
    public function resolve(int $id): JsonResponse
    {
        $row = KbSearchFailure::query()
            ->forTenant($this->tenant->current())
            ->find($id);

        if ($row === null) {
            throw new NotFoundHttpException('Content gap not found.');
        }

        $row->forceFill(['resolved_at' => now()])->save();

        return response()->json(['ok' => true, 'id' => $row->id, 'resolved_at' => $row->resolved_at]);
    }
}
