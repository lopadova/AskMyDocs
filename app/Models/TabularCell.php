<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v4.7/W1 — Tabular cell.
 *
 * Materialises one (review, document, column) extraction. `content` is
 * the structured JSON `{summary, flag, reasoning, citations[]}` the
 * extractor produces. Status follows the
 * pending → generating → ready | failed state machine.
 *
 * Composite unique `(tenant_id, review_id, document_id, column_index)`
 * prevents duplicate cells under a single tenant. FK cascade on
 * review_id + document_id wipes orphans automatically.
 */
class TabularCell extends Model
{
    use BelongsToTenant;

    protected $table = 'tabular_cells';

    protected $fillable = [
        'tenant_id',
        'review_id',
        'document_id',
        'column_index',
        'content',
        'status',
        'flag',
        'generated_at',
    ];

    protected $casts = [
        'content' => 'array',
        'column_index' => 'int',
        'generated_at' => 'datetime',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(TabularReview::class, 'review_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'document_id');
    }
}
