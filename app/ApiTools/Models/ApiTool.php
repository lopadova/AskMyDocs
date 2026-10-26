<?php

declare(strict_types=1);
namespace App\ApiTools\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ApiTool extends Model
{
    use BelongsToTenant;
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return ['publication_intent' => 'boolean', 'manual_disabled' => 'boolean', 'failure_threshold' => 'integer', 'consecutive_failures' => 'integer', 'verification_generation' => 'integer'];
    }
    public function revision(): BelongsTo { return $this->belongsTo(ApiToolRevision::class, 'current_revision_id'); }
    public function available(): bool { return $this->status === 'active' && $this->publication_intent && ! $this->manual_disabled; }
}
