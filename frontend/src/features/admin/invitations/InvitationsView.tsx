import { useEffect, useMemo, useState } from 'react';
import { Icon } from '../../../components/Icons';
import { useAuthStore } from '../../../lib/auth-store';
import { AdminShell } from '../shell/AdminShell';
import { ToastHost } from '../shared/Toast';
import { OverviewTab } from './OverviewTab';
import { CampaignsTab } from './CampaignsTab';
import { CodesTab } from './CodesTab';
import { InviteTab } from './InviteTab';
import { ReferralsTab } from './ReferralsTab';
import { RewardsTab } from './RewardsTab';
import { WaitlistTab } from './WaitlistTab';
import { AntiAbuseTab } from './AntiAbuseTab';

/*
 * Native Invitations admin — a unified, in-app surface over the core
 * `padosoft/laravel-invitations` API (`/api/admin/invitations/*`). Replaces
 * the old "3 KPIs + open the standalone panel" landing with native tabs that
 * match the rest of the admin chrome (theme, team switcher, no new tab):
 * Overview · Codes · Referrals · Rewards · Waitlist · Anti-abuse.
 *
 * The self-contained package panel (campaign editor + grant editor, the parts
 * not yet ported natively) is still reachable as an "Advanced" launcher — but
 * ONLY when its mount is actually enabled. The host learns that from the
 * server-truthful `features.invitations_admin` flag delivered on /api/auth/me;
 * when the package mount is OFF the link is hidden so it never points at the
 * unregistered /admin/invitations 404 (R14/R43 — both states are safe).
 *
 * Active tab is deep-linkable via `?tab=` (LogsView precedent).
 */

const PANEL_URL = '/admin/invitations';

type InvTab = 'overview' | 'campaigns' | 'codes' | 'invite' | 'referrals' | 'rewards' | 'waitlist' | 'abuse';

const TABS: Array<{ id: InvTab; label: string }> = [
    { id: 'overview', label: 'Overview' },
    { id: 'campaigns', label: 'Campaigns' },
    { id: 'codes', label: 'Codes' },
    { id: 'invite', label: 'Invite' },
    { id: 'referrals', label: 'Referrals' },
    { id: 'rewards', label: 'Rewards' },
    { id: 'waitlist', label: 'Waitlist' },
    { id: 'abuse', label: 'Anti-abuse' },
];

const VALID_TABS: InvTab[] = TABS.map((t) => t.id);

function parseInitialTab(): InvTab {
    if (typeof window === 'undefined') return 'overview';
    const raw = new URLSearchParams(window.location.search).get('tab');
    return (VALID_TABS as string[]).includes(raw ?? '') ? (raw as InvTab) : 'overview';
}

function syncTabUrl(tab: InvTab) {
    if (typeof window === 'undefined') return;
    const params = new URLSearchParams(window.location.search);
    params.set('tab', tab);
    window.history.replaceState(null, '', `${window.location.pathname}?${params.toString()}`);
}

export function InvitationsView() {
    const initial = useMemo(parseInitialTab, []);
    const [tab, setTab] = useState<InvTab>(initial);
    const panelEnabled = useAuthStore((s) => s.features.invitations_admin === true);

    useEffect(() => {
        syncTabUrl(tab);
    }, [tab]);

    return (
        <AdminShell section="invitations">
            <ToastHost />
            <div
                data-testid="admin-invitations"
                style={{ display: 'flex', flexDirection: 'column', gap: 14, minHeight: 0, height: '100%' }}
            >
                <header style={{ display: 'flex', alignItems: 'flex-start', gap: 12 }}>
                    <div>
                        <h1 style={{ fontSize: 20, fontWeight: 600, margin: '0 0 2px', letterSpacing: '-0.02em', color: 'var(--fg-0)' }}>
                            Invitations
                        </h1>
                        <p style={{ fontSize: 12.5, color: 'var(--fg-3)', margin: 0 }}>
                            Invite funnel, code inventory, referrals, rewards, waitlist and anti-abuse — read from the
                            core invitations API, scoped to the active team.
                        </p>
                    </div>
                    <span style={{ flex: 1 }} />
                    {panelEnabled && (
                        <a
                            href={PANEL_URL}
                            target="_blank"
                            rel="noopener noreferrer"
                            data-testid="admin-invitations-open-panel"
                            className="focus-ring"
                            style={{
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: 6,
                                fontSize: 12,
                                fontWeight: 600,
                                color: 'var(--fg-1)',
                                textDecoration: 'none',
                                padding: '6px 12px',
                                borderRadius: 8,
                                border: '1px solid var(--panel-border, rgba(255,255,255,.15))',
                                whiteSpace: 'nowrap',
                            }}
                        >
                            Advanced panel
                            <Icon.Share size={12} />
                        </a>
                    )}
                </header>

                <div
                    role="tablist"
                    aria-label="Invitations sections"
                    style={{ display: 'flex', gap: 4, borderBottom: '1px solid var(--hairline, rgba(255,255,255,.08))', flexWrap: 'wrap' }}
                >
                    {TABS.map((entry) => {
                        const active = entry.id === tab;
                        return (
                            <button
                                key={entry.id}
                                type="button"
                                role="tab"
                                aria-selected={active}
                                aria-controls="admin-invitations-panel"
                                data-testid={`admin-invitations-tab-${entry.id}`}
                                data-active={active ? 'true' : 'false'}
                                onClick={() => setTab(entry.id)}
                                style={{
                                    padding: '8px 14px',
                                    fontSize: 13,
                                    background: 'transparent',
                                    color: active ? 'var(--fg-0)' : 'var(--fg-2)',
                                    border: 'none',
                                    borderBottom: active ? '2px solid var(--accent, #3b82f6)' : '2px solid transparent',
                                    cursor: 'pointer',
                                    fontWeight: active ? 600 : 400,
                                }}
                            >
                                {entry.label}
                            </button>
                        );
                    })}
                </div>

                <div
                    id="admin-invitations-panel"
                    role="tabpanel"
                    data-testid={`admin-invitations-panel-${tab}`}
                    style={{ flex: 1, minHeight: 0, overflow: 'auto' }}
                >
                    {tab === 'overview' && <OverviewTab />}
                    {tab === 'campaigns' && <CampaignsTab />}
                    {tab === 'codes' && <CodesTab />}
                    {tab === 'invite' && <InviteTab />}
                    {tab === 'referrals' && <ReferralsTab />}
                    {tab === 'rewards' && <RewardsTab />}
                    {tab === 'waitlist' && <WaitlistTab />}
                    {tab === 'abuse' && <AntiAbuseTab />}
                </div>
            </div>
        </AdminShell>
    );
}
