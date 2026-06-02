<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\KnowledgeDocument;
use App\Services\Kb\Versioning\DocumentVersionService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * v8.7/W5 — Cloud Time Machine endpoints.
 *
 * Read a document's version timeline, diff two versions, and restore an
 * archived version to live. Auth: `auth:sanctum` + `role:admin|super-admin`
 * (route group). R30 — every lookup is tenant-scoped.
 */
final class KbDocumentVersionController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly DocumentVersionService $versions,
    ) {}

    /**
     * GET /api/admin/kb/documents/{id}/versions
     */
    public function index(int $id): JsonResponse
    {
        $document = $this->findOr404($id);

        $rows = $this->versions->versionsFor($document)->map(fn (KnowledgeDocument $v): array => [
            'id' => $v->id,
            'title' => $v->title,
            'version_hash' => $v->version_hash,
            'status' => $v->status,
            'is_canonical' => (bool) $v->is_canonical,
            'canonical_type' => $v->canonical_type,
            'is_live' => $v->status === 'active',
            'indexed_at' => $v->indexed_at,
            'created_at' => $v->created_at,
        ])->all();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'project_key' => $document->project_key,
                'source_path' => $document->source_path,
                'total' => count($rows),
            ],
        ]);
    }

    /**
     * GET /api/admin/kb/documents/{id}/versions/diff?from=A&to=B
     *
     * `A` and `B` must both belong to `{id}`'s version family.
     */
    public function diff(Request $request, int $id): JsonResponse
    {
        $anchor = $this->findOr404($id);
        $validated = $request->validate([
            'from' => ['required', 'integer'],
            'to' => ['required', 'integer'],
        ]);

        $from = $this->resolveFamilyMember($anchor, (int) $validated['from']);
        $to = $this->resolveFamilyMember($anchor, (int) $validated['to']);

        return response()->json(['data' => $this->versions->diff($from, $to)]);
    }

    /**
     * POST /api/admin/kb/documents/{id}/restore
     *
     * Restores version `{id}` to live (archives the current live version,
     * transfers its canonical identity when canonical). Refuses if `{id}`
     * is already the live version (R14 — 422, not a silent no-op).
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        $version = $this->findOr404($id);

        if ($version->status === 'active') {
            return response()->json([
                'message' => 'This version is already live.',
            ], 422);
        }

        $actor = $request->user()?->id !== null ? 'user:'.$request->user()->id : null;
        $restored = $this->versions->restore($version, $actor);

        return response()->json([
            'data' => [
                'id' => $restored->id,
                'status' => $restored->status,
                'is_canonical' => (bool) $restored->is_canonical,
                'slug' => $restored->slug,
            ],
        ]);
    }

    private function findOr404(int $id): KnowledgeDocument
    {
        $document = KnowledgeDocument::query()->forTenant($this->tenant->current())->find($id);
        if ($document === null) {
            throw new NotFoundHttpException('Document not found.');
        }

        return $document;
    }

    /**
     * Resolve a version that MUST belong to the anchor's
     * `(project_key, source_path)` family — prevents diffing/restoring
     * across unrelated documents.
     */
    private function resolveFamilyMember(KnowledgeDocument $anchor, int $versionId): KnowledgeDocument
    {
        $version = KnowledgeDocument::query()
            ->forTenant($this->tenant->current())
            ->where('project_key', $anchor->project_key)
            ->where('source_path', $anchor->source_path)
            ->find($versionId);

        if ($version === null) {
            throw new NotFoundHttpException('Version not found in this document family.');
        }

        return $version;
    }
}
