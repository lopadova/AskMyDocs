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
            // v8.36 / ADR 0030 §4 — additive (R27): null / false on rows that
            // predate the artifacts, never a changed key.
            'version_actor' => $v->version_actor,
            'version_reason' => $v->version_reason,
            'content_hash' => $v->content_hash,
            'has_artifact' => is_string($v->markdown_path) && $v->markdown_path !== '',
            // ADR 0030 §6 — the last restore, kept apart from the creation provenance
            'restored_by' => DocumentVersionService::lastRestoreOf($v)['actor'] ?? null,
            'restored_at' => DocumentVersionService::lastRestoreOf($v)['at'] ?? null,
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
     * POST /api/admin/kb/documents/{id}/restore-version
     *
     * Restores version `{id}` to live (archives the current live version,
     * transfers its canonical identity when canonical). Refuses if `{id}`
     * is already the live version (R14 — 422, not a silent no-op).
     *
     * The "already live" guard runs inside DocumentVersionService::restore()
     * under a lockForUpdate() so it is atomic (R21). No pre-check here.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        $version = $this->findOr404($id);

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

    /**
     * GET /api/admin/kb/documents/{id}/versions/{versionId}/content
     *
     * v8.36 / ADR 0030 §5 — the version's content and which source it came
     * from (`artifact` | `reconstruction`). `{versionId}` must belong to
     * `{id}`'s family. Admin-only and un-redacted by design: this is the one
     * read path that returns the converter's output before the PII seam,
     * which is why it has no MCP twin (documented R44 exception).
     */
    public function content(int $id, int $versionId): JsonResponse
    {
        $anchor = $this->findOr404($id);
        $version = $this->resolveFamilyMember($anchor, $versionId);
        $content = $this->versions->contentFor($version);

        return response()->json([
            'data' => [
                'id' => (int) $version->id,
                'source' => $content['source'],
                // ADR 0030 §5 — verified | mismatch | null (no content_hash to check against)
                'integrity' => $content['integrity'] ?? null,
                'content_hash' => $version->content_hash,
                'content' => $content['content'],
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
