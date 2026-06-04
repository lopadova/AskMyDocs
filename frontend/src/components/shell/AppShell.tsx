import { useCallback, useEffect, useMemo, useState } from 'react';
import { Outlet, useMatchRoute, useNavigate } from '@tanstack/react-router';
import { Sidebar } from './Sidebar';
import { NAV_ITEMS, SECTION_ROUTES, deriveSection, type SidebarSection } from './nav-config';
import { Topbar } from './Topbar';
import { CommandPalette } from './CommandPalette';
import { TweaksPanel } from './TweaksPanel';
import { useDensity, useFontPair, useTheme } from './hooks';
import { USERS, type Project, type SeedUser } from '../../lib/seed';
import { useAuthStore } from '../../lib/auth-store';
import { useProjectStore } from '../../lib/project-store';

// Active-section detection is centralised in nav-config.deriveSection, which
// resolves the LONGEST route prefix (so `/app/admin/kb/synonyms` → `synonyms`,
// not its parent `kb`). We feed it the router's fuzzy matcher — EXCEPT for the
// bare `/app/admin` (Dashboard) root, matched exactly. That root is a prefix of
// every admin sub-page, including `/app/admin/notifications` which has NO nav
// entry of its own; fuzzy-matching the root there would mis-highlight
// Dashboard. Exact match leaves it unhighlighted instead, while a real
// section's deeper sub-pages still resolve to their own (longer) route fuzzily
// — e.g. `/app/admin/kb/time-machine/$docId` matches `kb` (Knowledge).
function deriveSectionFromMatch(match: ReturnType<typeof useMatchRoute>): SidebarSection | null {
    return deriveSection((route) => Boolean(match({ to: route, fuzzy: route !== '/app/admin' })));
}

// The sidebar footer shows ONE role label. Pick the most privileged of the
// user's real Spatie roles (from `/api/auth/me`) so the label matches what
// the RBAC guards actually grant — e.g. an `admin` user is shown `admin`,
// not the seed-constant `super-admin` that the static USERS[0] fixture
// carried (which made the super-admin-only screens look mis-gated).
// Typed to the SeedUser role union (which now covers all five real system
// roles) so the picked value flows into the sidebar label with no cast. A
// user whose roles fall entirely outside this set yields null → the caller
// falls back to the least-privileged `viewer`; we deliberately do NOT surface
// an unknown raw role string, which is what the old `?? roles[0]` fallback +
// cast smuggled through.
const ROLE_PRIORITY: SeedUser['role'][] = ['super-admin', 'admin', 'dpo', 'editor', 'viewer'];

function pickPrimaryRole(roles: string[]): SeedUser['role'] | null {
    return ROLE_PRIORITY.find((r) => roles.includes(r)) ?? null;
}

// Deterministic accent colour per project key. `color` is the only
// ProjectSwitcher field with no backend source yet, so we derive a stable
// hue from the key (same key → same colour across reloads) instead of
// reintroducing a hard-coded per-project palette. Pure FE cosmetics.
const PROJECT_PALETTE = ['#8b5cf6', '#22d3ee', '#f97316', '#a3e635', '#e11d48', '#14b8a6', '#eab308'];

function colorForKey(key: string): string {
    let hash = 0;
    for (let i = 0; i < key.length; i++) {
        hash = (hash * 31 + key.charCodeAt(i)) >>> 0;
    }
    return PROJECT_PALETTE[hash % PROJECT_PALETTE.length];
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
    const activeProjectKey = useProjectStore((s) => s.activeProjectKey);
    const setActiveProject = useProjectStore((s) => s.setActiveProject);

    const navigate = useNavigate();
    const matchRoute = useMatchRoute();
    const section = deriveSectionFromMatch(matchRoute);
    const storeUser = useAuthStore((s) => s.user);
    const storeProjects = useAuthStore((s) => s.projects);
    const storeRoles = useAuthStore((s) => s.roles);

    const sidebarUser = storeUser
        ? {
              ...USERS[0],
              name: storeUser.name,
              email: storeUser.email,
              // Real role from the auth store, not the USERS[0] seed constant
              // (which always read `super-admin` and mislabelled every user).
              // pickPrimaryRole returns the SeedUser role union (or null); when
              // the user has no known role we fall back to the LEAST-privileged
              // `viewer` rather than the seed's `super-admin`, so an empty /
              // unrecognised roles array can never mislabel someone as an admin.
              role: pickPrimaryRole(storeRoles) ?? 'viewer',
              avatar: storeUser.name
                  .split(' ')
                  .map((part) => part[0])
                  .slice(0, 2)
                  .join('')
                  .toUpperCase(),
          }
        : USERS[0];

    // Topbar / ProjectSwitcher need the rich Project shape (key, label,
    // color, docs). When the auth store has real backend memberships,
    // map them to that shape, looking up label/color/docs from the
    // seeded PROJECTS table when available, and falling back to a
    // synthetic record (humanised key + neutral colour) otherwise.
    // Keeps `projectCount` and the switcher in lockstep — Copilot PR #33
    // flagged the previous mismatch where projectCount came from
    // storeProjects but the switcher always rendered PROJECTS.
    const projects: Project[] = useMemo(
        () =>
            storeProjects.map((sp) => ({
                key: sp.project_key,
                label: sp.label || sp.project_key,
                color: colorForKey(sp.project_key),
                docs: sp.doc_count ?? 0,
                members: 0,
            })),
        [storeProjects],
    );

    // Hydrate the active project from the first real membership once the
    // store has loaded, and self-heal if the current selection is no longer
    // in the user's project list (e.g. membership revoked).
    useEffect(() => {
        if (projects.length === 0) {
            return;
        }
        if (!projects.some((p) => p.key === activeProjectKey)) {
            setActiveProject(projects[0].key);
        }
    }, [projects, activeProjectKey, setActiveProject]);

    // Resolve the active project from the shared store; fall back to the
    // first project until the effect hydrates it, so Topbar never gets undefined.
    const activeProject = projects.find((p) => p.key === activeProjectKey) ?? projects[0];
    const projectCount = projects.length;

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
                    project={activeProject}
                    projects={projects}
                    onProjectChange={(p) => setActiveProject(p.key)}
                    theme={theme}
                    setTheme={setTheme}
                    onToggleTweaks={() => setTweaksOpen((o) => !o)}
                    crumbs={[NAV_ITEMS.find((i) => i.id === section)?.label ?? 'Admin']}
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
                section={section ?? 'chat'}
                setSection={onNav}
            />
        </div>
    );
}
