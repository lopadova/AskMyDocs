<?php

declare(strict_types=1);
namespace App\ApiTools\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class ApiToolVerification extends Model
{
    use BelongsToTenant;
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $hidden = ['results'];
    protected function casts(): array { return ['results' => 'encrypted:array', 'created_at' => 'datetime', 'completed_at' => 'datetime']; }
}
