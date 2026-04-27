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
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
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
        $needle = $validated['q'];
        $escaped = str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $needle,
        );
        $like = "%{$escaped}%";

        $query->where(function ($w) use ($like): void {
            $w->whereRaw("title LIKE ? ESCAPE '\\'", [$like])
                ->orWhereRaw("source_path LIKE ? ESCAPE '\\'", [$like]);
        });

        $results = $query
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
