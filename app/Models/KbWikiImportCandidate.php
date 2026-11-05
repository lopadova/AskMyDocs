<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * KbWikiImportCandidate — the idempotency record behind one `kb:import-wiki`
 * / `POST /api/admin/kb/imports` / `KbImportWikiTool` proposal (v8.38/W4c,
 * ADR 0032 §10/§11).
 *
 * Deliberately thin: this row never holds the proposed markdown or the
 * candidate's editorial content — it points at the
 * {@see \Padosoft\LaravelFlow\FlowRun} the actual promotion saga is running
 * under, so a REPLAYED call with the same `idempotency_key` can re-issue a
 * fresh approval token for that SAME run rather than starting a second one.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $project_key
 * @property string $slug
 * @property int|null $requested_by
 * @property string $idempotency_key
 * @property string $content_hash
 * @property string $flow_run_id
 * @property string $source
 */
final class KbWikiImportCandidate extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const SOURCE_CLI = 'cli';

    public const SOURCE_HTTP = 'http';

    public const SOURCE_MCP = 'mcp';

    protected $table = 'kb_wiki_import_candidates';

    protected $fillable = [
        'tenant_id',
        'project_key',
        'slug',
        'requested_by',
        'idempotency_key',
        'content_hash',
        'flow_run_id',
        'source',
    ];

    /**
     * Same construction as {@see KbTextCorrectionCandidate::idempotencyKeyFor()}:
     * each field is hashed to a fixed-length digest before concatenation so
     * the final digest is injective over the 5-tuple — a delimiter-joined
     * raw string is not, when `$actorIdentity` or `$contentHash` could in
     * principle carry adversarial content (they cannot today — both are
     * server-derived — but the construction costs nothing and keeps this
     * key mechanically identical to its sibling).
     */
    public static function idempotencyKeyFor(
        string $tenantId,
        string $projectKey,
        string $slug,
        string $contentHash,
        string $actorIdentity,
    ): string {
        $digests = array_map(
            static fn (string $part): string => hash('sha256', $part),
            [$tenantId, $projectKey, $slug, $contentHash, $actorIdentity],
        );

        return hash('sha256', implode('', $digests));
    }
}
