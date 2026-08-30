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
 * The one shape it cannot express exactly — a glob mixing `**` with a
 * single-segment `*` — grants nothing rather than widening to a prefix
 * match, because there is no second gate on this path to narrow a superset.
 *
 * The policy remains the authoritative per-row check everywhere it runs.
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
        $this->excludeSourceRestrictedDocuments($builder, $model, $user);
    }

    private function constrainByProject(Builder $builder, Model $model, User $user): void
    {
        // ONE query for the whole membership set. Asking for the project list
        // and then each project's scope separately costs 1 + N queries, and
        // this scope runs on EVERY KnowledgeDocument query -- including the
        // retrieval hot path, which reaches documents through
        // `whereHas('document')`.
        $scopes = $user->allowedProjectScopes();

        if ($scopes === []) {
            $builder->whereRaw('1=0');
            return;
        }

        if (array_key_exists(User::PROJECT_WILDCARD, $scopes)) {
            return;
        }

        $column = $model->qualifyColumn('project_key');

        // The allowlist is per MEMBERSHIP, so it is per project: a user may
        // hold `hr/**` in one project and no restriction in another. Only the
        // projects that actually carry a scope need their own arm; collapsing
        // those would apply one project's scope to all of them.
        //
        // Everything else collapses back into a single `whereIn`. Emitting an
        // arm per project regardless would turn the pre-R33 plan into a long
        // OR chain for the common subject who has several memberships and no
        // allowlist at all -- the case this rule promises to leave untouched.
        [$unrestricted, $restricted] = $this->partitionByScope($scopes);

        if ($restricted === []) {
            $builder->whereIn($column, $unrestricted);
            return;
        }

        $builder->where(function ($outer) use ($unrestricted, $restricted, $column, $model): void {
            if ($unrestricted !== []) {
                $outer->orWhereIn($column, $unrestricted);
            }

            foreach ($restricted as $projectKey => $scope) {
                $outer->orWhere(function ($arm) use ($projectKey, $scope, $column, $model): void {
                    $arm->where($column, (string) $projectKey);
                    $this->constrainByScopeAllowlist($arm, $model, $scope);
                });
            }
        });
    }

    /**
     * Split the membership map into the projects that carry no scope at all
     * and the ones that do.
     *
     * @param  array<string, array<string, mixed>>  $scopes
     * @return array{0: list<string>, 1: array<string, array<string, mixed>>}
     */
    private function partitionByScope(array $scopes): array
    {
        $unrestricted = [];
        $restricted = [];

        foreach ($scopes as $projectKey => $scope) {
            $hasScope = ($scope['folder_globs'] ?? []) !== [] || ($scope['tags'] ?? []) !== [];

            if ($hasScope) {
                $restricted[(string) $projectKey] = $scope;
                continue;
            }

            $unrestricted[] = (string) $projectKey;
        }

        return [$unrestricted, $restricted];
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
     *
     * @param  array<string, mixed>  $scope  The membership's decoded
     *                                       `scope_allowlist`, resolved once
     *                                       by `User::allowedProjectScopes()`.
     */
    private function constrainByScopeAllowlist(
        mixed $builder,
        Model $model,
        array $scope,
    ): void {
        $globs = $scope['folder_globs'] ?? [];
        $tags = $scope['tags'] ?? [];

        if ($globs === [] && $tags === []) {
            return;
        }

        $pathColumn = $model->qualifyColumn('source_path');
        $idColumn = $model->qualifyColumn('id');
        $tenantColumn = $model->qualifyColumn('tenant_id');
        $projectColumn = $model->qualifyColumn('project_key');

        $builder->where(function ($q) use ($globs, $tags, $pathColumn, $idColumn, $tenantColumn, $projectColumn): void {
            foreach ($globs as $glob) {
                ScopeAllowlistSql::apply($q, $pathColumn, (string) $glob);
            }

            if ($tags !== []) {
                // Mirrors User::documentHasAnyTag(): slugs are unique only
                // per (tenant_id, project_key), so the join stays inside
                // the document's own tenant (R30).
                $q->orWhereExists(function ($sub) use ($tags, $idColumn, $tenantColumn, $projectColumn): void {
                    $sub->from('knowledge_document_tags')
                        ->join('kb_tags', 'kb_tags.id', '=', 'knowledge_document_tags.kb_tag_id')
                        ->whereColumn('knowledge_document_tags.knowledge_document_id', $idColumn)
                        // Every part of the slug's uniqueness key is
                        // correlated, or a same-named tag elsewhere satisfies
                        // the subquery: the pivot's tenant (the join reaches
                        // kb_tags through it), the tag's tenant, and the tag's
                        // project — an allowlist names slugs within its own
                        // membership's project, and the identically-named tag
                        // in a sibling project is a different tag.
                        ->whereColumn('knowledge_document_tags.tenant_id', $tenantColumn)
                        ->whereColumn('kb_tags.tenant_id', $tenantColumn)
                        ->whereColumn('kb_tags.project_key', $projectColumn)
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

    /**
     * Hide documents whose SOURCE stated who may read them, from everyone it
     * did not name (ADR 0028 phase 2, R33).
     *
     * A file shared with three people upstream used to become visible to the
     * whole project on ingest, because project membership was the only gate
     * and it is far coarser than what the source said. Once a permission list
     * has been mirrored, membership stops being sufficient on its own: the
     * reader also needs a matching allow.
     *
     * This has to live in SQL and not only in the policy. Retrieval reaches
     * chunks through `whereHas('document')` and never calls
     * KnowledgeDocumentPolicy, so an arm implemented only there would let the
     * model receive the document as grounding and cite it -- exactly the H8
     * shape, and exactly why R33 exists.
     *
     * Restriction is read from `source_acl_enforced_at` on the document, not
     * inferred from the presence of mirrored rows. A complete list naming
     * only people this application cannot place produces zero rows, and
     * treating that as "no restriction" would leave open precisely the
     * documents whose readers are least likely to be colleagues.
     *
     * Manual grants count. An operator resolving a triage entry writes an
     * ordinary ACL row, and it must be enough on its own; the subquery
     * therefore looks for ANY allow, not only a mirrored one.
     *
     * Users who can read every project never reach this code -- apply()
     * returns early for them -- so oversight roles keep working unchanged.
     */
    private function excludeSourceRestrictedDocuments(Builder $builder, Model $model, User $user): void
    {
        $roleNames = $user->getRoleNames()->all();
        $idColumn = $model->qualifyColumn('id');

        $builder->where(function ($outer) use ($model, $idColumn, $user, $roleNames) {
            // The overwhelmingly common branch, and a plain NULL check on an
            // indexed column: no source has ever spoken for this document.
            $outer->whereNull($model->qualifyColumn('source_acl_enforced_at'))
                ->orWhereExists(function ($sub) use ($idColumn, $user, $roleNames) {
                    $sub->from('knowledge_document_acl as granted')
                        ->whereColumn('granted.knowledge_document_id', $idColumn)
                        ->where('granted.permission', KnowledgeDocumentAcl::PERMISSION_VIEW)
                        ->where('granted.effect', KnowledgeDocumentAcl::EFFECT_ALLOW)
                        ->where(function ($q) use ($user, $roleNames) {
                            $q->where(function ($s) use ($user) {
                                $s->where('granted.subject_type', KnowledgeDocumentAcl::SUBJECT_USER)
                                    ->where('granted.subject_id', (string) $user->getKey());
                            });

                            if ($roleNames !== []) {
                                $q->orWhere(function ($s) use ($roleNames) {
                                    $s->where('granted.subject_type', KnowledgeDocumentAcl::SUBJECT_ROLE)
                                        ->whereIn('granted.subject_id', $roleNames);
                                });
                            }
                        })
                        ->selectRaw('1');
                });
        });
    }
}
