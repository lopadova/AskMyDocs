<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * v8.44/W4 (ADR 0035 §D6.4) — an admin-authored description of a part of the
 * corpus, bound to a `source_path` prefix within a (tenant, project).
 *
 * The description is ADMIN-AUTHORED prose that is later rendered into the RAG
 * prompt: it is treated as untrusted DATA there (delimited, never promoted to
 * a system instruction — SEC-LLM-001 gate 4), which is why it is capped at 500
 * characters and every mutation is audited.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $project_key
 * @property string $path_prefix
 * @property string $description
 * @property int|null $updated_by
 */
class KbPathContext extends Model
{
    use BelongsToTenant;

    protected $table = 'kb_path_contexts';

    protected $fillable = [
        'tenant_id',
        'project_key',
        'path_prefix',
        'description',
        'updated_by',
    ];
}
