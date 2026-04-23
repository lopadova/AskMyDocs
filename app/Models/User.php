<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    // Sentinel value returned by allowedProjects() when the user has the
    // global `kb.read.any` permission (super-admin / admin / editor /
    // viewer all have it by default — see RbacSeeder). Consumers check
    // for `in_array('*', ...)` before filtering the project_key column.
    public const PROJECT_WILDCARD = '*';

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function chatLogs(): HasMany
    {
        return $this->hasMany(ChatLog::class);
    }

    public function projectMemberships(): HasMany
    {
        return $this->hasMany(ProjectMembership::class);
    }

    /**
     * List of project_key values this user can access.
     *
     * - Returns [User::PROJECT_WILDCARD] when the user holds `kb.read.any`.
     *   Callers must treat that as "no project filter".
     * - Returns the concrete project_key set of `project_memberships` rows
     *   otherwise. Empty array means "no access to any project".
     */
    public function allowedProjects(): array
    {
        if ($this->can('kb.read.any')) {
            return [self::PROJECT_WILDCARD];
        }

        return $this->projectMemberships()
            ->pluck('project_key')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * scope_allowlist JSON for the given project_key, or empty array
     * meaning "no further restriction within that project".
     *
     * Shape: ['folder_globs' => ['a/*', 'b/**'], 'tags' => ['slug1']].
     */
    public function allowedScopesFor(string $projectKey): array
    {
        $membership = $this->projectMemberships()
            ->where('project_key', $projectKey)
            ->first();

        if ($membership === null) {
            return [];
        }

        return $membership->scope_allowlist ?? [];
    }

    /**
     * Authoritative per-document access check.
     *
     *   1. Global `kb.{permission}.any` permission → allow.
     *   2. Explicit ACL row on knowledge_document_acl:
     *        - Any matching deny  → deny.
     *        - Any matching allow (with no deny) → allow.
     *   3. Implicit: project membership + scope_allowlist match → allow.
     *   4. Otherwise deny.
     *
     * The ACL lookup is a raw DB query (not through the model) because the
     * access control check must be independent of any soft-delete scoping
     * (R2 compliance — see .claude/skills/soft-delete-aware-queries/).
     */
    public function hasDocumentAccess(KnowledgeDocument $doc, string $permission = 'view'): bool
    {
        if ($this->can("kb.{$permission}.any")) {
            return true;
        }

        $aclDecision = $this->evaluateAclDecision($doc, $permission);

        if ($aclDecision === 'deny') {
            return false;
        }

        if ($aclDecision === 'allow') {
            return true;
        }

        return $this->hasProjectAndScopeAccess($doc);
    }

    /**
     * @return 'allow'|'deny'|null  null when no ACL row matches.
     */
    private function evaluateAclDecision(KnowledgeDocument $doc, string $permission): ?string
    {
        $rows = DB::table('knowledge_document_acl')
            ->where('knowledge_document_id', $doc->getKey())
            ->where('permission', $permission)
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->where('subject_type', KnowledgeDocumentAcl::SUBJECT_USER)
                        ->where('subject_id', (string) $this->getKey());
                })->orWhere(function ($q) {
                    $roleNames = $this->getRoleNames()->all();
                    if ($roleNames === []) {
                        // whereIn([]) → always false, keep the clause safe.
                        $q->whereRaw('1=0');
                        return;
                    }
                    $q->where('subject_type', KnowledgeDocumentAcl::SUBJECT_ROLE)
                        ->whereIn('subject_id', $roleNames);
                });
            })
            ->pluck('effect');

        if ($rows->contains(KnowledgeDocumentAcl::EFFECT_DENY)) {
            return 'deny';
        }

        if ($rows->contains(KnowledgeDocumentAcl::EFFECT_ALLOW)) {
            return 'allow';
        }

        return null;
    }

    private function hasProjectAndScopeAccess(KnowledgeDocument $doc): bool
    {
        $allowed = $this->allowedProjects();

        if ($allowed === []) {
            return false;
        }

        $hasWildcard = in_array(self::PROJECT_WILDCARD, $allowed, true);

        if (! $hasWildcard && ! in_array($doc->project_key, $allowed, true)) {
            return false;
        }

        $scope = $this->allowedScopesFor($doc->project_key);

        if ($scope === []) {
            return true;
        }

        return $this->matchesScope($doc, $scope);
    }

    /**
     * @param  array{folder_globs?: array<int,string>, tags?: array<int,string>}  $scope
     */
    private function matchesScope(KnowledgeDocument $doc, array $scope): bool
    {
        $globs = $scope['folder_globs'] ?? [];
        $tags = $scope['tags'] ?? [];

        if ($globs === [] && $tags === []) {
            return true;
        }

        if ($globs !== [] && $this->matchesAnyGlob((string) $doc->source_path, $globs)) {
            return true;
        }

        if ($tags !== [] && $this->documentHasAnyTag($doc, $tags)) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<int,string>  $globs
     */
    private function matchesAnyGlob(string $path, array $globs): bool
    {
        foreach ($globs as $glob) {
            if (fnmatch($glob, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int,string>  $tagSlugs
     */
    private function documentHasAnyTag(KnowledgeDocument $doc, array $tagSlugs): bool
    {
        return DB::table('knowledge_document_tags')
            ->join('kb_tags', 'kb_tags.id', '=', 'knowledge_document_tags.kb_tag_id')
            ->where('knowledge_document_tags.knowledge_document_id', $doc->getKey())
            ->whereIn('kb_tags.slug', $tagSlugs)
            ->exists();
    }
}
