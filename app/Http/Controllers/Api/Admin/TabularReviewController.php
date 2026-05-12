<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\TabularReview\GenerateCellRequest;
use App\Http\Requests\Admin\TabularReview\StoreTabularReviewRequest;
use App\Http\Requests\Admin\TabularReview\SuggestPromptRequest;
use App\Http\Requests\Admin\TabularReview\UpdateTabularReviewRequest;
use App\Models\KnowledgeDocument;
use App\Models\TabularCell;
use App\Models\TabularReview;
use App\Services\TabularReview\ColumnPromptSuggester;
use App\Services\TabularReview\TabularReviewExtractor;
use App\Support\TabularReview\CellStatus;
use App\Support\TabularReview\FormatType;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * v4.7/W1 — Admin RESTful CRUD on tabular_reviews + extraction triggers.
 *
 * Every action is tenant-scoped via TenantContext (R30). The controller
 * is thin: request → service → JsonResource-equivalent payload.
 *
 * Auth: `auth:sanctum` + `can:viewTabularReviews` Gate at the route
 * layer. The Gate admits super-admin, admin (full RW within tenant),
 * and viewer (read-only). The controller enforces the read-only side
 * of viewer via a `denyMutation()` guard on every write action.
 */
final class TabularReviewController extends Controller
{
    public function __construct(
        private readonly TabularReviewExtractor $extractor,
        private readonly ColumnPromptSuggester $promptSuggester,
        private readonly TenantContext $ctx,
    ) {}

    /**
     * GET /api/admin/tabular-reviews?project_key=...&page=N
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_key' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 25);

        $query = TabularReview::query()
            ->forTenant($this->ctx->current())
            ->orderByDesc('id');

        if (! empty($validated['project_key'])) {
            $query->where('project_key', $validated['project_key']);
        }

        $page = $query->paginate($perPage);

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * POST /api/admin/tabular-reviews
     */
    public function store(StoreTabularReviewRequest $request): JsonResponse
    {
        $this->denyMutationForViewer($request);

        $validated = $request->validated();

        $review = TabularReview::create([
            'tenant_id' => $this->ctx->current(),
            'project_key' => $validated['project_key'],
            'user_id' => $request->user()->id,
            'title' => $validated['title'],
            'columns_config' => $validated['columns_config'],
            'workflow_id' => $validated['workflow_id'] ?? null,
            'shared_with' => $validated['shared_with'] ?? [],
            'practice' => $validated['practice'] ?? null,
        ]);

        return response()->json(['data' => $review], 201);
    }

    /**
     * GET /api/admin/tabular-reviews/{id}
     */
    public function show(int $id): JsonResponse
    {
        $review = $this->findOr404($id);

        $cells = TabularCell::query()
            ->forTenant($this->ctx->current())
            ->where('review_id', $review->id)
            ->orderBy('document_id')
            ->orderBy('column_index')
            ->get();

        return response()->json([
            'data' => $review,
            'cells' => $cells,
        ]);
    }

    /**
     * PATCH /api/admin/tabular-reviews/{id}
     */
    public function update(UpdateTabularReviewRequest $request, int $id): JsonResponse
    {
        $this->denyMutationForViewer($request);

        $review = $this->findOr404($id);

        if ($request->has('project_key') && $request->input('project_key') !== $review->project_key) {
            throw new \Illuminate\Validation\ValidationException(
                validator(
                    ['project_key' => $request->input('project_key')],
                    ['project_key' => 'in:'.$review->project_key],
                    ['project_key.in' => 'Cannot move a review between projects. Delete and recreate instead.'],
                )
            );
        }

        $review->fill($request->validated());
        $review->save();

        return response()->json(['data' => $review]);
    }

