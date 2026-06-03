import { useState } from 'react';
import { WidgetKeysView } from './WidgetKeysView';
import { WidgetSessionsView } from './WidgetSessionsView';

type Tab = 'keys' | 'sessions';

/**
 * M6.4+M6.5 — Widget admin container with tabs for Keys and Sessions.
 * R11 testid, R15 a11y.
 */
export function WidgetAdminView() {
    const [tab, setTab] = useState<Tab>('keys');

    return (
        <div data-testid="admin-widget-view" style={{ display: 'flex', flexDirection: 'column', gap: 14, flex: 1 }}>
            <nav
                data-testid="admin-widget-tabs"
                role="tablist"
                aria-label="Widget admin sections"
                style={{ display: 'flex', gap: 0, borderBottom: '1px solid var(--hairline)' }}
            >
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
            </nav>

            <div
                id="admin-widget-panel-keys"
                role="tabpanel"
                aria-labelledby="admin-widget-tab-keys"
                hidden={tab !== 'keys'}
            >
                {tab === 'keys' && <WidgetKeysView />}
            </div>
            <div
                id="admin-widget-panel-sessions"
                role="tabpanel"
                aria-labelledby="admin-widget-tab-sessions"
                hidden={tab !== 'sessions'}
            >
                {tab === 'sessions' && <WidgetSessionsView />}
            </div>
        </div>
    );
}