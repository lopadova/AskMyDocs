<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use Illuminate\Routing\Controller;
use App\Jobs\AnalyzeExpansionLearningJob;
use App\Services\Kb\Retrieval\Expansion\Learning\ExpansionLearningDisabled;
use App\Services\Kb\Retrieval\Expansion\Learning\ExpansionLearningService;
use App\Services\Kb\Retrieval\Expansion\Learning\OpenAiFineTuningBackend;
use App\Support\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

/**
 * W7 — HTTP surface of the expansion learning loop (R44), mounted in the
 * `role:admin|super-admin` admin group (R32 matrix row
 * `/api/admin/kb/expansion-learning`). Thin: validate → service → JSON.
 *
 *  - Flag OFF (R43) → every endpoint 404 `expansion_learning_disabled`.
 *  - `analyze` is QUEUED (202): it runs the benchmark gate, never inline.
 *  - `train` and `promote` change what leaves the deployment / what the live
 *    expansion uses: super-admin only AND an explicit `confirm: true`.
 *  - Domain refusals are 422 with the service's reason; a foreign or missing
 *    id is 404 (tenant-scoped lookup, R30 — never "exists elsewhere").
 */
final class KbExpansionLearningController extends Controller
{
    public function __construct(
        private readonly ExpansionLearningService $expansionLearningService,
        private readonly TenantContext $tenantContext,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $validated = $request->validate(['project_key' => ['nullable', 'string', 'max:120']]);

        return $this->guard(fn (): array => $this->expansionLearningService->status(
            $this->tenantContext->current(),
            ($validated['project_key'] ?? '') !== '' ? (string) $validated['project_key'] : null,
        ), requireEnabled: true);
    }

    public function analyze(Request $request): JsonResponse
    {
        $validated = $request->validate(['project_key' => ['required', 'string', 'max:120']]);
        if (! $this->expansionLearningService->enabled()) {
            return $this->disabled();
        }
        AnalyzeExpansionLearningJob::dispatch($this->tenantContext->current(), (string) $validated['project_key'], $this->actor($request));

        return response()->json(['queued' => true, 'project_key' => $validated['project_key']], 202);
    }

    public function apply(Request $request, int $id): JsonResponse
    {
        return $this->guard(fn (): array => $this->expansionLearningService->apply($this->tenantContext->current(), $id, $this->actor($request)));
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        return $this->guard(fn (): array => $this->expansionLearningService->reject($this->tenantContext->current(), $id, $this->actor($request)));
    }

    public function feedback(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['value' => ['required', 'integer', 'in:-1,0,1']]);

        return $this->guard(fn (): array => $this->expansionLearningService->feedback($this->tenantContext->current(), $id, (int) $validated['value'], $request->user()?->id));
    }

    public function train(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_key' => ['nullable', 'string', 'max:120'],
            'backend' => ['required', 'string', 'in:export,openai'],
            'base_model' => ['nullable', 'string', 'in:'.implode(',', OpenAiFineTuningBackend::BASE_MODELS)],
            'confirm' => ['required', 'accepted'],
        ]);
        if (! $this->isSuperAdmin($request)) {
            return response()->json(['code' => 'super_admin_required', 'message' => 'Only a super-admin can start a training run.'], 403);
        }

        return $this->guard(fn (): array => $this->expansionLearningService->train(
            $this->tenantContext->current(),
            ($validated['project_key'] ?? '') !== '' ? (string) $validated['project_key'] : null,
            (string) $validated['backend'],
            isset($validated['base_model']) ? (string) $validated['base_model'] : null,
            $this->actor($request),
        ), status: 201);
    }

    public function refresh(int $id): JsonResponse
    {
        return $this->guard(fn (): array => $this->expansionLearningService->refresh($this->tenantContext->current(), $id));
    }

    public function promote(Request $request, int $id): JsonResponse
    {
        $request->validate(['confirm' => ['required', 'accepted']]);
        if (! $this->isSuperAdmin($request)) {
            return response()->json(['code' => 'super_admin_required', 'message' => 'Only a super-admin can promote an expansion model.'], 403);
        }

        return $this->guard(fn (): array => $this->expansionLearningService->promote($this->tenantContext->current(), $id, $this->actor($request)));
    }

    public function retire(Request $request, int $id): JsonResponse
    {
        return $this->guard(fn (): array => $this->expansionLearningService->retire($this->tenantContext->current(), $id, $this->actor($request)));
    }

    /** @param  Closure(): array<string,mixed>  $operation */
    private function guard(Closure $operation, bool $requireEnabled = true, int $status = 200): JsonResponse
    {
        if ($requireEnabled && ! $this->expansionLearningService->enabled()) {
            return $this->disabled();
        }
        try {
            return response()->json($operation(), $status);
        } catch (ExpansionLearningDisabled) {
            return $this->disabled();
        } catch (ModelNotFoundException) {
            return response()->json(['code' => 'not_found', 'message' => 'No such tuning or model run.'], 404);
        } catch (InvalidArgumentException $e) {
            return response()->json(['code' => 'refused', 'message' => $e->getMessage()], 422);
        } catch (RuntimeException) {
            return response()->json(['code' => 'storage_failed', 'message' => 'The training dataset could not be stored.'], 503);
        }
    }

    private function disabled(): JsonResponse
    {
        return response()->json(['code' => 'expansion_learning_disabled', 'message' => 'The expansion learning loop is disabled on this deployment.'], 404);
    }

    private function actor(Request $request): string
    {
        return 'user:'.(string) ($request->user()?->id ?? 'unknown');
    }

    private function isSuperAdmin(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && method_exists($user, 'hasRole') && $user->hasRole('super-admin');
    }
}