    /**
     * DELETE /api/admin/tabular-reviews/{id}
     *
     * Cascades to `tabular_cells` via FK ON DELETE CASCADE so the
     * grid disappears atomically.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->denyMutationForViewer($request);

        $review = $this->findOr404($id);
        $review->delete();

        return response()->json(null, 204);
    }

    /**
     * POST /api/admin/tabular-reviews/{id}/generate
     *
     * Synchronously runs the extractor over the review's project
     * documents and returns a JSON summary with `review_id`,
     * `documents_processed`, `cells_total`, and `truncated` (true when
     * the requested batch hit `max_documents`). The streaming SSE
     * transport (per-cell push) ships in W3; the extractor accepts an
     * `$onCell` callback so the streaming hook is already wire-able
     * without a refactor.
     *
     * `max_documents` (default 200, hard ceiling 1000) caps the batch
     * size so a single HTTP request never holds the worker for an
     * unbounded number of LLM calls. When `truncated=true` the caller
     * can re-run with a higher cap or wait for W3 to push the work
     * onto the queue.
     *
     * Returns HTTP 200 because the work is synchronous: by the time the
     * response is written every cell exists in `tabular_cells`.
     */
    public function generate(Request $request, int $id): JsonResponse
    {
        $this->denyMutationForViewer($request);

        $validated = $request->validate([
            'max_documents' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        $cap = (int) ($validated['max_documents'] ?? 200);

        $review = $this->findOr404($id);

        $tenant = $this->ctx->current();
        $baseQuery = KnowledgeDocument::query()
            ->forTenant($tenant)
            ->where('project_key', $review->project_key);

        $totalAvailable = (int) $baseQuery->count();

        // Memory-safe iteration (R3): a project can hold thousands of
        // documents and the previous `$baseQuery->get()` materialised
        // every Eloquent row at once. `chunkById` keeps the loaded
        // window bounded; `$processed` enforces the `max_documents`
        // ceiling across chunks; `$cellsTotal` accumulates a scalar
        // count rather than the full cell list so memory stays flat
        // regardless of the column count.
        $cellsTotal = 0;
        $processed = 0;
        $baseQuery->orderBy('id')->chunkById(50, function ($docs) use ($review, $cap, &$cellsTotal, &$processed): bool {
            foreach ($docs as $doc) {
                if ($processed >= $cap) {
                    return false;
                }
                $cells = $this->extractor->extract($review, $doc);
                $cellsTotal += count($cells);
                $processed++;
            }
            return true;
        });

        return response()->json([
            'data' => [
                'review_id' => $review->id,
                'documents_processed' => $processed,
                'documents_total_available' => $totalAvailable,
                'cells_total' => $cellsTotal,
                'truncated' => $totalAvailable > $processed,
                'max_documents' => $cap,
            ],
        ]);
    }

    /**
     * POST /api/admin/tabular-reviews/{id}/regenerate-cell
     */
    public function regenerateCell(GenerateCellRequest $request, int $id): JsonResponse
    {
        $this->denyMutationForViewer($request);

        $review = $this->findOr404($id);
        $documentId = (int) $request->validated()['document_id'];
        $columnIndex = (int) $request->validated()['column_index'];

        $columns = $review->columns_config ?? [];
        if (! isset($columns[$columnIndex])) {
            throw new \Illuminate\Validation\ValidationException(
                validator(
                    ['column_index' => $columnIndex],
                    ['column_index' => 'in:'.implode(',', array_keys($columns))],
                    ['column_index.in' => 'Column index out of range.'],
                )
            );
        }

        $doc = KnowledgeDocument::query()
            ->forTenant($this->ctx->current())
            ->where('id', $documentId)
            ->where('project_key', $review->project_key)
            ->first();

        if ($doc === null) {
            throw new NotFoundHttpException('Document not found in this review.');
        }

        // Re-extract every column for the doc but only return the
        // requested cell — the multi-column LLM batch is cheaper than
        // per-column calls and keeps the grid consistent.
        $cells = $this->extractor->extract($review, $doc);

        $cell = collect($cells)->first(fn ($c) => $c->column_index === $columnIndex);

        return response()->json(['data' => $cell]);
    }

    /**
     * POST /api/admin/tabular-reviews/{id}/clear-cells
     *
     * Wipes every cell for the review. Useful when the user reshuffles
     * columns and wants a fresh extraction pass.
     */
    public function clearCells(Request $request, int $id): JsonResponse
    {
        $this->denyMutationForViewer($request);

        $review = $this->findOr404($id);

        $count = TabularCell::query()
            ->forTenant($this->ctx->current())
            ->where('review_id', $review->id)
            ->delete();

        return response()->json([
            'data' => [
                'review_id' => $review->id,
                'cells_deleted' => $count,
            ],
        ]);
    }

    /**
     * POST /api/admin/tabular-reviews/prompt
     *
     * Triggers an LLM call to draft a column extraction prompt. Even
     * though the endpoint doesn't mutate `tabular_reviews` itself, it
     * spends provider credit — treat it as a mutation and deny viewers
     * (cost-protection, mirrors the rest of the controller's RW guard).
     */
    public function suggestPrompt(SuggestPromptRequest $request): JsonResponse
    {
        $this->denyMutationForViewer($request);

        $validated = $request->validated();
        $format = FormatType::from($validated['format']);

        $prompt = $this->promptSuggester->suggest($validated['column_name'], $format);

        return response()->json([
            'data' => [
                'prompt' => $prompt,
            ],
        ]);
    }

    /**
     * Lookup + R30 enforcement in one place.
     */
    private function findOr404(int $id): TabularReview
    {
        $review = TabularReview::query()
            ->forTenant($this->ctx->current())
            ->where('id', $id)
            ->first();

        if ($review === null) {
            throw new NotFoundHttpException('Tabular review not found.');
        }

        return $review;
    }

    /**
     * Reject write actions when the caller has only `viewer` role.
     * super-admin / admin are admitted upstream by the Gate.
     */
    private function denyMutationForViewer(Request $request): void
    {
        $user = $request->user();
        if ($user === null) {
            return;
        }
        if (method_exists($user, 'hasRole') && $user->hasRole('viewer')
            && ! $user->hasAnyRole(['admin', 'super-admin'])) {
            throw new AccessDeniedHttpException('Viewers cannot mutate tabular reviews.');
        }
    }
}
