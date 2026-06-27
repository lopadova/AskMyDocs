import { useState } from 'react';
import { toAdminError } from '../shared/errors';
import { useToast } from '../shared/Toast';
import { useCreateCampaign, useUpdateCampaign } from './use-invitations';
import {
    CAMPAIGN_STATUSES,
    CAMPAIGN_TYPES,
    type Campaign,
    type CampaignStatus,
    type CampaignType,
    type CreateCampaignPayload,
    type Tenant,
    type UpdateCampaignPayload,
} from './invitations.api';
import { Drawer, Field, drawerInput, drawerPrimaryBtn } from './Drawer';
import {
    EMPTY_GRANT_DRAFT,
    GrantEditor,
    buildGrant,
    grantHasSuperAdmin,
    grantToDraft,
    type GrantDraft,
} from './GrantEditor';

/*
 * Create / edit a campaign. `key` + `type` are immutable on edit (the package
 * update rules don't accept them), so they render read-only. The grant editor
 * lifts its draft into this form; super-admin is rejected client-side before
 * submit (the package rejects it too — fail fast). 422s surface inline per
 * field (toAdminError) with a form-level fallback.
 */

interface CampaignDrawerProps {
    campaign: Campaign | null;
    tenants: Tenant[];
    onClose: () => void;
}

const numOrNull = (raw: string): number | null => {
    if (raw.trim() === '') return null;
    const n = Number(raw);
    return Number.isFinite(n) ? n : null;
};

