<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * v8.8/W3 — a per-(tenant, project) override for the AI deep-analysis gate.
 *
 * `project_key='*'` is the tenant-wide default. Every boolean is nullable —
 * a null value INHERITS the next level up (exact project → tenant `*` →
 * `config('kb.change_analysis.*')`). Resolution lives in
 * {@see \App\Services\Kb\Analysis\ChangeAnalysisGate}.
 *
 * @property string $tenant_id
 * @property string $project_key
 * @property bool|null $enabled
 * @property bool|null $canonical
 * @property bool|null $non_canonical
 * @property bool|null $delete_enabled
 */
class KbAnalysisSetting extends Model
{
    use BelongsToTenant;

    /** Sentinel project_key meaning "every project in this tenant". */
    public const WILDCARD = '*';

    protected $table = 'kb_analysis_settings';

    protected $fillable = [
        'tenant_id',
        'project_key',
        'enabled',
        'canonical',
        'non_canonical',
        'delete_enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'canonical' => 'boolean',
        'non_canonical' => 'boolean',
        'delete_enabled' => 'boolean',
    ];
}
