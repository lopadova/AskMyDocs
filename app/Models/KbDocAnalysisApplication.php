<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * v8.11/P8 — audit of an applied change/delete suggestion. See the
 * `kb_doc_analysis_applications` migration. Append-only (no updated_at);
 * tenant-aware (R30/R31).
 *
 * @property string      $tenant_id
 * @property string      $project_key
 * @property int|null    $analysis_id
 * @property string      $suggestion_type  'cross_reference' | 'impacted'
 * @property string      $action
 * @property string|null $source_slug
 * @property string|null $target_slug
 * @property array|null  $before_json
 * @property array|null  $after_json
 * @property string      $applied_by
 */
class KbDocAnalysisApplication extends Model
{
    use BelongsToTenant;

    protected $table = 'kb_doc_analysis_applications';

    public const UPDATED_AT = null; // append-only

    protected $fillable = [
        'tenant_id',
        'project_key',
        'analysis_id',
        'suggestion_type',
        'action',
        'source_slug',
        'target_slug',
        'before_json',
        'after_json',
        'applied_by',
        'created_at',
    ];

    protected $casts = [
        'before_json' => 'array',
        'after_json' => 'array',
    ];
}
