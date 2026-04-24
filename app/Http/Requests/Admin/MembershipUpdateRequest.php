<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/admin/memberships/{membership} — update role / scope_allowlist.
 *
 * project_key is intentionally NOT editable here — moving a membership
 * across projects would break the `(user_id, project_key)` unique, so the
 * admin UI deletes + re-creates instead.
 */
class MembershipUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role' => ['sometimes', 'string', Rule::in(['member', 'admin', 'owner'])],
            'scope_allowlist' => ['sometimes', 'nullable', 'array'],
            'scope_allowlist.folder_globs' => ['sometimes', 'array'],
            'scope_allowlist.folder_globs.*' => ['string', 'max:255'],
            'scope_allowlist.tags' => ['sometimes', 'array'],
            'scope_allowlist.tags.*' => ['string', 'max:120'],
        ];
    }
}
