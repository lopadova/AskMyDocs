<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\KbTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * T2.10 — Admin RESTful CRUD on `kb_tags`.
 *
 * Tags are scoped per project: a slug is unique within a single
 * project_key but the SAME slug can legitimately exist on other
 * projects (per-tenant taxonomy isolation). Listing accepts an
 * optional `project_keys[]` filter — admins typically narrow to the
 * project they're managing.
 *
 * The pivot table `knowledge_document_tags` cascades on tag delete
 * (per `2026_04_23_000003_create_knowledge_document_tags_table.php`),
 * so destroying a tag removes its document associations atomically
 * — no orphan rows. The controller returns 204 on delete; cascades
 * are visible to the admin via the document-side audit trail.
 *
 * Auth: `auth:sanctum` + `role:admin|super-admin` (route group at
 * routes/api.php). Each tag is project-scoped, but the controller
 * doesn't enforce per-tenant isolation on admins — admins can curate
 * tags across every project they oversee. Per-user authorization is
 * not relevant here (tags are admin-curated, not user-owned).
 */
final class TagController extends Controller
{
    /**
     * GET /api/admin/kb/tags?project_keys[]=...
     *
     * Returns the full tag list, optionally narrowed to the requested
     * projects. Sorted by project_key + slug for deterministic display.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_keys' => ['nullable', 'array'],
            'project_keys.*' => ['string', 'max:120'],
        ]);

        $projectKeys = $validated['project_keys'] ?? [];

        $query = KbTag::query()
            ->orderBy('project_key')
            ->orderBy('slug');

        if ($projectKeys !== []) {
            $query->whereIn('project_key', $projectKeys);
        }

        return response()->json([
            'data' => $query->get(['id', 'project_key', 'slug', 'label', 'color', 'created_at', 'updated_at']),
        ]);
    }

    /**
     * POST /api/admin/kb/tags
     *
     * Creates a tag scoped to a project. Slug uniqueness is per-project:
     * `(project_key, slug)` must be unique. Color is optional but stored
     * as a 7-char hex string when provided so the FE can render the
     * pill swatch.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_key' => ['required', 'string', 'max:120'],
            'slug' => [
                'required',
                'string',
                'max:120',
                // Slug shape: lowercase alphanumeric + hyphens. Mirrors
                // the canonical-slug rules the BE applies elsewhere.
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('kb_tags', 'slug')
                    ->where(fn ($q) => $q->where('project_key', $request->input('project_key'))),
            ],
            'label' => ['required', 'string', 'max:120'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $tag = KbTag::create($validated);

        return response()->json([
            'data' => $tag->only(['id', 'project_key', 'slug', 'label', 'color', 'created_at', 'updated_at']),
        ], 201);
    }

    /**
     * GET /api/admin/kb/tags/{id}
     */
    public function show(int $id): JsonResponse
    {
        $tag = $this->findOr404($id);

        return response()->json([
            'data' => $tag->only(['id', 'project_key', 'slug', 'label', 'color', 'created_at', 'updated_at']),
        ]);
    }

    /**
     * PUT /api/admin/kb/tags/{id}
     *
     * Updates label + color (and optionally slug, with re-validation
     * of per-project uniqueness). project_key cannot be changed —
     * moving a tag between projects would leave document-tag pivots
     * dangling (the pivot indexes by tag_id, not by project_key,
     * so swapping the parent project would orphan the associations).
     * Returning 422 on project_key change is more honest than silently
     * dropping the field; the admin should delete + recreate.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $tag = $this->findOr404($id);

        $validated = $request->validate([
            'slug' => [
                'sometimes',
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('kb_tags', 'slug')
                    ->where(fn ($q) => $q->where('project_key', $tag->project_key))
                    ->ignore($tag->id),
            ],
            'label' => ['sometimes', 'string', 'max:120'],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        // Reject project_key in the payload — see docblock for rationale.
        if ($request->has('project_key') && $request->input('project_key') !== $tag->project_key) {
            throw new \Illuminate\Validation\ValidationException(
                validator(
                    ['project_key' => $request->input('project_key')],
                    ['project_key' => 'in:' . $tag->project_key],
                    ['project_key.in' => 'Cannot move a tag between projects. Delete and recreate instead.'],
                )
            );
        }

        $tag->update($validated);

        return response()->json([
            'data' => $tag->only(['id', 'project_key', 'slug', 'label', 'color', 'created_at', 'updated_at']),
        ]);
    }

    /**
     * DELETE /api/admin/kb/tags/{id}
     *
     * Deletes the tag. The pivot table `knowledge_document_tags`
     * cascades via FK ON DELETE CASCADE, so document associations
     * disappear atomically.
     */
    public function destroy(int $id): JsonResponse
    {
        $tag = $this->findOr404($id);
        $tag->delete();

        return response()->json(null, 204);
    }

    /**
     * Lookup helper. Returns a `404` rather than letting Eloquent's
     * implicit binding handle it — explicit so the controller's
     * surface stays consistent across actions.
     */
    private function findOr404(int $id): KbTag
    {
        $tag = KbTag::query()->find($id);
        if ($tag === null) {
            throw new NotFoundHttpException('Tag not found.');
        }
        return $tag;
    }
}
