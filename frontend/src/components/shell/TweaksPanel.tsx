import type { ReactNode } from 'react';
import { Icon } from '../Icons';
import { SegmentedControl } from './SegmentedControl';
import type { Density, FontPair, Theme } from './hooks';
import type { SidebarSection } from './Sidebar';

export type TweaksPanelProps = {
    open: boolean;
    onClose: () => void;
    theme: Theme;
    setTheme: (t: Theme) => void;
    density: Density;
    setDensity: (d: Density) => void;
    font: FontPair;
    setFont: (f: FontPair) => void;
    section: SidebarSection;
    setSection: (s: SidebarSection) => void;
};

function TweakRow({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <div
                style={{
                    fontSize: 10.5,
                    color: 'var(--fg-3)',
                    textTransform: 'uppercase',
                    letterSpacing: '.08em',
                    fontFamily: 'var(--font-mono)',
                    marginBottom: 6,
                }}
            >
                {label}
            </div>
            {children}
        </div>
    );
}

export function TweaksPanel({
    open,
    onClose,
    theme,
    setTheme,
    density,
    setDensity,
    font,
    setFont,
    section,
    setSection,
}: TweaksPanelProps) {
    if (!open) {
        return null;
    }
    return (
        <div
            role="dialog"
            aria-label="Tweaks panel"
            style={{
                position: 'fixed',
                right: 16,
                top: 72,
                zIndex: 500,
                width: 300,
            }}
            className="panel popin"
        >
            <div
                style={{
                    padding: '12px 14px',
                    display: 'flex',
                    alignItems: 'center',
                    borderBottom: '1px solid var(--hairline)',
                }}
            >
                <Icon.Sliders size={14} />
                <span style={{ marginLeft: 8, fontSize: 13, fontWeight: 500 }}>Tweaks</span>
                <span style={{ flex: 1 }} />
                <button type="button" className="btn icon sm ghost" aria-label="Close tweaks" onClick={onClose}>
                    <Icon.Close size={13} />
                </button>
            </div>
            <div style={{ padding: 14, display: 'flex', flexDirection: 'column', gap: 14 }}>
                <TweakRow label="Theme">
                    <SegmentedControl<Theme>
                        options={[
                            { v: 'dark', l: 'Dark' },
                            { v: 'light', l: 'Light' },
                        ]}
                        value={theme}
                        onChange={setTheme}
                    />
                </TweakRow>
                <TweakRow label="Density">
                    <SegmentedControl<Density>
                        options={[
                            { v: 'compact', l: 'Compact' },
                            { v: 'balanced', l: 'Balanced' },
                            { v: 'comfortable', l: 'Comfort' },
                        ]}
                        value={density}
                        onChange={setDensity}
                    />
                </TweakRow>
                <TweakRow label="Typography">
                    <SegmentedControl<FontPair>
                        small
                        options={[
                            { v: 'geist', l: 'Geist' },
                            { v: 'inter', l: 'Inter' },
                            { v: 'plex', l: 'Plex' },
                        ]}
                        value={font}
                        onChange={setFont}
                    />
                </TweakRow>
                <TweakRow label="Active section">
                    <select
                        className="input"
                        value={section}
                        onChange={(e) => setSection(e.target.value as SidebarSection)}
                        aria-label="Active section"
                        style={{ height: 30, fontSize: 12 }}
                    >
                        <option value="chat">Chat</option>
                        <option value="dashboard">Admin Dashboard</option>
                        <option value="kb">Knowledge Base</option>
                        <option value="insights">AI Insights</option>
                        <option value="users">Users &amp; Roles</option>
                        <option value="logs">Logs</option>
                        <option value="maintenance">Maintenance</option>
                    </select>
                </TweakRow>
            </div>
        </div>
    );
}
