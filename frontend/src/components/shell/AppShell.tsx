import { useCallback, useState } from 'react';
import { Outlet, useMatchRoute, useNavigate } from '@tanstack/react-router';
import { Sidebar, type SidebarSection } from './Sidebar';
import { Topbar } from './Topbar';
import { CommandPalette } from './CommandPalette';
import { TweaksPanel } from './TweaksPanel';
import { useDensity, useFontPair, useTheme } from './hooks';
import { PROJECTS, USERS } from '../../lib/seed';
import { useAuthStore } from '../../lib/auth-store';

const SECTION_ROUTES: Record<SidebarSection, string> = {
    chat: '/app/chat',
    dashboard: '/app/dashboard',
    kb: '/app/kb',
    insights: '/app/insights',
    users: '/app/users',
    logs: '/app/logs',
    maintenance: '/app/maintenance',
};

function deriveSectionFromMatch(match: ReturnType<typeof useMatchRoute>): SidebarSection {
    const entries = Object.entries(SECTION_ROUTES) as [SidebarSection, string][];
    for (const [section, path] of entries) {
        if (match({ to: path, fuzzy: true })) {
            return section;
        }
    }
    return 'chat';
}

/*
 * Root of the authenticated `/app/*` routes. Hosts the sidebar + topbar
 * + the route outlet + the floating palette + tweaks panel.
 */
export function AppShell() {
    const [theme, setTheme] = useTheme('dark');
    const [density, setDensity] = useDensity('balanced');
    const [font, setFont] = useFontPair('geist');
    const [tweaksOpen, setTweaksOpen] = useState(false);
    const [projectIndex, setProjectIndex] = useState(0);

    const navigate = useNavigate();
    const matchRoute = useMatchRoute();
    const section = deriveSectionFromMatch(matchRoute);
    const storeUser = useAuthStore((s) => s.user);
    const storeProjects = useAuthStore((s) => s.projects);

    const sidebarUser = storeUser
        ? {
              ...USERS[0],
              name: storeUser.name,
              email: storeUser.email,
              avatar: storeUser.name
                  .split(' ')
                  .map((part) => part[0])
                  .slice(0, 2)
                  .join('')
                  .toUpperCase(),
          }
        : USERS[0];

    const projectCount = storeProjects.length > 0 ? storeProjects.length : PROJECTS.length;

    const onNav = useCallback(
        (id: SidebarSection) => {
            navigate({ to: SECTION_ROUTES[id] });
        },
        [navigate],
    );

    return (
        <div
            data-testid="appshell-root"
            data-section={section}
            style={{
                display: 'flex',
                height: '100vh',
                overflow: 'hidden',
                background: 'var(--bg-0)',
                color: 'var(--fg-1)',
                fontFamily: 'var(--font-sans)',
            }}
        >
            <Sidebar active={section} onNav={onNav} user={sidebarUser} projectCount={projectCount} />
            <main style={{ flex: 1, display: 'flex', flexDirection: 'column', overflow: 'hidden' }}>
                <Topbar
                    project={PROJECTS[projectIndex]}
                    projects={PROJECTS}
                    onProjectChange={(p) => {
                        const idx = PROJECTS.findIndex((pp) => pp.key === p.key);
                        if (idx >= 0) {
                            setProjectIndex(idx);
                        }
                    }}
                    theme={theme}
                    setTheme={setTheme}
                    onToggleTweaks={() => setTweaksOpen((o) => !o)}
                    crumbs={[section.charAt(0).toUpperCase() + section.slice(1)]}
                />
                <div style={{ flex: 1, overflow: 'auto', display: 'flex' }}>
                    <Outlet />
                </div>
            </main>
            <CommandPalette />
            <TweaksPanel
                open={tweaksOpen}
                onClose={() => setTweaksOpen(false)}
                theme={theme}
                setTheme={setTheme}
                density={density}
                setDensity={setDensity}
                font={font}
                setFont={setFont}
                section={section}
                setSection={onNav}
            />
        </div>
    );
}
