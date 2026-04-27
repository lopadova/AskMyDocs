<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'metadata',
        'rating',
        'confidence',
        'refusal_reason',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'confidence' => 'integer',
        'created_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
