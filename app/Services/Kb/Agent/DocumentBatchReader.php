<?php

declare(strict_types=1);

namespace App\Services\Kb\Agent;

use App\Models\KnowledgeDocument;
use App\Support\KbPath;
use App\Support\LikeEscaper;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * W6 (qmd parity — `multi_get`) — the ONE core behind `kb:multi-get`,
 * `POST /api/kb/documents/multi` and the `KbMultiGetTool` MCP tool (R44).
 *
 * An agent that already knows WHICH documents it needs (from citations, a
 * facet listing, a previous search) reads them in one bounded call instead of
 * N round trips. Each selector is one of:
 *
 *  - a short reference `#3f9a1c…` ({@see DocumentReferenceResolver});
 *  - a glob over `source_path` (`policies/**`, `hr/*.md`) — the repository's
 *    own glob semantics ({@see KbPath::matchesAnyGlob()}), after a SQL
 *    pre-filter on the literal prefix before the first wildcard (R19: the
 *    prefix goes through {@see LikeEscaper});
 *  - an exact source path ({@see KbPath::normalize()}, R1).
 *
 * Every read is tenant-scoped (R30) and goes through Eloquent, so soft-delete
 * and the ACL scope apply (R2, R33). Archived versions are never returned.
 *
 * Bounded output (R14 — truncation is declared, never silent):
 *  - at most `kb.multi_get.max_documents` documents (default 20);
 *  - a total byte budget (`max_bytes`, default 65 536, ceiling
 *    `kb.multi_get.max_bytes_ceiling`); the document that crosses the budget is
 *    cut on a UTF-8 boundary and flagged `truncated`, the ones after it are
 *    listed under `omitted` (ids + paths, no content);
 *  - a selector that matches nothing is reported under `missing`, with
 *    "did you mean" suggestions for an exact path.
 */
final class DocumentBatchReader
{
    public const MAX_SELECTORS = 20;

    private const GLOB_CANDIDATE_CAP = 500;

    public function __construct(
        private readonly DocumentLineReader $lines,
        private readonly DocumentReferenceResolver $references,
        private readonly SimilarDocumentFinder $similar,
    ) {}

    public function maxDocuments(): int
    {
        return max(1, (int) config('kb.multi_get.max_documents', 20));
    }

    public function maxBytesCeiling(): int
    {
        return max(1024, (int) config('kb.multi_get.max_bytes_ceiling', 262_144));
    }

