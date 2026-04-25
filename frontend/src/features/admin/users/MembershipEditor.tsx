import { useState } from 'react';
import { Icon } from '../../../components/Icons';
import type { AdminMembership } from '../admin.api';
import {
    useDeleteMembership,
    useUpdateMembership,
    useUpsertMembership,
    useUserMemberships,
} from './users.api';
import { useToast } from '../shared/Toast';
import { toAdminError } from '../shared/errors';

export interface MembershipEditorProps {
    userId: number;
    projectKeys: string[];
}

/*
 * Visual editor for a user's project_memberships rows. scope_allowlist
 * is modelled in two synchronised lists: folder globs (any text input)
 * and tag chips. `null` means "no restriction" — we render that as the
 * empty-chip placeholder with a clear toggle.
 */
export function MembershipEditor({ userId, projectKeys }: MembershipEditorProps) {
    const memberships = useUserMemberships(userId);
    const upsert = useUpsertMembership(userId);
    const update = useUpdateMembership(userId);
    const del = useDeleteMembership(userId);
    const toast = useToast();

    const [adding, setAdding] = useState(false);
    const [newProjectKey, setNewProjectKey] = useState(projectKeys[0] ?? '');
    const [newRole, setNewRole] = useState<'member' | 'admin' | 'owner'>('member');

    async function handleAdd() {
        if (newProjectKey.trim() === '') return;
        try {
            await upsert.mutateAsync({
                project_key: newProjectKey,
                role: newRole,
                scope_allowlist: null,
            });
            setAdding(false);
            toast.success(`Granted access to ${newProjectKey}`, 'toast-membership-added');
        } catch (e) {
            const err = toAdminError(e);
            toast.error(err.message, 'toast-membership-error');
        }
    }

    const list = memberships.data?.data ?? [];
    const existingKeys = list.map((m) => m.project_key);
    const availableKeys = projectKeys.filter((k) => !existingKeys.includes(k));

    return (
        <div
            data-testid="membership-editor"
            data-state={memberships.isLoading ? 'loading' : 'ready'}
            style={{ display: 'flex', flexDirection: 'column', gap: 10 }}
        >
            {memberships.isLoading ? (
                <div data-testid="memberships-loading" style={muted}>
                    Loading memberships…
                </div>
            ) : list.length === 0 ? (
                <div data-testid="memberships-empty" style={muted}>
                    No project memberships yet.
                </div>
            ) : (
                list.map((m) => (
                    <MembershipCard
                        key={m.id}
                        membership={m}
                        onRoleChange={(role) =>
                            update
                                .mutateAsync({ id: m.id, input: { role } })
                                .then(() => toast.success(`Role updated on ${m.project_key}`))
                                .catch((err) => toast.error(toAdminError(err).message))
                        }
                        onScopeChange={(scope) =>
                            update
                                .mutateAsync({ id: m.id, input: { scope_allowlist: scope } })
                                .then(() => toast.success(`Scope updated on ${m.project_key}`))
                                .catch((err) => toast.error(toAdminError(err).message))
                        }
                        onDelete={() =>
                            del
                                .mutateAsync(m.id)
                                .then(() => toast.success(`Removed from ${m.project_key}`))
                                .catch((err) => toast.error(toAdminError(err).message))
                        }
                    />
                ))
            )}

            {adding ? (
                <div
                    data-testid="membership-add-row"
                    style={{
                        padding: 10,
                        border: '1px dashed var(--hairline)',
                        borderRadius: 8,
                        display: 'flex',
                        gap: 8,
                        alignItems: 'center',
                    }}
                >
                    <select
                        data-testid="membership-add-project"
                        value={newProjectKey}
                        onChange={(e) => setNewProjectKey(e.target.value)}
                        style={selectStyle}
                    >
                        {availableKeys.map((k) => (
                            <option key={k} value={k}>
                                {k}
                            </option>
                        ))}
                    </select>
                    <select
                        data-testid="membership-add-role"
                        value={newRole}
                        onChange={(e) =>
                            setNewRole(e.target.value as 'member' | 'admin' | 'owner')
                        }
                        style={selectStyle}
                    >
                        <option value="member">member</option>
                        <option value="admin">admin</option>
                        <option value="owner">owner</option>
                    </select>
                    <button
                        type="button"
                        className="focus-ring"
                        data-testid="membership-add-save"
                        onClick={handleAdd}
                        disabled={availableKeys.length === 0 || upsert.isPending}
                        style={smallPrimary}
                    >
                        Add
                    </button>
                    <button
                        type="button"
                        className="focus-ring"
                        data-testid="membership-add-cancel"
                        onClick={() => setAdding(false)}
                        style={smallSecondary}
                    >
                        Cancel
                    </button>
                </div>
            ) : (
                <button
                    type="button"
                    className="focus-ring"
                    data-testid="membership-add"
                    onClick={() => setAdding(true)}
                    disabled={availableKeys.length === 0}
                    style={{
                        ...smallSecondary,
                        alignSelf: 'flex-start',
                        opacity: availableKeys.length === 0 ? 0.5 : 1,
                    }}
                >
                    <Icon.Plus size={13} /> Add membership
                </button>
            )}
        </div>
    );
}

