<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\Workflow\FromProposalRequest;
use App\Http\Requests\Admin\Workflow\ShareWorkflowRequest;
use App\Http\Requests\Admin\Workflow\StoreWorkflowRequest;
use App\Http\Requests\Admin\Workflow\SuggestWorkflowsRequest;
use App\Http\Requests\Admin\Workflow\UpdateWorkflowRequest;
use App\Http\Resources\Admin\WorkflowResource;
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

        return response()->json(['data' => $share], 201);
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

    private function assertCanCreate(Request $request): void
    {
        $user = $request->user();
        if ($user === null) {
            return;
        }
        if (! method_exists($user, 'hasAnyRole')) {
            return;
        }
        if (! $user->hasAnyRole(['admin', 'super-admin'])) {
            throw new AccessDeniedHttpException('Only admin / super-admin can mutate workflows.');
        }
    }

    private function assertCanSuggest(Request $request): void
    {
        $user = $request->user();
        if ($user === null) {
            return;
        }
        if (! method_exists($user, 'hasAnyRole')) {
            return;
        }
        if (! $user->hasAnyRole(['admin', 'super-admin'])) {
            throw new AccessDeniedHttpException('Only admin / super-admin can request workflow suggestions.');
        }
    }
}