    /**
     * @param  list<string>  $selectors
     * @return array{
     *   documents: list<array<string,mixed>>,
     *   omitted: list<array{id:int, ref:?string, source_path:string, bytes:int}>,
     *   missing: list<array{selector:string, reason:string, suggestions?:list<array<string,mixed>>, candidates?:list<array<string,mixed>>}>,
     *   meta: array{max_bytes:int, bytes_returned:int, max_documents:int, truncated:bool}
     * }
     */
    public function read(string $tenantId, array $selectors, ?string $projectKey = null, ?int $maxBytes = null): array
    {
        $selectors = array_values(array_unique(array_filter(array_map(
            static fn ($s): string => trim((string) $s),
            $selectors,
        ), static fn (string $s): bool => $s !== '')));
        if ($selectors === []) {
            throw new InvalidArgumentException('At least one selector (path, glob or #ref) is required.');
        }
        if (count($selectors) > self::MAX_SELECTORS) {
            throw new InvalidArgumentException('At most '.self::MAX_SELECTORS.' selectors per call.');
        }

        $budget = max(1, min($maxBytes ?? (int) config('kb.multi_get.default_max_bytes', 65_536), $this->maxBytesCeiling()));
        $limit = $this->maxDocuments();

        /** @var array<int, KnowledgeDocument> $found */
        $found = [];
        $missing = [];
        foreach ($selectors as $selector) {
            $matches = $this->resolveSelector($tenantId, $selector, $projectKey, $missing);
            foreach ($matches as $doc) {
                $found[$doc->id] ??= $doc;
            }
        }

        $documents = [];
        $omitted = [];
        $used = 0;
        $truncated = false;
        foreach (array_values($found) as $index => $doc) {
            $content = $this->lines->content($doc);
            $bytes = strlen($content);
            $remaining = $budget - $used;
            if ($index >= $limit || $remaining <= 0) {
                $omitted[] = ['id' => $doc->id, 'ref' => DocumentReferenceResolver::shortRef($doc->document_hash), 'source_path' => (string) $doc->source_path, 'bytes' => $bytes];
                $truncated = true;

                continue;
            }
            $cut = $bytes > $remaining;
            $body = $cut ? mb_strcut($content, 0, $remaining, 'UTF-8') : $content;
            $used += strlen($body);
            $truncated = $truncated || $cut;
            $documents[] = [
                'id' => $doc->id,
                'ref' => DocumentReferenceResolver::shortRef($doc->document_hash),
                'project_key' => $doc->project_key,
                'title' => $doc->title,
                'source_path' => $doc->source_path,
                'source_type' => $doc->source_type,
                'bytes' => $bytes,
                'truncated' => $cut,
                'content' => $body,
            ];
        }

        return [
            'documents' => $documents,
            'omitted' => $omitted,
            'missing' => $missing,
            'meta' => ['max_bytes' => $budget, 'bytes_returned' => $used, 'max_documents' => $limit, 'truncated' => $truncated],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $missing  (by reference) misses are appended here
     * @return list<KnowledgeDocument>
     */
    private function resolveSelector(string $tenantId, string $selector, ?string $projectKey, array &$missing): array
    {
        if (DocumentReferenceResolver::parse($selector) !== null) {
            try {
                return [$this->references->resolve($tenantId, $selector, $projectKey)];
            } catch (AmbiguousDocumentReference $e) {
                $missing[] = ['selector' => $selector, 'reason' => 'ambiguous_reference', 'candidates' => $e->candidates];
            } catch (DocumentReferenceNotFound) {
                $missing[] = ['selector' => $selector, 'reason' => 'not_found'];
            }

            return [];
        }

        if (strpbrk($selector, '*?[') !== false) {
            $matches = $this->globMatches($tenantId, $selector, $projectKey);
            if ($matches === []) {
                $missing[] = ['selector' => $selector, 'reason' => 'no_match'];
            }

            return $matches;
        }

        try {
            $path = KbPath::normalize($selector);
        } catch (InvalidArgumentException) {
            $missing[] = ['selector' => $selector, 'reason' => 'invalid_path'];

            return [];
        }
        $docs = $this->base($tenantId, $projectKey)->where('source_path', $path)->orderBy('id')->get()->all();
        if ($docs === []) {
            $missing[] = ['selector' => $selector, 'reason' => 'not_found', 'suggestions' => $this->similar->suggest($tenantId, $path, $projectKey)];
        }

        return $docs;
    }

    /** @return list<KnowledgeDocument> */
    private function globMatches(string $tenantId, string $glob, ?string $projectKey): array
    {
        $glob = ltrim(str_replace('\\', '/', $glob), '/');
        $literalPrefix = substr($glob, 0, (int) strcspn($glob, '*?['));

        return $this->base($tenantId, $projectKey)
            ->when($literalPrefix !== '', fn (Builder $q) => $q->whereRaw('source_path LIKE ? '.LikeEscaper::ESCAPE_SQL, [LikeEscaper::escape($literalPrefix).'%']))
            ->orderBy('source_path')
            ->orderBy('id')
            ->limit(self::GLOB_CANDIDATE_CAP)
            ->get()
            ->filter(fn (KnowledgeDocument $d): bool => KbPath::matchesAnyGlob((string) $d->source_path, [$glob]))
            ->values()
            ->all();
    }

    private function base(string $tenantId, ?string $projectKey): Builder
    {
        return KnowledgeDocument::query()
            ->forTenant($tenantId)
            ->where('status', '!=', 'archived')
            ->when($projectKey !== null && $projectKey !== '', fn (Builder $q) => $q->where('project_key', $projectKey));
    }
}