interface MembershipCardProps {
    membership: AdminMembership;
    onRoleChange: (role: 'member' | 'admin' | 'owner') => void;
    onScopeChange: (scope: AdminMembership['scope_allowlist']) => void;
    onDelete: () => void;
}

function MembershipCard({
    membership,
    onRoleChange,
    onScopeChange,
    onDelete,
}: MembershipCardProps) {
    const scope = membership.scope_allowlist;
    const folderGlobs = scope?.folder_globs ?? [];
    const tags = scope?.tags ?? [];

    const [newGlob, setNewGlob] = useState('');
    const [newTag, setNewTag] = useState('');

    function updateScope(next: AdminMembership['scope_allowlist']) {
        onScopeChange(next);
    }

    function removeGlob(glob: string) {
        const nextGlobs = folderGlobs.filter((g) => g !== glob);
        updateScope(
            nextGlobs.length === 0 && tags.length === 0
                ? null
                : { folder_globs: nextGlobs, tags },
        );
    }

    function addGlob() {
        if (newGlob.trim() === '') return;
        const nextGlobs = [...folderGlobs, newGlob.trim()];
        updateScope({ folder_globs: nextGlobs, tags });
        setNewGlob('');
    }

    function removeTag(tag: string) {
        const nextTags = tags.filter((t) => t !== tag);
        updateScope(
            folderGlobs.length === 0 && nextTags.length === 0
                ? null
                : { folder_globs: folderGlobs, tags: nextTags },
        );
    }

    function addTag() {
        if (newTag.trim() === '') return;
        const nextTags = [...tags, newTag.trim()];
        updateScope({ folder_globs: folderGlobs, tags: nextTags });
        setNewTag('');
    }

    return (
        <div
            data-testid={`membership-${membership.project_key}`}
            style={{
                padding: 12,
                border: '1px solid var(--hairline)',
                borderRadius: 8,
                background: 'var(--bg-0)',
                display: 'flex',
                flexDirection: 'column',
                gap: 10,
            }}
        >
            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <strong style={{ fontSize: 13, color: 'var(--fg-0)' }}>
                    {membership.project_key}
                </strong>
                <select
                    data-testid={`membership-${membership.project_key}-role`}
                    value={membership.role}
                    onChange={(e) =>
                        onRoleChange(e.target.value as 'member' | 'admin' | 'owner')
                    }
                    style={selectStyle}
                >
                    <option value="member">member</option>
                    <option value="admin">admin</option>
                    <option value="owner">owner</option>
                </select>
                <div style={{ flex: 1 }} />
                <button
                    type="button"
                    className="focus-ring"
                    data-testid={`membership-${membership.project_key}-delete`}
                    onClick={onDelete}
                    title="Remove membership"
                    style={{ ...smallSecondary, color: '#fca5a5' }}
                >
                    <Icon.Trash size={13} /> Remove
                </button>
            </div>

            <div>
                <div style={miniLabel}>Folder globs</div>
                <div
                    data-testid={`membership-${membership.project_key}-globs`}
                    style={{ display: 'flex', gap: 6, flexWrap: 'wrap', alignItems: 'center' }}
                >
                    {folderGlobs.length === 0 ? (
                        <span style={{ fontSize: 12, color: 'var(--fg-3)' }}>
                            (no restriction)
                        </span>
                    ) : (
                        folderGlobs.map((g) => (
                            <Chip
                                key={g}
                                testid={`membership-${membership.project_key}-glob-${g}`}
                                label={g}
                                onRemove={() => removeGlob(g)}
                            />
                        ))
                    )}
                    <input
                        data-testid={`membership-${membership.project_key}-glob-input`}
                        value={newGlob}
                        placeholder="folder/**"
                        onChange={(e) => setNewGlob(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                addGlob();
                            }
                        }}
                        style={{ ...inputStyle, maxWidth: 160 }}
                    />
                    <button
                        type="button"
                        className="focus-ring"
                        data-testid={`membership-${membership.project_key}-glob-add`}
                        onClick={addGlob}
                        style={smallSecondary}
                    >
                        +
                    </button>
                </div>
            </div>

            <div>
                <div style={miniLabel}>Tags</div>
                <div
                    data-testid={`membership-${membership.project_key}-tags`}
                    style={{ display: 'flex', gap: 6, flexWrap: 'wrap', alignItems: 'center' }}
                >
                    {tags.length === 0 ? (
                        <span style={{ fontSize: 12, color: 'var(--fg-3)' }}>
                            (no tag filter)
                        </span>
                    ) : (
                        tags.map((t) => (
                            <Chip
                                key={t}
                                testid={`membership-${membership.project_key}-tag-${t}`}
                                label={t}
                                onRemove={() => removeTag(t)}
                            />
                        ))
                    )}
                    <input
                        data-testid={`membership-${membership.project_key}-tag-input`}
                        value={newTag}
                        placeholder="tag-slug"
                        onChange={(e) => setNewTag(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                addTag();
                            }
                        }}
                        style={{ ...inputStyle, maxWidth: 140 }}
                    />
                    <button
                        type="button"
                        className="focus-ring"
                        data-testid={`membership-${membership.project_key}-tag-add`}
                        onClick={addTag}
                        style={smallSecondary}
                    >
                        +
                    </button>
                </div>
            </div>
        </div>
    );
}

