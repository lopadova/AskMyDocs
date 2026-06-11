import { useState } from 'react';
import { useAuthStore } from '../../../lib/auth-store';
import { WidgetKeysView } from './WidgetKeysView';
import { WidgetSessionsView } from './WidgetSessionsView';
import { WidgetIntegrationGuideView } from './WidgetIntegrationGuideView';

type Tab = 'keys' | 'sessions' | 'guide';

/**
 * M6.4+M6.5 — Widget admin container with tabs for Keys, Sessions and the
 * integration guide (the DOM-annotation hand-off command).
 * R11 testid, R15 a11y.
 *
 * #31 — la gestione chiavi (Keys/Integration) è super-admin; la sola
 * ispezione Sessions è ammessa anche ad `admin` (allineato al gate BE
 * viewWidgetSessions). Un admin vede SOLO la tab Sessions.
 */
export function WidgetAdminView() {
    const roles = useAuthStore((s) => s.roles);
    const isSuperAdmin = roles.includes('super-admin');
    const [tab, setTab] = useState<Tab>(isSuperAdmin ? 'keys' : 'sessions');

    return (
        <div data-testid="admin-widget-view" style={{ display: 'flex', flexDirection: 'column', gap: 14, flex: 1 }}>
            <nav
                data-testid="admin-widget-tabs"
                role="tablist"
                aria-label="Widget admin sections"
                style={{ display: 'flex', gap: 0, borderBottom: '1px solid var(--hairline)' }}
            >
                {isSuperAdmin && (
                    <button
                        data-testid="admin-widget-tab-keys"
                        role="tab"
                        aria-selected={tab === 'keys'}
                        aria-controls="admin-widget-panel-keys"
                        onClick={() => setTab('keys')}
                        style={{
                            padding: '8px 16px',
                            border: 'none',
                            borderBottom: tab === 'keys' ? '2px solid var(--color-brand, #4f46e5)' : '2px solid transparent',
                            background: 'transparent',
                            color: tab === 'keys' ? 'var(--fg-1)' : 'var(--fg-2)',
                            cursor: 'pointer',
                            fontWeight: tab === 'keys' ? 600 : 400,
                        }}
                    >
                        Widget Keys
                    </button>
                )}
                <button
                    data-testid="admin-widget-tab-sessions"
                    role="tab"
                    aria-selected={tab === 'sessions'}
                    aria-controls="admin-widget-panel-sessions"
                    onClick={() => setTab('sessions')}
                    style={{
                        padding: '8px 16px',
                        border: 'none',
                        borderBottom: tab === 'sessions' ? '2px solid var(--color-brand, #4f46e5)' : '2px solid transparent',
                        background: 'transparent',
                        color: tab === 'sessions' ? 'var(--fg-1)' : 'var(--fg-2)',
                        cursor: 'pointer',
                        fontWeight: tab === 'sessions' ? 600 : 400,
                    }}
                >
                    Sessions
                </button>
                {isSuperAdmin && (
                    <button
                        data-testid="admin-widget-tab-guide"
                        role="tab"
                        aria-selected={tab === 'guide'}
                        aria-controls="admin-widget-panel-guide"
                        onClick={() => setTab('guide')}
                        style={{
                            padding: '8px 16px',
                            border: 'none',
                            borderBottom: tab === 'guide' ? '2px solid var(--color-brand, #4f46e5)' : '2px solid transparent',
                            background: 'transparent',
                            color: tab === 'guide' ? 'var(--fg-1)' : 'var(--fg-2)',
                            cursor: 'pointer',
                            fontWeight: tab === 'guide' ? 600 : 400,
                        }}
                    >
                        Integration
                    </button>
                )}
            </nav>

            {isSuperAdmin && (
                <div
                    id="admin-widget-panel-keys"
                    role="tabpanel"
                    aria-labelledby="admin-widget-tab-keys"
                    hidden={tab !== 'keys'}
                >
                    {tab === 'keys' && <WidgetKeysView />}
                </div>
            )}
            <div
                id="admin-widget-panel-sessions"
                role="tabpanel"
                aria-labelledby="admin-widget-tab-sessions"
                hidden={tab !== 'sessions'}
            >
                {tab === 'sessions' && <WidgetSessionsView />}
            </div>
            {isSuperAdmin && (
                <div
                    id="admin-widget-panel-guide"
                    role="tabpanel"
                    aria-labelledby="admin-widget-tab-guide"
                    hidden={tab !== 'guide'}
                >
                    {tab === 'guide' && <WidgetIntegrationGuideView />}
                </div>
            )}
        </div>
    );
}