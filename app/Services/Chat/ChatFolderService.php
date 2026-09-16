<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Models\ChatFolder;
use Illuminate\Database\Eloquent\Collection;

/**
 * CRUD for the user-created folders that group chat sessions.
 *
 * This is the single core (R44): the HTTP controller and any future
 * caller adapt input and output around it, never re-implementing the
 * scoping. Folders are private per (tenant, user), so EVERY query here
 * carries `forTenant()` (R30) and `where('user_id', ...)` — there is no
 * policy and no global scope by design, mirroring
 * {@see \App\Models\ChatFilterPreset}, so CLI and queue contexts can
 * still operate without an authenticated user.
 */
final class ChatFolderService
{
    /**
     * The acting user's folders, in sidebar order.
     *
     * @return Collection<int, ChatFolder>
     */
    public function listFor(int $userId, string $tenantId): Collection
    {
        return ChatFolder::query()
            ->forTenant($tenantId)
            ->where('user_id', $userId)
            ->orderBy('position')
            ->orderBy('name')
            ->get();
    }

    /**
     * Resolve one folder the user owns, or null.
     *
     * Returning null (rather than throwing) keeps the caller free to
     * answer 404 without leaking whether the id exists in another
     * tenant or for another user — no existence oracle (SEC-IDOR-001).
     */
    public function find(int $folderId, int $userId, string $tenantId): ?ChatFolder
    {
        return ChatFolder::query()
            ->forTenant($tenantId)
            ->where('user_id', $userId)
            ->find($folderId);
    }

    public function create(int $userId, string $tenantId, string $name, int $position = 0): ChatFolder
    {
        return ChatFolder::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'name' => $name,
            'position' => $position,
        ]);
    }

    public function rename(ChatFolder $folder, string $name): ChatFolder
    {
        $folder->update(['name' => $name]);

        return $folder->refresh();
    }

    /**
     * Delete a folder. Its conversations are UNFILED, never deleted —
     * the FK is nullOnDelete (see the migration), so this is enforced by
     * the schema rather than by a sweep here.
     */
    public function delete(ChatFolder $folder): void
    {
        $folder->delete();
    }
}