function Chip({
    label,
    onRemove,
    testid,
}: {
    label: string;
    onRemove: () => void;
    testid: string;
}) {
    return (
        <span
            data-testid={testid}
            style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: 4,
                padding: '3px 6px 3px 10px',
                fontSize: 12,
                background: 'var(--grad-accent-soft)',
                borderRadius: 999,
                color: 'var(--fg-1)',
            }}
        >
            {label}
            <button
                type="button"
                className="focus-ring"
                data-testid={`${testid}-remove`}
                onClick={onRemove}
                style={{
                    background: 'transparent',
                    border: 'none',
                    color: 'var(--fg-3)',
                    cursor: 'pointer',
                    display: 'inline-flex',
                    alignItems: 'center',
                    padding: 2,
                }}
            >
                <Icon.Close size={10} />
            </button>
        </span>
    );
}

const muted = {
    fontSize: 13,
    color: 'var(--fg-3)',
    padding: '18px 10px',
    textAlign: 'center' as const,
};

const miniLabel = {
    fontSize: 11,
    color: 'var(--fg-3)',
    fontFamily: 'var(--font-mono)',
    textTransform: 'uppercase' as const,
    letterSpacing: '0.05em',
    marginBottom: 4,
};

const selectStyle = {
    padding: '5px 8px',
    fontSize: 12,
    background: 'var(--bg-0)',
    border: '1px solid var(--hairline)',
    borderRadius: 6,
    color: 'var(--fg-1)',
};

const inputStyle = {
    padding: '5px 8px',
    fontSize: 12,
    background: 'var(--bg-0)',
    border: '1px solid var(--hairline)',
    borderRadius: 6,
    color: 'var(--fg-0)',
};

const smallPrimary = {
    padding: '5px 10px',
    fontSize: 12,
    background: 'var(--grad-accent)',
    color: '#fff',
    border: '1px solid transparent',
    borderRadius: 6,
    cursor: 'pointer',
};

const smallSecondary = {
    display: 'inline-flex',
    alignItems: 'center',
    gap: 4,
    padding: '5px 10px',
    fontSize: 12,
    background: 'transparent',
    color: 'var(--fg-2)',
    border: '1px solid var(--hairline)',
    borderRadius: 6,
    cursor: 'pointer',
};
