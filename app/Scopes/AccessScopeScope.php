<?php

namespace App\Scopes;

use App\Models\KnowledgeDocumentAcl;
use App\Models\User;
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
 *   4. Constrain project_key to the user's allowed project set.
 *   5. Exclude rows that have a matching deny ACL row for subject=user /
 *      permission=view.
 *
 * The scope does NOT enforce scope_allowlist folder_globs / tags — that
 * is done by KnowledgeDocumentPolicy::view() so hot retrieval paths stay
 * a single SELECT without joins. For bulk listing endpoints that must
 * honour scope filters, paginate + filter in PHP using the policy.
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

        $builder->whereIn($model->qualifyColumn('project_key'), $allowed);
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
