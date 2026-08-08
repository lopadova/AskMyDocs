<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\Project;
use App\Models\User;
use App\Models\WidgetKey;
use App\Services\Admin\ProjectCatalogService;
use App\Services\Widget\Exceptions\WidgetIdentityCredentialConflict;
use App\Services\Widget\Exceptions\WidgetIdentityCredentialDisabled;
use App\Services\Widget\Exceptions\WidgetIdentityCredentialNotFound;
use App\Services\Widget\Exceptions\WidgetIdentityCredentialUnauthorized;
use App\Services\Widget\WidgetIdentityCredentialService;
use App\Services\Widget\WidgetIntroService;
use App\Services\Widget\WidgetThemeService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
        private readonly WidgetIntroService $intro,
        private readonly WidgetIdentityCredentialService $identityCredentials,
        private readonly ProjectCatalogService $projects,
    ) {}

    /** List all widget keys for the current tenant. */
    public function index(): JsonResponse
    {
        // R30 — scope via lo scope tenant-aware (BelongsToTenant), non un raw
        // where('tenant_id'). #3 (perf) — withCount('sessions') batcha il
        // conteggio in una sola subquery JOIN invece di una COUNT per riga (N+1).
        $rows = WidgetKey::query()
            ->forTenant($this->tenantContext->current())
            ->withCount('sessions')
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
            // #18 — (tenant_id, project_key, label) è UNIQUE: una label duplicata
            // dava 500 (violazione FK) invece di 422. La validazione la blocca prima.
            'label' => [
                'required', 'string', 'max:120',
                Rule::unique('widget_keys', 'label')
                    ->where('tenant_id', $tenantId)
                    ->where('project_key', (string) $request->input('project_key')),
            ],
            'project_key' => [
                'required',
                'string',
                'max:120',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! $this->projects->contains($value)) {
                        $fail('The selected project is not available for this tenant.');
                    }
                },
            ],
            'allowed_origins' => ['nullable', 'array'],
            'allowed_origins.*' => ['string', 'max:255'],
            'rate_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            // #15 — lo skill deve avere il formato `<id>@<versione>` del registry
            // (es. askmydocs-assistant@1): uno skill malformato (es. "my-skill")
            // farebbe risolvere zero tool → degrado/errore a runtime.
            'skill' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9-]*@[0-9]+$/'],
            'host_tools_enabled' => ['nullable', 'boolean'],
            'user_auth_enabled' => ['nullable', 'boolean'],
        ] + $this->theme->rules('theme') + $this->intro->rules('intro'));

        $introConfig = array_key_exists('intro', $validated)
            ? $this->intro->sanitize(is_array($validated['intro']) ? $validated['intro'] : [])
            : null;
        if ($introConfig !== null) {
            $this->intro->assertUsable($introConfig);
        }

        $plainSecret = 'sk_'.Str::random(40);
        $publicKey = 'pk_'.Str::random(32);

        [$row, $identitySecret] = DB::transaction(function () use (
            $tenantId,
            $validated,
            $publicKey,
            $plainSecret,
            $request,
            $introConfig,
        ): array {
            // Serialize widget creation with registry deletion. A first-class
            // project has a lockable row shared with ProjectController::destroy;
            // legacy catalogue entries intentionally have no registry row, so
            // they remain valid through the historical document/membership/key
            // sources. Re-checking the catalogue inside the transaction closes
            // the window between request validation and the insert.
            Project::query()
                ->forTenant($tenantId)
                ->where('project_key', $validated['project_key'])
                ->lockForUpdate()
                ->first(['id']);

            if (! $this->projects->contains($validated['project_key'])) {
                throw ValidationException::withMessages([
                    'project_key' => ['The selected project is not available for this tenant.'],
                ]);
            }

            // Identity auth starts disabled: the shared service below is the
            // only writer for user_auth_enabled, its hash and its version.
            $row = WidgetKey::query()->create([
                'tenant_id' => $tenantId,
                'project_key' => $validated['project_key'],
                'public_key' => $publicKey,
                // #4 — Hash::make() respects config('hashing.driver').
                'secret_hash' => Hash::make($plainSecret),
                'label' => $validated['label'],
                'allowed_origins' => $validated['allowed_origins'] ?? [],
                'rate_limit' => $validated['rate_limit'] ?? 60,
                'skill' => $validated['skill'] ?? 'askmydocs-assistant@1',
                'host_tools_enabled' => $validated['host_tools_enabled'] ?? false,
                'is_active' => true,
                // Tema esplicito solo se fornito; altrimenti null → il widget
                // risolve i default (snippet di create resta minimale).
                'theme_config' => array_key_exists('theme', $validated)
                    ? $this->theme->sanitize($validated['theme'])
                    : null,
                'intro_config' => $introConfig,
            ]);

            if (! ($validated['user_auth_enabled'] ?? false)) {
                return [$row, null];
            }

            $result = $this->identityCredentials->enable(
                keyId: (int) $row->id,
                tenantId: $tenantId,
                expectedVersion: 0,
                actor: $this->actor($request),
                surface: WidgetIdentityCredentialService::SURFACE_HTTP,
            );

            return [$result->key, $result->plainSecret];
        });

        // Return the secret ONCE — never again available after this response.
        return response()->json([
            'data' => $this->serialize($row),
            'plain_secret' => $plainSecret,
            'public_key' => $publicKey,
            'identity_plain_secret' => $identitySecret,
        ], 201);
    }

    /** Update mutable fields on a widget key (label, allowed_origins, rate_limit, skill). */
    public function update(Request $request, int $id): JsonResponse
    {
        $row = $this->findForTenant($id);
        $tenantId = $this->tenantContext->current();

        $validated = $request->validate([
            // #18 — label unica per (tenant, project) anche in update, ignorando se
            // stessa. NB: `project_key` NON è aggiornabile (non è tra i campi sotto),
            // quindi scopiamo sull'attuale $row->project_key. Se in futuro si
            // rendesse `project_key` modificabile, va aggiornato anche questo scope.
            'label' => [
                'nullable', 'string', 'max:120',
                Rule::unique('widget_keys', 'label')
                    ->where('tenant_id', $tenantId)
                    ->where('project_key', $row->project_key)
                    ->ignore($row->id),
            ],
            'allowed_origins' => ['nullable', 'array'],
            'allowed_origins.*' => ['string', 'max:255'],
            'rate_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            // #15 — formato skill del registry.
            'skill' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9-]*@[0-9]+$/'],
            'host_tools_enabled' => ['nullable', 'boolean'],
            'user_auth_enabled' => ['nullable', 'boolean'],
            'identity_credential_version' => [
                'required_with:user_auth_enabled',
                'integer',
                'min:0',
            ],
        ] + $this->theme->rules('theme') + $this->intro->rules('intro'));

        // Il tema vive sulla colonna `theme_config` (nome diverso dalla chiave
        // FE `theme`): gestito a parte, mai via fill().
        $themeProvided = array_key_exists('theme', $validated);
        $themePatch = $themeProvided && is_array($validated['theme']) ? $validated['theme'] : [];
        unset($validated['theme']);
        $introProvided = array_key_exists('intro', $validated);
        $introPatch = $introProvided && is_array($validated['intro']) ? $validated['intro'] : [];
        unset($validated['intro']);
        $userAuthProvided = array_key_exists('user_auth_enabled', $validated);
        $userAuthEnabled = (bool) ($validated['user_auth_enabled'] ?? false);
        $identityVersion = (int) ($validated['identity_credential_version'] ?? 0);
        unset($validated['user_auth_enabled'], $validated['identity_credential_version']);

        try {
            [$row, $identitySecret] = DB::transaction(function () use (
                $row,
                $validated,
                $themeProvided,
                $themePatch,
                $introProvided,
                $introPatch,
                $userAuthProvided,
                $userAuthEnabled,
                $identityVersion,
                $tenantId,
            ): array {
                $row->fill(array_filter($validated, fn ($value) => $value !== null));
                if ($themeProvided) {
                    // PATCH semantico: i campi omessi conservano il valore
                    // attualmente risolto, invece di tornare ai default.
                    $row->theme_config = $this->theme->sanitize(array_replace(
                        $this->theme->resolve($row->theme_config),
                        $themePatch,
                    ));
                }
                if ($introProvided) {
                    $resolvedIntro = $this->intro->patch($row->intro_config, $introPatch);
                    $this->intro->assertUsable($resolvedIntro);
                    $row->intro_config = $resolvedIntro;
                }
                $row->save();

                if (! $userAuthProvided) {
                    return [$row->fresh(), null];
                }

                $result = $userAuthEnabled
                    ? $this->identityCredentials->enable(
                        keyId: (int) $row->id,
                        tenantId: $tenantId,
                        expectedVersion: $identityVersion,
                        actor: $this->actor($request),
                        surface: WidgetIdentityCredentialService::SURFACE_HTTP,
                    )
                    : $this->identityCredentials->disable(
                        keyId: (int) $row->id,
                        tenantId: $tenantId,
                        expectedVersion: $identityVersion,
                        actor: $this->actor($request),
                        surface: WidgetIdentityCredentialService::SURFACE_HTTP,
                    );

                return [$result->key, $result->plainSecret];
            });
        } catch (WidgetIdentityCredentialNotFound $e) {
            return $this->identityCredentialError('widget_key_not_found', $e, 404);
        } catch (WidgetIdentityCredentialConflict $e) {
            return response()->json([
                'error' => 'identity_credential_conflict',
                'message' => $e->getMessage(),
                'expected_version' => $e->expectedVersion,
                'current_version' => $e->actualVersion,
            ], 409);
        } catch (WidgetIdentityCredentialUnauthorized $e) {
            return $this->identityCredentialError('forbidden', $e, 403);
        }

        return response()->json([
            'data' => $this->serialize($row),
            'identity_plain_secret' => $identitySecret,
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
            // #4 — Hash::make() (vedi store): rispetta config('hashing.driver').
            'secret_hash' => Hash::make($plainSecret),
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

    /** Rotate the server-to-server identity credential; plaintext is returned once. */
    public function rotateIdentitySecret(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'identity_credential_version' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $result = $this->identityCredentials->rotate(
                keyId: $id,
                tenantId: $this->tenantContext->current(),
                expectedVersion: (int) $validated['identity_credential_version'],
                actor: $this->actor($request),
                surface: WidgetIdentityCredentialService::SURFACE_HTTP,
            );
        } catch (WidgetIdentityCredentialNotFound $e) {
            return $this->identityCredentialError('widget_key_not_found', $e, 404);
        } catch (WidgetIdentityCredentialDisabled $e) {
            return $this->identityCredentialError('user_auth_disabled', $e, 409);
        } catch (WidgetIdentityCredentialConflict $e) {
            return response()->json([
                'error' => 'identity_credential_conflict',
                'message' => $e->getMessage(),
                'expected_version' => $e->expectedVersion,
                'current_version' => $e->actualVersion,
            ], 409);
        } catch (WidgetIdentityCredentialUnauthorized $e) {
            return $this->identityCredentialError('forbidden', $e, 403);
        }

        return response()->json([
            'data' => $this->serialize($result->key),
            'identity_plain_secret' => $result->plainSecret,
        ]);
    }

    /** Find a WidgetKey scoped to the current tenant or 404. */
    private function findForTenant(int $id): WidgetKey
    {
        // R30 — forTenant() (BelongsToTenant) invece di raw where('tenant_id').
        $row = WidgetKey::query()
            ->forTenant($this->tenantContext->current())
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
            'host_tools_enabled' => $row->host_tools_enabled,
            'user_auth_enabled' => $row->user_auth_enabled,
            'identity_credential_version' => $row->identity_credential_version,
            'is_active' => $row->is_active,
            'last_used_at' => $row->last_used_at?->toIso8601String(),
            // #3 — usa l'attributo withCount quando presente (index()); fallback
            // alla COUNT diretta per i percorsi single-row (store/update/rotate).
            'sessions_count' => $row->sessions_count ?? $row->sessions()->count(),
            // Tema risolto (stored sui default) così l'editor admin parte sempre
            // da un oggetto completo, anche per le key senza tema esplicito.
            'theme' => $this->theme->resolve($row->theme_config),
            'intro' => $this->intro->resolve($row->intro_config),
            'created_at' => $row->created_at->toIso8601String(),
            'updated_at' => $row->updated_at->toIso8601String(),
        ];
    }

    private function actor(Request $request): ?User
    {
        $actor = $request->user();

        return $actor instanceof User ? $actor : null;
    }

    private function identityCredentialError(
        string $error,
        \RuntimeException $exception,
        int $status,
    ): JsonResponse {
        return response()->json([
            'error' => $error,
            'message' => $exception->getMessage(),
        ], $status);
    }
}
