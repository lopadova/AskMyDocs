<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ui4WorkbenchSandboxObject extends Model
{
    protected $fillable = [
        'tenant_id',
        'user_id',
        'type',
        'title',
        'data',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'archived_at' => 'datetime',
        ];
    }
}
