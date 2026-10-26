<?php

declare(strict_types=1);
namespace App\ApiTools\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class ApiToolRun extends Model
{
    use BelongsToTenant;
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['snapshot', 'context', 'inputs', 'outputs', 'continuation'];
    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'context' => 'encrypted:array', 'inputs' => 'encrypted:array', 'outputs' => 'encrypted:array', 'continuation' => 'encrypted:array', 'deadline_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime', 'resumed_at' => 'datetime'];
    }
    public function terminal(): bool { return in_array($this->status, ['completed', 'partial', 'failed', 'cancelled', 'timed_out'], true); }
}
