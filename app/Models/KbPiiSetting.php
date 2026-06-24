<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * v8.23 (Ciclo 4) — a per-(tenant, project) override for the PII ingestion
 * policy.
 *
 * `project_key='*'` is the tenant-wide default. Every override column is
 * nullable — a null value INHERITS the next level up (exact project →
 * tenant `*` → `config('kb.pii_redactor.*')`). Resolution lives in
 * {@see \App\Services\Kb\Pii\KbPiiPolicyResolver}.
 *
 * @property string $tenant_id
 * @property string $project_key
 * @property bool|null $redact_enabled
 * @property string|null $strategy
 */
class KbPiiSetting extends Model
{
    use BelongsToTenant;

    /** Sentinel project_key meaning "every project in this tenant". */
    public const WILDCARD = '*';

    /** The redaction strategies a policy row may select. */
    public const STRATEGIES = ['mask', 'tokenise'];

    protected $table = 'kb_pii_settings';

    protected $fillable = [
        'tenant_id',
        'project_key',
        'redact_enabled',
        'strategy',
    ];

    protected $casts = [
        'redact_enabled' => 'boolean',
    ];
}
