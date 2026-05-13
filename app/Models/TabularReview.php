<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * v4.7/W1 — Tabular Review.
 *
 * Spreadsheet-style extraction review over N documents. The column
 * configuration drives the extractor; cells materialise (document,
 * column) results.
 *
 * R30/R31: tenant-scoped via `BelongsToTenant`. Every read query must
 * call `->forTenant($ctx->current())` or include an explicit
 * `where('tenant_id', ...)` predicate.
 *
 * `workflow_id` will FK to `workflows.id` in W2 — kept as a plain
 * nullable bigint here so W1 ships independently.
 */
class TabularReview extends Model
{
    use BelongsToTenant;

    protected $table = 'tabular_reviews';

    protected $fillable = [
        'tenant_id',
        'project_key',
        'user_id',
        'title',
        'columns_config',
        'workflow_id',
        'shared_with',
        'practice',
    ];

    protected $casts = [
        'columns_config' => 'array',
        'shared_with' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cells(): HasMany
    {
        return $this->hasMany(TabularCell::class, 'review_id');
    }
}
