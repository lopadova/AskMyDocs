<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Scopes\AccessScopeScope;
use App\Support\Canonical\GenerationSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class KnowledgeDocument extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'project_key',
        'source_type',
        'title',
        'source_path',
        'markdown_path',
        'mime_type',
        'language',
        'access_scope',
        'status',
        'document_hash',
        'version_hash',
        'metadata',
        'source_updated_at',
        'indexed_at',
        // --- canonical columns (added in 2026_04_22_000001) -----------
        'doc_id',
        'slug',
        'canonical_type',
        'canonical_status',
        'is_canonical',
        'generation_source',
        'retrieval_priority',
        'source_of_truth',
        'frontmatter_json',
    ];

    protected $casts = [
        'metadata' => 'array',
        'source_updated_at' => 'datetime',
        'indexed_at' => 'datetime',
        'deleted_at' => 'datetime',
        // canonical casts
        'is_canonical' => 'bool',
        'source_of_truth' => 'bool',
        'retrieval_priority' => 'int',
        'frontmatter_json' => 'array',
    ];

    /**
     * Wire the per-user access-scope global filter. The SoftDeletes trait
     * registers its own scope via the trait's boot method — this addition
     * composes on top without fighting it.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new AccessScopeScope);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(
            KbTag::class,
            'knowledge_document_tags',
            'knowledge_document_id',
            'kb_tag_id',
        )->withTimestamps();
    }

    public function acl(): HasMany
    {
        return $this->hasMany(KnowledgeDocumentAcl::class, 'knowledge_document_id');
    }

    // -----------------------------------------------------------------
    // Canonical-aware query scopes (see ADR 0001)
    // -----------------------------------------------------------------

    /**
     * Only documents marked as canonical (typed markdown with frontmatter).
     */
    public function scopeCanonical(Builder $query): Builder
    {
        return $query->where('is_canonical', true);
    }

    /**
     * Non-canonical documents — the inverse of `canonical()`. Introduced
     * for the admin tree explorer's raw-mode filter so call sites use the
     * same named scope vocabulary (`canonical()` / `raw()`) instead of
     * inlining `where('is_canonical', false)` and drifting from R10.
     */
    public function scopeRaw(Builder $query): Builder
    {
        return $query->where('is_canonical', false);
    }

    /**
     * Only canonical documents in `accepted` status.
     *
     * Composes with `canonical()` so a stray `canonical_status='accepted'`
     * on a non-canonical row (manual update, partial backfill) cannot
     * leak into retrieval. `accepted()` always implies canonical.
     */
    public function scopeAccepted(Builder $query): Builder
    {
        return $query->canonical()->where('canonical_status', 'accepted');
    }

    /**
     * Filter by canonical type (one of the 9 CanonicalType values).
     */
    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('canonical_type', $type);
    }

    /**
     * Lookup by project-scoped slug. Canonical slugs are unique per project.
     */
    public function scopeBySlug(Builder $query, string $projectKey, string $slug): Builder
    {
        return $query->where('project_key', $projectKey)->where('slug', $slug);
    }

    // -----------------------------------------------------------------
    // v8.11 Auto-Wiki — provenance-tier scopes (see GenerationSource)
    // -----------------------------------------------------------------

    /**
     * Only AUTO-tier documents (compiled / enriched by the AutoWikiCompiler).
     * Second-class to human-curated: ranked below human `accepted` by the
     * reranker firewall and promotable to `human` by an admin.
     */
    public function scopeAuto(Builder $query): Builder
    {
        return $query->where('generation_source', GenerationSource::Auto->value);
    }

    /**
     * Only HUMAN-curated documents — the authoritative, human-vouched tier
     * (ADR 0003). The inverse of `auto()`; explicit named scope so call sites
     * never inline `where('generation_source', 'human')` and drift (R10).
     */
    public function scopeHumanCurated(Builder $query): Builder
    {
        return $query->where('generation_source', GenerationSource::Human->value);
    }
}
