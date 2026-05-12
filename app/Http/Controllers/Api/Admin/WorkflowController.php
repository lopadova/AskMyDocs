<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\Workflow\FromProposalRequest;
use App\Http\Requests\Admin\Workflow\ShareWorkflowRequest;
use App\Http\Requests\Admin\Workflow\StoreWorkflowRequest;
use App\Http\Requests\Admin\Workflow\SuggestWorkflowsRequest;
use App\Http\Requests\Admin\Workflow\UpdateWorkflowRequest;
use App\Http\Resources\Admin\WorkflowResource;
use App\Http\Resources\Admin\WorkflowShareResource;
use App\Models\Workflow;
use App\Models\WorkflowShare;
use App\Services\Workflow\WorkflowService;
use App\Services\Workflow\WorkflowSuggester;
use App\Support\TenantContext;
use App\Support\Workflow\WorkflowType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * v4.7/W2 — Admin RESTful CRUD on workflows + share + hide + suggest.
 *
 * Auth: `auth:sanctum`. Gates on the route layer:
 *   - viewWorkflows      → index + show + suggest (viewer is admitted
 *     for reads, but the controller fences mutation + suggest)
 *   - createWorkflows    → store + update + destroy + share + from-proposal
 *
 * Tenant scoping is enforced inside {@see WorkflowService} and the
 * `findOr404()` helper here.
 */
final class WorkflowController extends Controller
{
    public function __construct(
        private readonly WorkflowService $service,
        private readonly WorkflowSuggester $suggester,
        private readonly TenantContext $ctx,
    ) {}

    /**
     * GET /api/admin/workflows
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', 'string', Rule::in(WorkflowType::values())],
            'include_shared' => ['nullable', 'boolean'],
            'include_hidden' => ['nullable', 'boolean'],
        ]);

        $type = $validated['type'] ?? null;
        $includeShared = $this->boolFlag($validated, 'include_shared', true);
        $includeHidden = $this->boolFlag($validated, 'include_hidden', false);

        $workflows = $this->service->list(
            $request->user(),
            $type,
            $includeShared,
            $includeHidden,
        );

        return response()->json([
            'data' => WorkflowResource::collection($workflows),
            'meta' => [
                'total' => $workflows->count(),
            ],
        ]);
    }

    /**
     * POST /api/admin/workflows
     */
    public function store(StoreWorkflowRequest $request): JsonResponse
    {
        $this->assertCanCreate($request);

        $validated = $request->validated();
        $attributes = [
            'title' => $validated['title'],
            'type' => $validated['type'],
            'prompt_md' => $validated['prompt_md'],
            'practice' => $validated['practice'] ?? 'generic',
            'columns_config' => $validated['columns_config'] ?? null,
        ];

        $workflow = $this->service->create($request->user(), $attributes);

        return response()->json(['data' => (new WorkflowResource($workflow))->resolve()], 201);
    }

    /**
     * GET /api/admin/workflows/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $workflow = $this->findOr404($id, $request->user());
        $workflow->load('shares');

        return response()->json(['data' => (new WorkflowResource($workflow))->resolve()]);
    }

    /**
     * PATCH /api/admin/workflows/{id}
     */
    public function update(UpdateWorkflowRequest $request, int $id): JsonResponse
    {
        $this->assertCanCreate($request);

        $workflow = $this->findOr404($id, $request->user());
        $updated = $this->service->update($workflow, $request->user(), $request->validated());

        return response()->json(['data' => (new WorkflowResource($updated))->resolve()]);
    }

    /**
     * DELETE /api/admin/workflows/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->assertCanCreate($request);

        $workflow = $this->findOr404($id, $request->user());
        $this->service->delete($workflow, $request->user());

        return response()->json(null, 204);
    }

    /**
     * POST /api/admin/workflows/{id}/share
     */
    public function share(ShareWorkflowRequest $request, int $id): JsonResponse
    {
        $this->assertCanCreate($request);

        $workflow = $this->findOr404($id, $request->user());

        $validated = $request->validated();
        $share = $this->service->share(
            $workflow,
            $request->user(),
            (string) $validated['email'],
            (bool) ($validated['allow_edit'] ?? false),
        );

        // Copilot iter 1: route through WorkflowShareResource so the
        // payload shape (and ISO-8601 timestamps) matches every other
        // workflow endpoint — returning the raw Eloquent model could
        // leak unintended fields if the Model later grows columns.
        return response()->json([
            'data' => (new WorkflowShareResource($share))->resolve(),
        ], 201);
    }

    /**
     * DELETE /api/admin/workflows/{id}/share
     */
    public function unshare(ShareWorkflowRequest $request, int $id): JsonResponse
    {
        $this->assertCanCreate($request);

        $workflow = $this->findOr404($id, $request->user());
        $deleted = $this->service->unshare(
            $workflow,
            $request->user(),
            (string) $request->validated()['email'],
        );

        return response()->json([
            'data' => [
                'workflow_id' => $workflow->id,
                'unshared' => $deleted,
            ],
        ]);
    }

