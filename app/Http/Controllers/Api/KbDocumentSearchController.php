<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\KnowledgeDocument;
use App\Support\LikeEscaper;
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
 * the user-supplied query string is escaped via App\Support\LikeEscaper
 * (escape char `~`, not backslash — see that class) AND combined with its
 * ESCAPE clause so a literal `_` in the query doesn't act as a wildcard
 * (SQLite's default LIKE has NO escape character; Postgres/MySQL respect
 * the explicit clause portably).
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

        // R30 — scope autocomplete to the active tenant; project_keys alone
        // is not a tenant boundary (two tenants can share a project_key).
        $query = KnowledgeDocument::query()
            ->forTenant(app(\App\Support\TenantContext::class)->current())
            ->where('status', '!=', 'archived');

        $projectKeys = $validated['project_keys'] ?? [];
        if ($projectKeys !== []) {
            $query->whereIn('project_key', $projectKeys);
        }

        // R19 — escape %, _, and the escape char via LikeEscaper. The
        // escape char is `~`, NOT backslash: a backslash escape clause
        // crashes on Postgres+PDO with SQLSTATE[HY093] when another bound
        // `?` follows (PDO swallows the next placeholder). This path runs
        // against pgsql in the E2E job, so it MUST use the LikeEscaper
        // clause. See LikeEscaper for the full rationale.
        //
        // Case-folding portability: SQLite's LIKE is case-INSENSITIVE by
        // default while PostgreSQL's is case-SENSITIVE, so we lowercase BOTH
        // sides — the columns via LOWER(...) and the pattern via mb_strtolower.
        $qLower = mb_strtolower((string) $validated['q']);
        $escaped = LikeEscaper::escape($qLower);
        $prefixPat = $escaped.'%';
        $containsPat = '%'.$escaped.'%';

        $query->where(function ($w) use ($containsPat): void {
            $w->whereRaw('LOWER(title) LIKE ? '.LikeEscaper::ESCAPE_SQL, [$containsPat])
                ->orWhereRaw('LOWER(source_path) LIKE ? '.LikeEscaper::ESCAPE_SQL, [$containsPat]);
        });

        // v8.1 P2 — RELEVANCE ranking first, curation second. A title that
        // exactly equals or starts with the query is a far better @mention
        // hit than one where the query only appears mid-title or buried in
        // the path. Rank: title-exact (4) > title-prefix (3) >
        // title-contains (2) > path-contains (1). Within a rank we keep the
        // prior deterministic tie-breakers (canonical, curated weight,
        // recency, title, id) so results stay stable across dialects and as
        // the table grows. orderByRaw (not selectRaw) keeps the response
        // shape unchanged — the match rank never leaks to the FE.
        $results = $query
            ->orderByRaw(
                'CASE '
                .'WHEN LOWER(title) = ? THEN 4 '
                .'WHEN LOWER(title) LIKE ? '.LikeEscaper::ESCAPE_SQL.' THEN 3 '
                .'WHEN LOWER(title) LIKE ? '.LikeEscaper::ESCAPE_SQL.' THEN 2 '
                .'WHEN LOWER(source_path) LIKE ? '.LikeEscaper::ESCAPE_SQL.' THEN 1 '
                .'ELSE 0 END DESC',
                [$qLower, $prefixPat, $containsPat, $containsPat],
            )
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
