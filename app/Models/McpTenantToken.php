<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class McpTenantToken extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'mcp_tenant_tokens';

    protected $fillable = [
        'tenant_id',
        'label',
        'token_hash',
        'token_last4',
        'scopes_json',
        'created_by',
        'last_used_at',
        'expires_at',
        'revoked_at',
    ];

    protected $casts = [
        'scopes_json' => 'array',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];
}

