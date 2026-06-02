<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\KbSynonym;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * v8.7/W1 — Admin RESTful CRUD on `kb_synonyms`.
 *
 * Synonym groups are scoped per (tenant_id, project_key): a `term` is
 * unique within a single project of a single tenant, but the SAME term
 * can legitimately exist (with different synonyms) in another project OR
 * another tenant. `term` and every `synonyms` entry are lowercased on
 * write so {@see App\Services\Kb\Retrieval\SynonymExpander} matches them
 * case-insensitively at retrieval time.
 *
 * Auth: `auth:sanctum` + `role:admin|super-admin` (route group at
 * routes/api.php). R30 — every read/write is tenant-scoped via
 * `forTenant($this->tenant->current())`, so an admin only ever
 * sees/edits synonyms of the active tenant; cross-tenant access is
 * structurally impossible (IDOR-safe in findOr404).
 */
final class SynonymController extends Controller
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * GET /api/admin/kb/synonyms?project_keys[]=...
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_keys' => ['nullable', 'array'],
            'project_keys.*' => ['string', 'max:120'],
        ]);

        $projectKeys = $validated['project_keys'] ?? [];

        $query = KbSynonym::query()
            ->forTenant($this->tenant->current())
            ->orderBy('project_key')
            ->orderBy('term');

        if ($projectKeys !== []) {
            $query->whereIn('project_key', $projectKeys);
        }

        return response()->json([
            'data' => $query->get(['id', 'project_key', 'term', 'synonyms', 'enabled', 'created_at', 'updated_at']),
        ]);
    }

    /**
     * POST /api/admin/kb/synonyms
     *
     * Creates a synonym group scoped to a project. `term` uniqueness is
     * per (tenant_id, project_key, term). `term` + `synonyms` are
     * lowercased + de-duplicated; at least one synonym distinct from the
     * term is required (a group of one is meaningless).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules($request, null));

        $payload = $this->normalizePayload($validated, $request);

        $synonym = KbSynonym::create($payload);

        return response()->json([
            'data' => $synonym->only(['id', 'project_key', 'term', 'synonyms', 'enabled', 'created_at', 'updated_at']),
        ], 201);
    }

    /**
     * GET /api/admin/kb/synonyms/{id}
     */
    public function show(int $id): JsonResponse
    {
        $synonym = $this->findOr404($id);

        return response()->json([
            'data' => $synonym->only(['id', 'project_key', 'term', 'synonyms', 'enabled', 'created_at', 'updated_at']),
        ]);
    }

    /**
     * PUT /api/admin/kb/synonyms/{id}
     *
     * Updates term / synonyms / enabled. `project_key` cannot change —
     * a group's identity is its (project, term) pair; moving it between
     * projects is delete + recreate (mirrors TagController's rationale).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $synonym = $this->findOr404($id);

        if ($request->has('project_key') && $request->input('project_key') !== $synonym->project_key) {
            throw new \Illuminate\Validation\ValidationException(
                validator(
                    ['project_key' => $request->input('project_key')],
                    ['project_key' => 'in:' . $synonym->project_key],
                    ['project_key.in' => 'Cannot move a synonym group between projects. Delete and recreate instead.'],
                )
            );
        }

        $validated = $request->validate($this->rules($request, $synonym));

        // If `term` changes but `synonyms` is omitted, re-validate the
        // EXISTING synonyms against the new term: an existing synonym equal
        // to the new term must be dropped, and if that empties the group
        // the request is rejected (422) so the "≥1 distinct synonym"
        // invariant always holds (Copilot review).
        if (array_key_exists('term', $validated) && ! array_key_exists('synonyms', $validated)) {
            $validated['synonyms'] = $synonym->synonyms ?? [];
        }

        $payload = $this->normalizePayload($validated, $request, $synonym);

        $synonym->update($payload);

        return response()->json([
            'data' => $synonym->only(['id', 'project_key', 'term', 'synonyms', 'enabled', 'created_at', 'updated_at']),
        ]);
    }

    /**
     * DELETE /api/admin/kb/synonyms/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $synonym = $this->findOr404($id);
        $synonym->delete();

        return response()->json(null, 204);
    }

    /**
     * Validation rules shared by store + update. On update most fields are
     * `sometimes`. The term-uniqueness rule is scoped to
     * (tenant_id, project_key) and ignores the current row on update.
     *
     * @return array<string, array<int, mixed>>
     */
    private function rules(Request $request, ?KbSynonym $existing): array
    {
        $isCreate = $existing === null;
        $projectKey = $isCreate
            ? (string) $request->input('project_key')
            : $existing->project_key;
        $tenantId = $isCreate ? $this->tenant->current() : $existing->tenant_id;

        $termRule = $isCreate ? ['required'] : ['sometimes'];
        $termRule = array_merge($termRule, [
            'string',
            'max:200',
            Rule::unique('kb_synonyms', 'term')
                ->where(fn ($q) => $q
                    ->where('tenant_id', $tenantId)
                    ->where('project_key', $projectKey))
                ->ignore($existing?->id),
        ]);

        return [
            'project_key' => $isCreate ? ['required', 'string', 'max:120'] : ['sometimes', 'string', 'max:120'],
            'term' => $termRule,
            'synonyms' => $isCreate ? ['required', 'array', 'min:1'] : ['sometimes', 'array', 'min:1'],
            'synonyms.*' => ['string', 'max:200'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Lowercase + de-duplicate term and synonyms; drop any synonym equal
     * to the term so the persisted group has ≥1 genuinely-distinct member.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizePayload(array $validated, Request $request, ?KbSynonym $existing = null): array
    {
        $payload = $validated;

        if (array_key_exists('term', $payload)) {
            $payload['term'] = $this->lower((string) $payload['term']);
        }

        if (array_key_exists('synonyms', $payload)) {
            $term = $payload['term'] ?? $existing?->term ?? '';
            $synonyms = collect((array) $payload['synonyms'])
                ->map(fn ($s) => $this->lower((string) $s))
                ->filter(fn (string $s) => $s !== '' && $s !== $term)
                ->unique()
                ->values()
                ->all();

            if ($synonyms === []) {
                throw new \Illuminate\Validation\ValidationException(
                    validator(
                        ['synonyms' => $payload['synonyms']],
                        ['synonyms' => 'array'],
                        ['synonyms.array' => 'Provide at least one synonym different from the term.'],
                    )->after(function ($validator): void {
                        $validator->errors()->add('synonyms', 'Provide at least one synonym different from the term.');
                    })
                );
            }

            $payload['synonyms'] = $synonyms;
        }

        return $payload;
    }

    private function lower(string $value): string
    {
        return (string) preg_replace('/\s+/', ' ', mb_strtolower(trim($value)));
    }

    private function findOr404(int $id): KbSynonym
    {
        $synonym = KbSynonym::query()->forTenant($this->tenant->current())->find($id);
        if ($synonym === null) {
            throw new NotFoundHttpException('Synonym group not found.');
        }

        return $synonym;
    }
}
