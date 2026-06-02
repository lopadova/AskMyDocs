<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * v8.7/W3–W4 — a single AI deep-analysis run for a document change.
 *
 * `analysis_json` holds the structured LLM output:
 *   - `enhancement_suggestions`: list<string>
 *   - `cross_references`: list<{slug, title, why}>
 *   - `impacted_docs`: list<{slug, title, impact, suggested_action}>
 *
 * @property string $tenant_id
 * @property string $project_key
 * @property int $knowledge_document_id
 * @property string $trigger
 * @property array $analysis_json
 * @property int $suggestion_count
 * @property int $impacted_count
 * @property string $status
 */
class KbDocAnalysis extends Model
{
    use BelongsToTenant;

    public const TRIGGER_INGESTED = 'ingested';
    public const TRIGGER_MODIFIED = 'modified';
    public const TRIGGER_DELETED = 'deleted';

    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $table = 'kb_doc_analyses';

    protected $fillable = [
        'tenant_id',
        'project_key',
        'knowledge_document_id',
        'doc_slug',
        'trigger',
        'analysis_json',
        'suggestion_count',
        'impacted_count',
        'provider',
        'model',
        'status',
        'error',
    ];

    protected $casts = [
        'analysis_json' => 'array',
        'suggestion_count' => 'int',
        'impacted_count' => 'int',
    ];
}
