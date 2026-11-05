<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Internal evidence archive. Always query through the session memory service. */
final class SessionMemoryObservation extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected $casts = ['evidence_json' => 'array', 'acquired_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];

    public function facts(): HasMany
    {
        return $this->hasMany(SessionMemoryFact::class, 'observation_id');
    }
}
