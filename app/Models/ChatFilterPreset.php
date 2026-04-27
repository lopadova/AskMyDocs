<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * User-owned saved filter preset for the chat composer (T2.9).
 *
 * The `filters` JSON column carries a serialised RetrievalFilters
 * payload — the same shape the FE composer builds and the same shape
 * `KbChatRequest::toFilters()` consumes. Round-tripping is lossless:
 * save → load → POST to /api/kb/chat produces identical retrieval
 * scope as if the user had re-selected every filter manually.
 *
 * Authorization: every query MUST be scoped to `auth()->id()` —
 * presets are private per user. The controller enforces this via a
 * `where('user_id', auth()->id())` predicate on every list/show/update/
 * delete; the model itself doesn't carry a global scope so admin/
 * background contexts can still query without the constraint.
 */
class ChatFilterPreset extends Model
{
    protected $table = 'chat_filter_presets';

    protected $fillable = [
        'user_id',
        'name',
        'filters',
    ];

    protected $casts = [
        'filters' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
