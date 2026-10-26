<?php

declare(strict_types=1);
namespace App\ApiTools\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class ApiToolRevision extends Model
{
    use BelongsToTenant;
    public $timestamps = false;
    protected $guarded = ['id'];
    protected function casts(): array { return ['definition' => 'encrypted:array', 'created_at' => 'datetime']; }
    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('API tool revisions are immutable.'));
    }
}
