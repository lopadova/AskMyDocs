<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SessionMemoryFact extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['subject_identifiers' => 'array', 'value_json' => 'json', 'verification_json' => 'array', 'claim_json' => 'array'];

    public function observation(): BelongsTo
    {
        return $this->belongsTo(SessionMemoryObservation::class, 'observation_id');
    }
}
