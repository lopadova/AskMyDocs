<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user-created folder grouping chat sessions in the Sessions workspace.
 *
 * Authorization: folders are PRIVATE per user, so every query MUST carry
 * both `forTenant($tenantId)` (R30) and `where('user_id', ...)`. That is
 * enforced in {@see \App\Services\Chat\ChatFolderService}, not by a
 * policy and not by a global scope — the same posture as
 * {@see ChatFilterPreset}, so background and CLI contexts can still query
 * without an authenticated user.
 *
 * Deleting a folder NULLs `conversations.chat_folder_id` (nullOnDelete);
 * it never cascades. Cascading would silently destroy a user's chat
 * history, so an unfiled conversation is always the correct outcome.
 */
class ChatFolder extends Model
{
    use BelongsToTenant;

    protected $table = 'chat_folders';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'name',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Conversation, $this> */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'chat_folder_id');
    }
}
