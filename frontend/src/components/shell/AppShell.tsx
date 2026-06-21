import { useCallback, useState } from 'react';
import { Outlet, useMatchRoute, useNavigate } from '@tanstack/react-router';
import { Sidebar } from './Sidebar';
import { NAV_ITEMS, SECTION_ROUTES, deriveSection, type SidebarSection } from './nav-config';
import { Topbar } from './Topbar';
import { CommandPalette } from './CommandPalette';
import { TweaksPanel } from './TweaksPanel';
import { useDensity, useFontPair, useTheme } from './hooks';
import { USERS, type SeedUser } from '../../lib/seed';
import { useAuthStore } from '../../lib/auth-store';
import { useTeamStore, type Team } from '../../lib/team-store';

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
    return deriveSection((route) =>
        Boolean(match({ to: route, fuzzy: route !== '/app/$teamHash/admin' })),
    );
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

/*
 * Root of the authenticated `/app/*` routes. Hosts the sidebar + topbar
 * + the route outlet + the floating palette + tweaks panel.
 */
export function AppShell() {
    const [theme, setTheme] = useTheme('dark');
    const [density, setDensity] = useDensity('balanced');
    const [font, setFont] = useFontPair('geist');
    const [tweaksOpen, setTweaksOpen] = useState(false);

    const navigate = useNavigate();
    const matchRoute = useMatchRoute();
    const section = deriveSectionFromMatch(matchRoute);
    const storeUser = useAuthStore((s) => s.user);
    const storeRoles = useAuthStore((s) => s.roles);
    const teams = useTeamStore((s) => s.teams);
    const currentTeam = useTeamStore((s) => s.currentTeam);

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

    // Active team object for the Topbar switcher. Before the first
    // `/api/auth/me` sync (or for a guest preview) the store is empty —
    // fall back to a synthetic `default` team so the shell never crashes
    // on an undefined team. (In practice TeamGate only mounts AppShell
    // once the URL hash resolved to a real team.)
    const activeTeam: Team = teams.find((t) => t.tenant_id === currentTeam) ?? {
        tenant_id: currentTeam ?? 'default',
        hash: '',
        name: 'Default',
        projects: [],
    };

    // Sidebar badge: projects the user can access INSIDE the active team
    // (the switcher shows the same number per team — kept in lockstep by
    // deriving both from the same Team record).
    const projectCount = activeTeam.projects.length;

    const onNav = useCallback(
        (id: SidebarSection) => {
            navigate({ to: SECTION_ROUTES[id], params: { teamHash: activeTeam.hash } });
        },
        [navigate, activeTeam.hash],
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
                    team={activeTeam}
                    teams={teams}
                    onTeamChange={(t) => {
                        // The URL is the source of truth for the active
                        // team: swap the hash segment and let TeamGate
                        // sync the store + clear the query cache. Search
                        // params are deliberately dropped — deep-link
                        // state (doc ids, filters) belongs to the
                        // previous team.
                        const rest = window.location.pathname.replace(/^\/app\/[^/]+/, '');
                        navigate({ to: `/app/${t.hash}${rest}` });
                    }}
                    theme={theme}
                    setTheme={setTheme}
                    onToggleTweaks={() => setTweaksOpen((o) => !o)}
                    crumbs={[NAV_ITEMS.find((i) => i.id === section)?.label ?? 'Admin']}
                />
                {/* Keyed on the active team: switching team remounts the
                  * whole route subtree, wiping page-local state (project
                  * pickers, tree selections, free-text filters) that
                  * would otherwise leak across tenants. Pairs with the
                  * queryClient.clear() in team-store.switchTeam. */}
                <div key={activeTeam.tenant_id} style={{ flex: 1, overflow: 'auto', display: 'flex' }}>
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
