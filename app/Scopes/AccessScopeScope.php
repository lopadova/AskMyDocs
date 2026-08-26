<?php

namespace App\Scopes;

use App\Models\KnowledgeDocumentAcl;
use App\Models\User;
use App\Support\ScopeAllowlistSql;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\DB;

/**
 * Global read-scope filter applied to every KnowledgeDocument query.
 *
 * Enforcement layers (cheap SQL only; see Policy for row-level scope
 * matching):
 *
 *   1. Bypass when `config('rbac.enforced')` is false.
 *   2. Bypass in unauthenticated contexts (console commands, system jobs,
 *      setUp() before actingAs()).
 *   3. Bypass for users who can read all projects (canReadAllProjects():
 *      `kb.read.any` when per-project isolation is OFF, `kb.read.all_projects`
 *      when it is ON — see config/kb.php `project_isolation.enabled`).
 *   4. Constrain project_key to the user's allowed project set, and within
 *      each project to that membership's scope_allowlist (folder_globs /
 *      tags) — see constrainByScopeAllowlist().
 *   5. Exclude rows that have a matching deny ACL row for subject=user /
 *      permission=view.
 *
 * Layer 4's allowlist arm used to live only in
 * KnowledgeDocumentPolicy::view(), on the reasoning that hot retrieval
 * paths should stay a single SELECT without joins. The reasoning held for
 * controller reads, which call the Gate; it did not hold for retrieval,
 * which resolves chunks through `whereHas('document')` and never calls the
 * policy — so the arm was simply absent where it mattered most. It is now
 * SQL (see App\Support\ScopeAllowlistSql), exact for every glob shape but
 * one, and costs a join only for memberships that actually carry a scope.
 *
 * The policy remains the authoritative per-row check and the only gate for
 * the one inexact shape (a glob mixing `**` with a single-segment `*`).
 */
class AccessScopeScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! config('rbac.enforced', true)) {
            return;
        }

        $user = auth()->user();

        if ($user === null) {
            return;
        }

        if ($user->canReadAllProjects()) {
            return;
        }

        $this->constrainByProject($builder, $model, $user);
        $this->excludeDeniedDocuments($builder, $model, $user);
    }

    private function constrainByProject(Builder $builder, Model $model, User $user): void
    {
        $allowed = $user->allowedProjects();

        if ($allowed === []) {
            $builder->whereRaw('1=0');
            return;
        }

        if (in_array(User::PROJECT_WILDCARD, $allowed, true)) {
            return;
        }

        $column = $model->qualifyColumn('project_key');

        // The allowlist is per MEMBERSHIP, so it is per project: a user may
        // hold `hr/**` in one project and no restriction in another.
        // Collapsing to one `whereIn` would apply a single project's scope
        // to all of them, so each project carries its own arm.
        $builder->where(function ($outer) use ($allowed, $column, $model, $user): void {
            foreach ($allowed as $projectKey) {
                $outer->orWhere(function ($arm) use ($projectKey, $column, $model, $user): void {
                    $arm->where($column, $projectKey);
                    $this->constrainByScopeAllowlist($arm, $model, $user, (string) $projectKey);
                });
            }
        });
    }

    /**
     * The third arm of `User::hasDocumentAccess()`, pushed into SQL.
     *
     * This used to run only in `KnowledgeDocumentPolicy::view()`, which the
     * RAG hot path never calls — so a membership scoped to `hr/policies/**`
     * still retrieved `hr/salaries/**` chunks through
     * `whereHas('document')` and handed them to the model as grounding.
     * Exactly the shape H8 fixed for role-level denies, one arm later.
     *
     * `matchesScope()` is globs OR tags — a document outside every glob is
     * still readable when it carries an allowlisted tag — so both arms are
     * OR'd. An empty allowlist means "no further restriction" and leaves
     * the query untouched, keeping the unscoped plan identical.
     */
    private function constrainByScopeAllowlist(
        mixed $builder,
        Model $model,
        User $user,
        string $projectKey,
    ): void {
        $scope = $user->allowedScopesFor($projectKey);

        $globs = $scope['folder_globs'] ?? [];
        $tags = $scope['tags'] ?? [];

        if ($globs === [] && $tags === []) {
            return;
        }

        $pathColumn = $model->qualifyColumn('source_path');
        $idColumn = $model->qualifyColumn('id');
        $tenantColumn = $model->qualifyColumn('tenant_id');

        $builder->where(function ($q) use ($globs, $tags, $pathColumn, $idColumn, $tenantColumn): void {
            foreach ($globs as $glob) {
                ScopeAllowlistSql::apply($q, $pathColumn, (string) $glob);
            }

            if ($tags !== []) {
                // Mirrors User::documentHasAnyTag(): slugs are unique only
                // per (tenant_id, project_key), so the join stays inside
                // the document's own tenant (R30).
                $q->orWhereExists(function ($sub) use ($tags, $idColumn, $tenantColumn): void {
                    $sub->from('knowledge_document_tags')
                        ->join('kb_tags', 'kb_tags.id', '=', 'knowledge_document_tags.kb_tag_id')
                        ->whereColumn('knowledge_document_tags.knowledge_document_id', $idColumn)
                        ->whereColumn('kb_tags.tenant_id', $tenantColumn)
                        ->whereIn('kb_tags.slug', $tags)
                        ->selectRaw('1');
                });
            }
        });
    }

    private function excludeDeniedDocuments(Builder $builder, Model $model, User $user): void
    {
        // H8 — the cheap global scope must mirror the policy's
        // evaluateAclDecision(): a deny applies whether the subject is the
        // USER or any ROLE the user holds. Previously only user-subject
        // denies were filtered here, so a role-level deny leaked the doc
        // into every bulk read path (search, tree) and was only caught by
        // the per-row policy check — which the hot retrieval path skips.
        $roleNames = $user->getRoleNames()->all();

        $builder->whereNotIn(
            $model->qualifyColumn('id'),
            DB::table('knowledge_document_acl')
                ->where('effect', KnowledgeDocumentAcl::EFFECT_DENY)
                ->where('permission', KnowledgeDocumentAcl::PERMISSION_VIEW)
                ->where(function ($q) use ($user, $roleNames) {
                    $q->where(function ($sub) use ($user) {
                        $sub->where('subject_type', KnowledgeDocumentAcl::SUBJECT_USER)
                            ->where('subject_id', (string) $user->getKey());
                    });

                    if ($roleNames !== []) {
                        $q->orWhere(function ($sub) use ($roleNames) {
                            $sub->where('subject_type', KnowledgeDocumentAcl::SUBJECT_ROLE)
                                ->whereIn('subject_id', $roleNames);
                        });
                    }
                })
                ->select('knowledge_document_id'),
        );
    }
}
