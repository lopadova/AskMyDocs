import { useState } from 'react';
import { toAdminError } from '../shared/errors';
import { useToast } from '../shared/Toast';
import { useSendInvitation } from './use-invitations';
import type { InviteChannel, SentInvitation } from './invitations.api';
import { Drawer, Field, drawerInput, drawerPrimaryBtn } from './Drawer';

/*
 * Send a single targeted invitation (POST /invitations). Idempotent per
 * (tenant, recipient, context_ref) server-side. There is no list endpoint, so
 * the parent keeps a session-scoped record of what was sent (onSent callback).
 */

const CHANNELS: InviteChannel[] = ['email', 'sms', 'in_app', 'link'];

interface SendInvitationDrawerProps {
    onClose: () => void;
    onSent: (invitation: SentInvitation) => void;
}

export function SendInvitationDrawer({ onClose, onSent }: SendInvitationDrawerProps) {
    const toast = useToast();
    const send = useSendInvitation();

    const [recipient, setRecipient] = useState('');
    const [channel, setChannel] = useState<InviteChannel>('email');
    const [role, setRole] = useState('');
    const [contextRef, setContextRef] = useState('');
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const [formError, setFormError] = useState<string | null>(null);

    async function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        setFieldErrors({});
        setFormError(null);

        if (channel === 'email' && !/^\S+@\S+\.\S+$/.test(recipient.trim())) {
            setFieldErrors({ recipient: 'Enter a valid email address.' });
            return;
        }
        if (channel !== 'email' && recipient.trim() === '') {
            setFieldErrors({ recipient: 'Recipient is required.' });
            return;
        }

        try {
            const invitation = await send.mutateAsync({
                recipient: recipient.trim(),
                channel,
                role: role.trim() || null,
                context_ref: contextRef.trim() || null,
            });
            onSent(invitation);
            toast.success(`Invitation sent to ${invitation.recipient}.`, 'toast-invitation-sent');
            onClose();
        } catch (err) {
            const e2 = toAdminError(err);
            setFieldErrors(e2.fieldErrors);
            setFormError(e2.message);
            toast.error(e2.message, 'toast-invitation-error');
        }
    }

    return (
        <Drawer title="Send invitation" testid="admin-invitations-invite-drawer" onClose={onClose}>
            <form onSubmit={handleSubmit} noValidate style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                <Field
                    id="invite-recipient"
                    label={channel === 'email' ? 'Recipient email' : 'Recipient'}
                    error={fieldErrors.recipient}
                >
                    <input
                        id="invite-recipient"
                        data-testid="admin-invitations-invite-recipient"
                        type={channel === 'email' ? 'email' : 'text'}
                        value={recipient}
                        onChange={(e) => setRecipient(e.target.value)}
                        placeholder={channel === 'email' ? 'user@example.com' : 'recipient'}
                        style={drawerInput}
                    />
                </Field>

                <Field id="invite-channel" label="Channel" error={fieldErrors.channel}>
                    <select
                        id="invite-channel"
                        data-testid="admin-invitations-invite-channel"
                        value={channel}
                        onChange={(e) => setChannel(e.target.value as InviteChannel)}
                        style={drawerInput}
                    >
                        {CHANNELS.map((c) => (
                            <option key={c} value={c}>{c}</option>
                        ))}
                    </select>
                </Field>

                <Field id="invite-role" label="Role (optional)" error={fieldErrors.role}>
                    <input
                        id="invite-role"
                        data-testid="admin-invitations-invite-role"
                        value={role}
                        onChange={(e) => setRole(e.target.value)}
                        placeholder="member"
                        style={drawerInput}
                    />
                </Field>

                <Field id="invite-context" label="Context ref (optional)" error={fieldErrors.context_ref}>
                    <input
                        id="invite-context"
                        data-testid="admin-invitations-invite-context"
                        value={contextRef}
                        onChange={(e) => setContextRef(e.target.value)}
                        placeholder="onboarding"
                        style={drawerInput}
                    />
                </Field>

                {formError && (
                    <p data-testid="admin-invitations-invite-error" role="alert" style={{ color: 'var(--danger-fg, #f87171)', fontSize: 12.5, margin: 0 }}>
                        {formError}
                    </p>
                )}

                <button
                    type="submit"
                    data-testid="admin-invitations-invite-submit"
                    disabled={send.isPending}
                    style={{ ...drawerPrimaryBtn, opacity: send.isPending ? 0.6 : 1 }}
                >
                    {send.isPending ? 'Sending…' : 'Send invitation'}
                </button>
            </form>
        </Drawer>
    );
}
