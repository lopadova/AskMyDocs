<?php

namespace App\Policies;

use App\Models\KnowledgeDocument;
use App\Models\User;

/**
 * Thin policy delegating every gate to User::hasDocumentAccess() so the
 * deny-wins / scope-allowlist logic lives in exactly one place.
 */
class KnowledgeDocumentPolicy
{
    public function view(User $user, KnowledgeDocument $doc): bool
    {
        return $user->hasDocumentAccess($doc, 'view');
    }

    public function edit(User $user, KnowledgeDocument $doc): bool
    {
        return $user->hasDocumentAccess($doc, 'edit');
    }

    public function delete(User $user, KnowledgeDocument $doc): bool
    {
        return $user->hasDocumentAccess($doc, 'delete');
    }

    public function promote(User $user, KnowledgeDocument $doc): bool
    {
        return $user->hasDocumentAccess($doc, 'promote');
    }
}
