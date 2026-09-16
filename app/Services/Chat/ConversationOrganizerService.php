<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Exceptions\Chat\ChatFolderNotOwnedException;
use App\Models\Conversation;
use App\Support\Chat\ConversationArchiveScope;
use App\Support\Chat\ConversationImportance;
use Illuminate\Database\Eloquent\Collection;

/**
 * Listing and organisation of a user's chat sessions: order, folder,
 * pin, archive, importance.
 *
 * Single core (R44): the HTTP controller adapts request → this →
 * response and holds no logic of its own. Tenant + owner scoping lives
 * HERE so no caller can forget it (R30/R33).
 *
 * Every organisation write runs with `timestamps = false`. `updated_at`
 * is the recency ordering key AND the "last activity" the sidebar shows,
 * so letting a pin or an archive bump it would teleport an untouched
 * thread to the top of the list — archive-then-restore would silently
 * reorder a user's history. Renaming still bumps it, unchanged: that IS
 * activity on the thread.
 */
final class ConversationOrganizerService
{
    public function __construct(private readonly ChatFolderService $folders)
    {
    }

    /**
     * The acting user's sessions: pinned first (most recently pinned
     * first), then by importance, then by recency.
     *
     * @return Collection<int, Conversation>
     */
    public function list(int $userId, string $tenantId, ConversationArchiveScope $scope): Collection
    {
        $query = Conversation::query()
            ->forTenant($tenantId)
            ->where('user_id', $userId);

        $query = match ($scope) {
            ConversationArchiveScope::Active => $query->whereNull('archived_at'),
            ConversationArchiveScope::Archived => $query->whereNotNull('archived_at'),
            ConversationArchiveScope::All => $query,
        };

        // `ORDER BY pinned_at DESC` is NOT portable: PostgreSQL sorts
        // NULLs first on DESC, SQLite sorts them last, and MySQL has no
        // NULLS LAST at all. The explicit null-rank CASE behaves the same
        // everywhere. The importance CASE is built from the enum, never
        // from request data (R19).
        return $query
            ->orderByRaw('CASE WHEN pinned_at IS NULL THEN 1 ELSE 0 END ASC')
            ->orderByDesc('pinned_at')
            ->orderByRaw(ConversationImportance::orderByCaseSql().' ASC')
            ->orderByDesc('updated_at')
            ->get();
    }

    /**
     * File the session into one of the user's folders, or unfile it with
     * null.
     *
     * The folder is re-verified here even though the FormRequest already
     * validated ownership: this service is a public PHP surface, and a
     * cross-user folder id must fail loudly rather than move a thread
     * into someone else's folder (R21 defence in depth, SEC-IDOR-001).
     */
    public function setFolder(Conversation $conversation, ?int $folderId): Conversation
    {
        if ($folderId !== null) {
            $owned = $this->folders->find(
                $folderId,
                (int) $conversation->user_id,
                (string) $conversation->tenant_id,
            );

            if ($owned === null) {
                throw ChatFolderNotOwnedException::forFolder($folderId);
            }
        }

        return $this->persist($conversation, ['chat_folder_id' => $folderId]);
    }

    public function setPinned(Conversation $conversation, bool $pinned): Conversation
    {
        return $this->persist($conversation, [
            'pinned_at' => $pinned ? now() : null,
        ]);
    }

    public function setArchived(Conversation $conversation, bool $archived): Conversation
    {
        return $this->persist($conversation, [
            'archived_at' => $archived ? now() : null,
        ]);
    }

    public function setImportance(Conversation $conversation, ConversationImportance $importance): Conversation
    {
        return $this->persist($conversation, ['importance' => $importance->value]);
    }

    /**
     * Write organisation attributes WITHOUT touching `updated_at`.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function persist(Conversation $conversation, array $attributes): Conversation
    {
        $conversation->timestamps = false;

        try {
            $conversation->forceFill($attributes)->save();
        } finally {
            // Restore the default so a later save on this same instance
            // (a rename in the same request, say) still records activity.
            $conversation->timestamps = true;
        }

        return $conversation;
    }
}
