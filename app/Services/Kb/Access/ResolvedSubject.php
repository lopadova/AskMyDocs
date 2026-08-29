<?php

declare(strict_types=1);

namespace App\Services\Kb\Access;

use App\Models\KnowledgeDocumentAcl;

/**
 * An external principal successfully matched to a subject this application
 * can grant to.
 *
 * The types deliberately mirror `KnowledgeDocumentAcl::SUBJECT_*`, because
 * resolution exists to produce ACL rows and nothing else. A resolver that
 * returned something the ACL table cannot store would only be deferring the
 * failure to the insert.
 */
final class ResolvedSubject
{
    private function __construct(
        public readonly string $subjectType,
        public readonly string $subjectId,
    ) {}

    public static function user(int|string $userId): self
    {
        return new self(KnowledgeDocumentAcl::SUBJECT_USER, (string) $userId);
    }

    public static function role(string $roleName): self
    {
        return new self(KnowledgeDocumentAcl::SUBJECT_ROLE, $roleName);
    }

    public static function team(string $teamSlug): self
    {
        return new self(KnowledgeDocumentAcl::SUBJECT_TEAM, $teamSlug);
    }
}
