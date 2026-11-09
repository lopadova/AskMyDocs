<?php

namespace App\Http\Requests\Admin;

use App\Services\Admin\ProjectCatalogService;
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
 * project_key uses the same tenant-scoped catalogue as the admin picker,
 * including empty registered projects and legacy references.
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
            'project_key' => ['required', 'string', 'max:120', Rule::in(app(ProjectCatalogService::class)->keys())],
            'role' => ['nullable', 'string', Rule::in(['member', 'admin', 'owner'])],
            'scope_allowlist' => ['nullable', 'array'],
            'scope_allowlist.folder_globs' => ['sometimes', 'array'],
            'scope_allowlist.folder_globs.*' => ['string', 'max:255'],
            'scope_allowlist.tags' => ['sometimes', 'array'],
            'scope_allowlist.tags.*' => ['string', 'max:120'],
        ];
    }
}
