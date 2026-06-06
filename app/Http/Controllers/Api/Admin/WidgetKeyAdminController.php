<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\WidgetKey;
use App\Services\Widget\WidgetThemeService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * M6.2 — Admin CRUD for WidgetKey (tenant-scoped, R30).
 *
 * Actions: index / store / update / destroy / rotate / revoke.
 * Rotate regenerates pk_ + sk_ and returns the new secret ONCE.
 * Revoke sets is_active=false (key stops working but data is preserved).
 */
final class WidgetKeyAdminController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly WidgetThemeService $theme,
    ) {}

    /** List all widget keys for the current tenant. */
    public function index(): JsonResponse
    {
        $tenantId = $this->tenantContext->current();

        $rows = WidgetKey::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (WidgetKey $row): array => $this->serialize($row))->values(),
        ]);
    }

    /** Create a new widget key for the current tenant. */
    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->current();
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'project_key' => ['required', 'string', 'max:120'],
            'allowed_origins' => ['nullable', 'array'],
            'allowed_origins.*' => ['string', 'max:255'],
            'rate_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'skill' => ['nullable', 'string', 'max:100'],
        ] + $this->theme->rules('theme'));

        $plainSecret = 'sk_'.Str::random(40);
        $publicKey = 'pk_'.Str::random(32);

        $row = WidgetKey::query()->create([
            'tenant_id' => $tenantId,
            'project_key' => $validated['project_key'],
            'public_key' => $publicKey,
            'secret_hash' => bcrypt($plainSecret),
            'label' => $validated['label'],
            'allowed_origins' => $validated['allowed_origins'] ?? [],
            'rate_limit' => $validated['rate_limit'] ?? 60,
            'skill' => $validated['skill'] ?? 'askmydocs-assistant@1',
            'is_active' => true,
            // Tema esplicito solo se fornito; altrimenti null → il widget
            // risolve i default (snippet di create resta minimale).
            'theme_config' => array_key_exists('theme', $validated)
                ? $this->theme->sanitize($validated['theme'])
                : null,
        ]);

        // Return the secret ONCE — never again available after this response.
        return response()->json([
            'data' => $this->serialize($row),
            'plain_secret' => $plainSecret,
            'public_key' => $publicKey,
        ], 201);
    }

    /** Update mutable fields on a widget key (label, allowed_origins, rate_limit, skill). */
    public function update(Request $request, int $id): JsonResponse
    {
        $row = $this->findForTenant($id);

        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:120'],
            'allowed_origins' => ['nullable', 'array'],
            'allowed_origins.*' => ['string', 'max:255'],
            'rate_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'skill' => ['nullable', 'string', 'max:100'],
        ] + $this->theme->rules('theme'));

        // Il tema vive sulla colonna `theme_config` (nome diverso dalla chiave
        // FE `theme`): gestito a parte, mai via fill().
        $themeProvided = array_key_exists('theme', $validated);
        unset($validated['theme']);

        $row->fill(array_filter($validated, fn ($v) => $v !== null));
        if ($themeProvided) {
            $row->theme_config = $this->theme->sanitize($request->input('theme', []));
        }
        $row->save();

        return response()->json([
            'data' => $this->serialize($row->fresh()),
        ]);
    }

    /** Hard-delete a widget key (and cascade sessions). Use revoke instead for safety. */
    public function destroy(int $id): JsonResponse
    {
        $row = $this->findForTenant($id);
        $row->delete();

        return response()->json([], 204);
    }

    /**
     * Rotate credentials: generates new pk_ + sk_, returns them once.
     * The old public_key stops working immediately (it's replaced in the row).
     */
    public function rotate(int $id): JsonResponse
    {
        $row = $this->findForTenant($id);

        $plainSecret = 'sk_'.Str::random(40);
        $publicKey = 'pk_'.Str::random(32);

        $row->forceFill([
            'public_key' => $publicKey,
            'secret_hash' => bcrypt($plainSecret),
        ])->save();

        return response()->json([
            'data' => $this->serialize($row->fresh()),
            'plain_secret' => $plainSecret,
            'public_key' => $publicKey,
        ]);
    }

    /** Revoke: sets is_active=false — key stops accepting requests but data is preserved. */
    public function revoke(int $id): JsonResponse
    {
        $row = $this->findForTenant($id);
        if ($row->is_active) {
            $row->forceFill(['is_active' => false])->save();
        }

        return response()->json([
            'data' => $this->serialize($row->fresh()),
        ]);
    }

    /** Find a WidgetKey scoped to the current tenant or 404. */
    private function findForTenant(int $id): WidgetKey
    {
        $tenantId = $this->tenantContext->current();

        $row = WidgetKey::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first();

        if ($row === null) {
            throw new NotFoundHttpException('Widget key not found.');
        }

        return $row;
    }

    /** Serialize a WidgetKey for API responses — never leak secret_hash. */
    private function serialize(WidgetKey $row): array
    {
        return [
            'id' => $row->id,
            'label' => $row->label,
            'public_key' => $row->public_key,
            'project_key' => $row->project_key,
            'allowed_origins' => $row->allowed_origins ?? [],
            'rate_limit' => $row->rate_limit,
            'skill' => $row->skill,
            'is_active' => $row->is_active,
            'last_used_at' => $row->last_used_at?->toIso8601String(),
            'sessions_count' => $row->sessions()->count(),
            // Tema risolto (stored sui default) così l'editor admin parte sempre
            // da un oggetto completo, anche per le key senza tema esplicito.
            'theme' => $this->theme->resolve($row->theme_config),
            'created_at' => $row->created_at->toIso8601String(),
            'updated_at' => $row->updated_at->toIso8601String(),
        ];
    }
}