export function CampaignDrawer({ campaign, tenants, onClose }: CampaignDrawerProps) {
    const toast = useToast();
    const isEdit = campaign !== null;
    const create = useCreateCampaign();
    const update = useUpdateCampaign();

    const [key, setKey] = useState(campaign?.key ?? '');
    const [name, setName] = useState(campaign?.name ?? '');
    const [description, setDescription] = useState(campaign?.description ?? '');
    const [type, setType] = useState<CampaignType>(campaign?.type ?? 'single_use');
    const [status, setStatus] = useState<CampaignStatus>(campaign?.status ?? 'draft');
    const [maxRedemptions, setMaxRedemptions] = useState(campaign?.max_redemptions_total?.toString() ?? '');
    const [perUserLimit, setPerUserLimit] = useState(campaign?.per_user_limit?.toString() ?? '');
    const [startsAt, setStartsAt] = useState((campaign?.starts_at ?? '').slice(0, 10));
    const [endsAt, setEndsAt] = useState((campaign?.ends_at ?? '').slice(0, 10));
    const [grant, setGrant] = useState<GrantDraft>(campaign ? grantToDraft(campaign.grant) : EMPTY_GRANT_DRAFT);

    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const [formError, setFormError] = useState<string | null>(null);

    const pending = create.isPending || update.isPending;

    async function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        setFieldErrors({});
        setFormError(null);

        const errors: Record<string, string> = {};
        if (!isEdit) {
            if (!/^[a-z0-9-]+$/.test(key.trim())) {
                errors['key'] = 'Lowercase letters, digits and hyphens only.';
            }
        }
        if (name.trim() === '') errors['name'] = 'Name is required.';
        if (grantHasSuperAdmin(grant)) errors['grant.role'] = 'super-admin cannot be granted through an invite code.';
        if (Object.keys(errors).length > 0) {
            setFieldErrors(errors);
            return;
        }

        const grantValue = buildGrant(grant);

        try {
            if (isEdit && campaign) {
                const payload: UpdateCampaignPayload = {
                    name: name.trim(),
                    description: description.trim() || null,
                    status,
                    max_redemptions_total: numOrNull(maxRedemptions),
                    per_user_limit: numOrNull(perUserLimit) ?? undefined,
                    starts_at: startsAt || null,
                    ends_at: endsAt || null,
                    grant: grantValue ?? null,
                };
                await update.mutateAsync({ id: campaign.id, payload });
                toast.success('Campaign updated.', 'toast-campaign-updated');
            } else {
                const payload: CreateCampaignPayload = {
                    key: key.trim(),
                    name: name.trim(),
                    description: description.trim() || null,
                    type,
                    status,
                    max_redemptions_total: numOrNull(maxRedemptions),
                    per_user_limit: numOrNull(perUserLimit),
                    starts_at: startsAt || null,
                    ends_at: endsAt || null,
                    grant: grantValue ?? null,
                };
                await create.mutateAsync(payload);
                toast.success('Campaign created.', 'toast-campaign-created');
            }
            onClose();
        } catch (err) {
            const e2 = toAdminError(err);
            setFieldErrors(e2.fieldErrors);
            setFormError(e2.message);
            toast.error(e2.message, 'toast-campaign-error');
        }
    }

    return (
        <Drawer title={isEdit ? 'Edit campaign' : 'New campaign'} testid="admin-invitations-campaign-drawer" onClose={onClose}>
            <form onSubmit={handleSubmit} noValidate style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                <Field id="campaign-key" label="Key (immutable)" error={fieldErrors.key}>
                    <input
                        id="campaign-key"
                        data-testid="admin-invitations-campaign-key"
                        value={key}
                        onChange={(e) => setKey(e.target.value)}
                        readOnly={isEdit}
                        placeholder="launch-wave"
                        style={{ ...drawerInput, opacity: isEdit ? 0.6 : 1 }}
                    />
                </Field>

                <Field id="campaign-name" label="Name" error={fieldErrors.name}>
                    <input
                        id="campaign-name"
                        data-testid="admin-invitations-campaign-name"
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        style={drawerInput}
                    />
                </Field>

                <Field id="campaign-description" label="Description (optional)" error={fieldErrors.description}>
                    <textarea
                        id="campaign-description"
                        data-testid="admin-invitations-campaign-description"
                        value={description}
                        onChange={(e) => setDescription(e.target.value)}
                        rows={2}
                        style={{ ...drawerInput, resize: 'vertical' }}
                    />
                </Field>

                <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
                    <Field id="campaign-type" label="Type (immutable)" error={fieldErrors.type}>
                        <select
                            id="campaign-type"
                            data-testid="admin-invitations-campaign-type"
                            value={type}
                            onChange={(e) => setType(e.target.value as CampaignType)}
                            disabled={isEdit}
                            style={{ ...drawerInput, opacity: isEdit ? 0.6 : 1 }}
                        >
                            {CAMPAIGN_TYPES.map((t) => (
                                <option key={t} value={t}>{t}</option>
                            ))}
                        </select>
                    </Field>

                    <Field id="campaign-status" label="Status" error={fieldErrors.status}>
                        <select
                            id="campaign-status"
                            data-testid="admin-invitations-campaign-status"
                            value={status}
                            onChange={(e) => setStatus(e.target.value as CampaignStatus)}
                            style={drawerInput}
                        >
                            {CAMPAIGN_STATUSES.map((s) => (
                                <option key={s} value={s}>{s}</option>
                            ))}
                        </select>
                    </Field>
                </div>

                <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
                    <Field id="campaign-max" label="Max redemptions (optional)" error={fieldErrors.max_redemptions_total}>
                        <input
                            id="campaign-max"
                            data-testid="admin-invitations-campaign-max"
                            type="number"
                            min={1}
                            value={maxRedemptions}
                            onChange={(e) => setMaxRedemptions(e.target.value)}
                            style={drawerInput}
                        />
                    </Field>
                    <Field id="campaign-per-user" label="Per-user limit (optional)" error={fieldErrors.per_user_limit}>
                        <input
                            id="campaign-per-user"
                            data-testid="admin-invitations-campaign-per-user"
                            type="number"
                            min={1}
                            value={perUserLimit}
                            onChange={(e) => setPerUserLimit(e.target.value)}
                            style={drawerInput}
                        />
                    </Field>
                </div>

                <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
                    <Field id="campaign-starts" label="Starts at (optional)" error={fieldErrors.starts_at}>
                        <input
                            id="campaign-starts"
                            data-testid="admin-invitations-campaign-starts"
                            type="date"
                            value={startsAt}
                            onChange={(e) => setStartsAt(e.target.value)}
                            style={drawerInput}
                        />
                    </Field>
                    <Field id="campaign-ends" label="Ends at (optional)" error={fieldErrors.ends_at}>
                        <input
                            id="campaign-ends"
                            data-testid="admin-invitations-campaign-ends"
                            type="date"
                            value={endsAt}
                            onChange={(e) => setEndsAt(e.target.value)}
                            style={drawerInput}
                        />
                    </Field>
                </div>

                <GrantEditor value={grant} onChange={setGrant} tenants={tenants} />
                {fieldErrors['grant.role'] && (
                    <span data-testid="admin-invitations-campaign-grant-role-error" role="alert" style={{ fontSize: 11.5, color: 'var(--danger-fg, #f87171)' }}>
                        {fieldErrors['grant.role']}
                    </span>
                )}

                {formError && (
                    <p data-testid="admin-invitations-campaign-error" role="alert" style={{ color: 'var(--danger-fg, #f87171)', fontSize: 12.5, margin: 0 }}>
                        {formError}
                    </p>
                )}

                <button
                    type="submit"
                    data-testid="admin-invitations-campaign-submit"
                    disabled={pending}
                    style={{ ...drawerPrimaryBtn, opacity: pending ? 0.6 : 1 }}
                >
                    {pending ? 'Saving…' : isEdit ? 'Save changes' : 'Create campaign'}
                </button>
            </form>
        </Drawer>
    );
}
