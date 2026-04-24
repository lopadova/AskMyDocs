<?php

namespace App\Http\Requests\Admin;

use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/admin/users/{user}/memberships — grant a user access to a project.
 *
 * scope_allowlist shape (see project_memberships.scope_allowlist column):
 *   null                                           -> no in-project restriction
 *   { "folder_globs": [ "a/*", "b/**" ],
 *     "tags":        [ "tag-slug" ] }              -> allow-list by folder glob / tag
 *
 * project_key is validated against the distinct set on knowledge_documents
 * (withTrashed so a just-soft-deleted project doesn't disappear from the
 * admin dropdown while an in-flight doc is still on disk — R2).
 */
class MembershipStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_key' => ['required', 'string', 'max:120', Rule::in($this->knownProjectKeys())],
            'role' => ['nullable', 'string', Rule::in(['member', 'admin', 'owner'])],
            'scope_allowlist' => ['nullable', 'array'],
            'scope_allowlist.folder_globs' => ['sometimes', 'array'],
            'scope_allowlist.folder_globs.*' => ['string', 'max:255'],
            'scope_allowlist.tags' => ['sometimes', 'array'],
            'scope_allowlist.tags.*' => ['string', 'max:120'],
        ];
    }

    /**
     * @return array<int,string>
     */
    private function knownProjectKeys(): array
    {
        return KnowledgeDocument::withTrashed()
            ->whereNotNull('project_key')
            ->distinct()
            ->pluck('project_key')
            ->all();
    }
}
