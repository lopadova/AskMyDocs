<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\InviteCampaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create-campaign payload. The route already enforces role:admin|super-admin,
 * so authorize() returns true.
 */
class InviteCampaignStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'type' => ['required', Rule::in(InviteCampaign::TYPES)],
            'status' => ['nullable', Rule::in(InviteCampaign::STATUSES)],
            'max_redemptions_total' => ['nullable', 'integer', 'min:1'],
            'per_user_limit' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'reward_policy' => ['nullable', 'array'],

            // Provisioning grant applied to the redeemer on a fresh claim.
            // `role` must be a real Spatie role and never super-admin (no
            // self-served super-admin via a code). Projects are free-form keys
            // (the tenant's project space is open-ended — KB ingest can create
            // new keys), validated only as non-empty strings.
            'grant' => ['nullable', 'array'],
            'grant.role' => ['nullable', 'string', 'exists:roles,name', 'not_in:super-admin'],
            'grant.projects' => ['nullable', 'array'],
            'grant.projects.*' => ['string', 'max:120'],
            'grant.project_role' => ['nullable', Rule::in(['member', 'admin', 'owner'])],
            'grant.scope_allowlist' => ['nullable', 'array'],

            // Multi-tenant form: one OR MORE per-tenant grants, so a single code
            // provisions the redeemer across several tenants ("teams") at once.
            // super-admin is rejected in every tenant grant too (no priv-esc).
            'grant.tenants' => ['nullable', 'array'],
            'grant.tenants.*.tenant_id' => ['required', 'string', 'max:50'],
            'grant.tenants.*.role' => ['nullable', 'string', 'exists:roles,name', 'not_in:super-admin'],
            'grant.tenants.*.projects' => ['nullable', 'array'],
            'grant.tenants.*.projects.*' => ['string', 'max:120'],
            'grant.tenants.*.project_role' => ['nullable', Rule::in(['member', 'admin', 'owner'])],
            'grant.tenants.*.scope_allowlist' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'grant.role.not_in' => 'super-admin cannot be granted through an invite code.',
            'grant.role.exists' => 'The selected grant role does not exist.',
            'grant.tenants.*.role.not_in' => 'super-admin cannot be granted through an invite code.',
            'grant.tenants.*.role.exists' => 'The selected grant role does not exist.',
            'grant.tenants.*.tenant_id.required' => 'Each tenant grant needs a tenant_id.',
        ];
    }
}
