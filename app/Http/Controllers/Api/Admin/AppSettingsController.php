<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\AppSetting;
use App\Services\Admin\AppSettingsResolver;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * v8.22 (Ciclo 3) — runtime configuration governance admin surface.
 *
 * Behind `auth:sanctum` + `tenant.authorize` + `role:super-admin` (config
 * governance changes AI provider / cadence / switches — super-admin only):
 *   GET /api/admin/app-settings[?project_key=]   → effective values + provenance
 *   PUT /api/admin/app-settings                  → set/clear one governable key
 *
 * Delegates to {@see AppSettingsResolver} (R44 one core; R30 tenant-scoped;
 * deploy-only keys rejected with 422).
 */
final class AppSettingsController extends Controller
{
    public function __construct(
        private readonly AppSettingsResolver $resolver,
        private readonly TenantContext $tenants,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // Normalise an absent/empty ?project_key= back to the wildcard so it is
        // never resolved as a literal empty project scope (but keep '0').
        $projectKey = AppSetting::normalizeProjectKey($request->query('project_key'));

        return response()->json([
            'data' => $this->resolver->all($this->tenants->current(), $projectKey),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:120'],
            // Accept an explicit null ("no project selected" from the UI) — it
            // normalises to the tenant-wide wildcard, so it must not 422.
            'project_key' => ['sometimes', 'nullable', 'string', 'max:120'],
            // value is type-validated by the resolver against the registry; a
            // null clears the override (inherit the next level up).
            'value' => ['present', 'nullable'],
        ]);

        // A present-but-empty project_key means "tenant-wide" — normalise to the
        // wildcard rather than persisting an empty scope (but keep '0').
        $projectKey = AppSetting::normalizeProjectKey($validated['project_key'] ?? null);

        // Resolver throws ValidationException (→ 422) on unknown / deploy-only
        // key or an invalid value for its type.
        $this->resolver->set(
            (string) $validated['key'],
            $validated['value'] ?? null,
            $this->tenants->current(),
            $projectKey,
        );

        return response()->json([
            'data' => $this->resolver->all($this->tenants->current(), $projectKey),
        ]);
    }

    public function updateMany(Request $request, \App\Services\Admin\AiModelCatalog $catalog): JsonResponse
    {
        $data = $request->validate(['project_key' => ['nullable', 'string', 'max:120'], 'values' => ['required', 'array', 'min:1', 'max:20']]);
        $scope = AppSetting::normalizeProjectKey($data['project_key'] ?? null);
        $tenant = $this->tenants->current();
        foreach ($data['values'] as $key => $value) {
            if (! is_string($key)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['values' => ['Settings must be keyed by their registered name.']]);
            }
            $this->resolver->validateDraft($key, $value, $scope);
        }
        $provider = array_key_exists('ai.provider', $data['values']) ? $data['values']['ai.provider'] : $this->resolver->effective('ai.provider', $tenant);
        $provider = $provider ?: config('ai.default');
        $models = array_filter(array_intersect_key($data['values'], array_flip(\App\Services\Admin\AppSettingsAssistant::MODEL_KEYS)), fn ($value) => $value !== null && $value !== '');
        if ($models !== []) {
            $snapshot = $catalog->get($provider, $tenant);
            $available = $snapshot['error'] === null ? array_column(array_filter($snapshot['models'], fn ($model) => $model['selectable']), 'id') : [];
            foreach ($models as $key => $model) {
                if (! in_array($model, $available, true)) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['values.'.$key => ['Choose an available model for the selected provider.']]);
                }
            }
        }
        if (($data['values']['ai.chat_routing'] ?? null) === 'economy' && $provider !== 'openrouter') {
            throw \Illuminate\Validation\ValidationException::withMessages(['values.ai.chat_routing' => ['Economy routing is available only with OpenRouter.']]);
        }
        $this->resolver->setMany($data['values'], $tenant, $scope);
        return response()->json(['data' => $this->resolver->all($tenant, $scope)]);
    }

    public function models(Request $request, \App\Services\Admin\AiModelCatalog $catalog): JsonResponse
    {
        $data = $request->validate(['provider' => ['required', \Illuminate\Validation\Rule::in(\App\Services\Admin\AiModelCatalog::PROVIDERS)]]);
        return response()->json($catalog->get($data['provider'], $this->tenants->current(), $request->isMethod('post')))
            ->header('Cache-Control', 'private, no-store');
    }

    public function assist(Request $request, \App\Services\Admin\AppSettingsAssistant $assistant): JsonResponse
    {
        $data = $request->validate(['prompt' => ['required', 'string', 'min:5', 'max:4000'],
            'project_key' => ['nullable', 'string', 'max:120'], 'draft' => ['present', 'array', 'max:20'],
            'provider' => ['required', \Illuminate\Validation\Rule::in(\App\Services\Admin\AiModelCatalog::PROVIDERS)],
            'target' => ['required', \Illuminate\Validation\Rule::in(['configuration', 'agent.tenant_instructions'])]]);
        try {
            return response()->json($assistant->generate($data['prompt'], $this->tenants->current(),
                AppSetting::normalizeProjectKey($data['project_key'] ?? null), $data['provider'], $data['target'], $data['draft']))
                ->header('Cache-Control', 'private, no-store');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            return response()->json(['message' => 'The configuration assistant is unavailable. Your form has not been changed.'], 503);
        }
    }
}
