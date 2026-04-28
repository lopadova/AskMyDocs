<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMembership extends Model
{
    use BelongsToTenant;

    protected $table = 'project_memberships';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'project_key',
        'role',
        'scope_allowlist',
    ];

    protected $casts = [
        'scope_allowlist' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
