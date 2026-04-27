<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\KnowledgeDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Document title/path autocomplete (T2.6).
 *
 * Backs the FE chat composer's `@mention` popover (T2.7/T2.8): the user
 * types `@policy` and we return up to 20 matching documents to render
 * as picker rows. Project-scoped via the optional `project_keys[]` query
 * param so a multi-tenant chat narrows to only the projects the user
 * has access to (the FE composer derives the same scope as the chat
 * payload's `filters.project_keys`).
 *
 * Title + source_path are searched with `LIKE` (substring match,
 * case-insensitive on most dialects). Per R19 (input-escape-complete),
 * the user-supplied query string is escaped for `%`, `_`, and `\`
 * AND combined with an explicit `ESCAPE '\\'` clause so a literal
 * `_` in the query doesn't accidentally act as a wildcard (SQLite's
 * default LIKE has NO escape character; PostgreSQL respects the
 * ESCAPE clause portably).
 *
 * Soft-deleted documents are excluded by the model's global scope;
 * archived rows are excluded with an explicit `status != 'archived'`
 * (matches the search service's hot-path semantics).
 *
 * Auth: Sanctum bearer token (same as /api/kb/chat).
 */
final class KbDocumentSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // Trim the query BEFORE validation so a whitespace-only input
        // ("  ") fails the `filled` + `min:2` guards instead of slipping
        // through and matching every row via a 0-char effective pattern.
        $rawNeedle = $request->input('q');
        if (is_string($rawNeedle)) {
            $request->merge(['q' => trim($rawNeedle)]);
        }

        $validated = $request->validate([
            'q' => ['required', 'filled', 'string', 'min:2', 'max:120'],
            'project_keys' => ['nullable', 'array'],
            'project_keys.*' => ['string', 'max:120'],
        ]);

        $query = KnowledgeDocument::query()
            ->where('status', '!=', 'archived');

        $projectKeys = $validated['project_keys'] ?? [];
        if ($projectKeys !== []) {
            $query->whereIn('project_key', $projectKeys);
        }

        // R19: escape `\` first (it's the escape char itself), then `%`
        // and `_` (the LIKE wildcards). Combine with an explicit
        // `ESCAPE '\\'` clause via whereRaw so the dialect actually
        // honours our backslash escapes — without the clause, SQLite's
        // default LIKE has NO escape character at all (our pattern
        // `Policy\_v2` would then match the literal 9-char string
        // including the backslash, NOT `Policy_v2`). PostgreSQL respects
        // the same `ESCAPE '\\'` clause, so the query is portable.
        // Laravel's `where('col', 'LIKE', $val)` does NOT support
        // tacking ESCAPE on the operator side, so whereRaw is the
        // only portable path.
        //
        // Case-folding portability: SQLite's LIKE is case-INSENSITIVE
        // by default while PostgreSQL's LIKE is case-SENSITIVE. To get
        // consistent autocomplete behaviour across both dialects (a
        // user typing `policy` MUST match `Policy Alpha`), we lowercase
        // BOTH sides — title/source_path via `LOWER(...)` and the
        // user's pattern via PHP's `mb_strtolower`. PostgreSQL's
        // `LOWER(text)` is index-friendly when paired with a functional
        // index; in v3.0 the autocomplete table is small enough that
        // the seq-scan cost is acceptable, and we can add the
        // functional index in v3.1 if profiling justifies it.
        $needle = mb_strtolower((string) $validated['q']);
        $escaped = str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $needle,
        );
        $like = "%{$escaped}%";

        $query->where(function ($w) use ($like): void {
            $w->whereRaw("LOWER(title) LIKE ? ESCAPE '\\'", [$like])
                ->orWhereRaw("LOWER(source_path) LIKE ? ESCAPE '\\'", [$like]);
        });

        // Deterministic ordering BEFORE the limit so the same query
        // returns the same 20 rows under both dialects and as the
        // table grows. Priority: canonical docs first (more
        // authoritative for citations), then by retrieval_priority
        // DESC (admin-curated weight), then by recency (indexed_at),
        // and finally by title + id as a stable tie-breaker.
        $results = $query
            ->orderByDesc('is_canonical')
            ->orderByDesc('retrieval_priority')
            ->orderByDesc('indexed_at')
            ->orderBy('title')
            ->orderBy('id')
            ->limit(20)
            ->get([
                'id',
                'project_key',
                'title',
                'source_path',
                'source_type',
                'canonical_type',
            ]);

        return response()->json(['data' => $results]);
    }
}
