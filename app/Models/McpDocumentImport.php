<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class McpDocumentImport extends Model
{
    use HasUuids;

    protected $guarded = ['id'];
    protected $casts = ['manifest' => 'array', 'approved_at' => 'datetime', 'expires_at' => 'datetime'];
}
