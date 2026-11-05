<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * An explicit business vocabulary used to interpret a chat request before any
 * vector search happens.  This is configuration, never inferred from KB text.
 */
final class KbRetrievalProfile extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'project_key',
        'company_context',
        'glossary',
        'relevant_entities',
        'expected_facts',
        'preferred_source_types',
    ];

    protected $casts = [
        'glossary' => 'array',
        'relevant_entities' => 'array',
        'expected_facts' => 'array',
        'preferred_source_types' => 'array',
    ];
}
