<?php

declare(strict_types=1);
namespace App\ApiTools\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class ApiToolStep extends Model
{
    use BelongsToTenant;
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $hidden = ['inputs', 'output'];
    protected function casts(): array { return ['inputs' => 'encrypted:array', 'output' => 'encrypted:array', 'diagnostics' => 'encrypted:array', 'started_at' => 'datetime', 'completed_at' => 'datetime']; }
}
