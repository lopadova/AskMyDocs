<?php

declare(strict_types=1);

namespace App\Services\Kb\Agent;

use App\Models\KnowledgeDocument;
use App\Support\LikeEscaper;
use Illuminate\Database\Eloquent\Builder;

/**
 * W6 (qmd parity) — short document references: `#3f9a1c` resolves to the
 * document whose content hash (`document_hash`, SHA-256 hex) starts with
 * those characters, the way qmd lets an agent cite and re-open `#docid`.
 *
 * Contract:
 *  - 6–64 lowercase hex characters after an optional leading `#`; anything
 *    else is not a reference ({@see parse()} returns null) — so a source path
 *    can never be mistaken for one;
 *  - tenant-scoped (R30) and read through Eloquent, so the soft-delete and
 *    the ACL global scopes apply exactly as on every other read (R2, R33);
 *  - archived versions are excluded: a short reference names the live text;
 *  - an ambiguous prefix is an error with the candidates, never a silent pick
 *    of the first row (R14): `kb:show` lists them and fails, the MCP read tool
 *    returns an error naming them, `multi_get` reports `ambiguous_reference`
 *    under `missing`.
 */
final class DocumentReferenceResolver
{
    public const MIN_LENGTH = 6;

    public const DISPLAY_LENGTH = 12;

    private const MAX_CANDIDATES = 5;

    /** The normalised hex prefix of a reference, or null when `$value` is not one. */
    public static function parse(string $value): ?string
    {
        $candidate = strtolower(ltrim(trim($value), '#'));
        if (! str_starts_with(trim($value), '#')
            && (preg_match('/^[0-9a-f]{12,64}$/', $candidate) !== 1 || preg_match('/[a-f]/', $candidate) !== 1)) {
            // Without a leading `#`, only an unmistakable hash counts: 12+ hex
            // characters with at least one letter, so a numeric id never is one.
            return null;
        }

        return preg_match('/^[0-9a-f]{'.self::MIN_LENGTH.',64}$/', $candidate) === 1 ? $candidate : null;
    }

    /** The short reference shown next to a document (`#` + 12 hex). */
    public static function shortRef(?string $documentHash): ?string
    {
        return $documentHash === null || $documentHash === '' ? null : '#'.substr($documentHash, 0, self::DISPLAY_LENGTH);
    }

    /**
     * @throws DocumentReferenceNotFound
     * @throws AmbiguousDocumentReference
     */
    public function resolve(string $tenantId, string $reference, ?string $projectKey = null): KnowledgeDocument
    {
        $prefix = self::parse($reference);
        if ($prefix === null) {
            throw new DocumentReferenceNotFound($reference);
        }

        $matches = KnowledgeDocument::query()
            ->forTenant($tenantId)
            ->where('status', '!=', 'archived')
            ->when($projectKey !== null && $projectKey !== '', fn (Builder $q) => $q->where('project_key', $projectKey))
            // The hash is stored lowercase hex and the prefix is normalised: no
            // function on the column, so a btree index on document_hash applies.
            ->whereRaw('document_hash LIKE ? '.LikeEscaper::ESCAPE_SQL, [LikeEscaper::escape($prefix).'%'])
            ->orderBy('id')
            ->limit(self::MAX_CANDIDATES + 1)
            ->get();

        if ($matches->isEmpty()) {
            throw new DocumentReferenceNotFound($reference);
        }
        if ($matches->count() > 1) {
            throw new AmbiguousDocumentReference($reference, $matches->take(self::MAX_CANDIDATES)->map(fn (KnowledgeDocument $d): array => [
                'id' => $d->id,
                'ref' => self::shortRef($d->document_hash),
                'project_key' => $d->project_key,
                'source_path' => $d->source_path,
                'title' => $d->title,
            ])->values()->all());
        }

        return $matches->first();
    }
}