    /**
     * POST /api/admin/workflows/{id}/hide
     *
     * Intentionally NOT gated by `assertCanCreate()` — the only state
     * a hide mutates is the caller's OWN row in `hidden_workflows`,
     * scoped by `WorkflowService::hide()` to `(tenant_id, user_id,
     * workflow_id)`. This is a cosmetic personal preference, not a
     * shared template mutation, so viewers are admitted on purpose.
     * Copilot iter 1 surfaced the ambiguity in the route docstring;
     * the route comment is now explicit about this contract.
     */
    public function hide(Request $request, int $id): JsonResponse
    {
        $workflow = $this->findOr404($id, $request->user());
        $row = $this->service->hide($workflow, $request->user());

        return response()->json(['data' => $row], 201);
    }

    /**
     * DELETE /api/admin/workflows/{id}/hide
     */
    public function unhide(Request $request, int $id): JsonResponse
    {
        $workflow = $this->findOr404($id, $request->user());
        $removed = $this->service->unhide($workflow, $request->user());

        return response()->json([
            'data' => [
                'workflow_id' => $workflow->id,
                'unhidden' => $removed,
            ],
        ]);
    }

    /**
     * POST /api/admin/workflows/suggest
     */
    public function suggest(SuggestWorkflowsRequest $request): JsonResponse
    {
        $this->assertCanSuggest($request);

        $validated = $request->validated();
        $payload = $this->suggester->suggest(
            $request->user(),
            (int) ($validated['limit'] ?? 5),
            (bool) ($validated['force_refresh'] ?? false),
        );

        return response()->json([
            'data' => $payload['proposals'],
            'meta' => $payload['meta'],
        ]);
    }

    /**
     * POST /api/admin/workflows/from-proposal
     */
    public function fromProposal(FromProposalRequest $request): JsonResponse
    {
        $this->assertCanCreate($request);

        $proposal = $request->validated()['proposal'];

        $workflow = $this->service->create($request->user(), [
            'title' => $proposal['title'],
            'type' => $proposal['type'],
            'prompt_md' => $proposal['prompt_md'],
            'practice' => $proposal['practice'] ?? 'generic',
            'columns_config' => $proposal['columns_config'] ?? null,
        ]);

        return response()->json(['data' => (new WorkflowResource($workflow))->resolve()], 201);
    }

    /**
     * @param array<string, mixed> $validated
     */
    private function boolFlag(array $validated, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $validated) || $validated[$key] === null) {
            return $default;
        }
        return filter_var($validated[$key], FILTER_VALIDATE_BOOLEAN);
    }

    private function findOr404(int $id, $user): Workflow
    {
        $workflow = Workflow::query()
            ->forTenant($this->ctx->current())
            ->where('id', $id)
            ->first();

        if ($workflow === null) {
            throw new NotFoundHttpException('Workflow not found.');
        }

        // System workflows are visible to every user.
        if ($workflow->is_system) {
            return $workflow;
        }

        if ((int) $workflow->user_id === (int) $user->id) {
            return $workflow;
        }

        if (method_exists($user, 'hasRole') && $user->hasRole('super-admin')) {
            return $workflow;
        }

        $email = mb_strtolower((string) ($user?->email ?? ''));
        if ($email !== '') {
            $sharedExists = WorkflowShare::query()
                ->where('workflow_id', $workflow->id)
                ->where('shared_with_email', $email)
                ->exists();
            if ($sharedExists) {
                return $workflow;
            }
        }

        throw new NotFoundHttpException('Workflow not found.');
    }

    /**
     * Fail-closed authorisation helper for write actions. Copilot iter 1
     * flagged the previous fail-open shape: returning early when `$user`
     * was null or did not implement the Spatie role API would silently
     * authorise writes if the auth stack was reconfigured. The route
     * group already enforces `auth:sanctum`, so a null user here is
     * structurally impossible — but throwing keeps the surface
     * fail-closed against future regressions.
     */
    private function assertCanCreate(Request $request): void
    {
        $user = $request->user();
        if ($user === null || ! method_exists($user, 'hasAnyRole')) {
            throw new AccessDeniedHttpException('Authentication required.');
        }
        if (! $user->hasAnyRole(['admin', 'super-admin'])) {
            throw new AccessDeniedHttpException('Only admin / super-admin can mutate workflows.');
        }
    }

    private function assertCanSuggest(Request $request): void
    {
        $user = $request->user();
        if ($user === null || ! method_exists($user, 'hasAnyRole')) {
            throw new AccessDeniedHttpException('Authentication required.');
        }
        if (! $user->hasAnyRole(['admin', 'super-admin'])) {
            throw new AccessDeniedHttpException('Only admin / super-admin can request workflow suggestions.');
        }
    }
}
