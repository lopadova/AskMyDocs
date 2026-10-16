import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { Outlet, useMatchRoute, useNavigate, useRouterState } from '@tanstack/react-router';
import * as Dialog from '@radix-ui/react-dialog';
import { Sidebar } from './Sidebar';
import { NAV_ITEMS, SECTION_ROUTES, deriveSection, type SidebarSection } from './nav-config';
import { areaForSection, hubForSection, hubHref, NAV_AREAS, NAV_HUBS, navLabel, moduleEnabled, navItem, visibleHubItems, type NavArea, type NavHub } from './navigation';
import { Topbar, type BreadcrumbItem } from './Topbar';
import { CommandPalette } from './CommandPalette';
import { SectionNavigation } from './SectionNavigation';
import { TweaksPanel } from './TweaksPanel';
import { useDensity, useFontPair, useSidebarCollapsed, useTheme } from './hooks';
import { USERS, type SeedUser } from '../../lib/seed';
import { useAuthStore } from '../../lib/auth-store';
import { useTeamStore } from '../../lib/team-store';
import { useUiTranslation } from '../../lib/ui-locale';
import { EditorialArtwork } from '../editorial/EditorialArtwork';

const ROLE_PRIORITY: SeedUser['role'][] = ['system-admin', 'super-admin', 'admin', 'dpo', 'editor', 'viewer'];

export function AppShell({ children, tenantScoped = true }: { children?: ReactNode; tenantScoped?: boolean } = {}) {
    const { t, locale } = useUiTranslation();
    const [theme, setTheme] = useTheme('dark');
    const [density, setDensity] = useDensity('balanced');
    const [font, setFont] = useFontPair('geist');
    const [tweaksOpen, setTweaksOpen] = useState(false);
    const [navOpen, setNavOpen] = useState(false);
    const [sidebarCollapsed, setSidebarCollapsed] = useSidebarCollapsed();
    const navigate = useNavigate();
    const match = useMatchRoute();
    const pathname = useRouterState({ select: (state) => state.location.pathname });
    const section = deriveSection((route) => Boolean(match({ to: route, fuzzy: route !== '/app/$teamHash/admin' })));
    const user = useAuthStore((state) => state.user);
    const roles = useAuthStore((state) => state.roles);
    const permissions = useAuthStore((state) => state.permissions);
    const features = useAuthStore((state) => state.features);
    const teams = useTeamStore((state) => state.teams);
    const currentTeam = useTeamStore((state) => state.currentTeam);
    const activeTeam = teams.find((team) => team.tenant_id === currentTeam) ?? null;
    const hubRoute = NAV_HUBS.find((hub) => pathname.endsWith('/area/' + hub.id));
    const hub = hubRoute ?? hubForSection(section);
    const area = hub?.area ?? areaForSection(section);
    const access = { roles, permissions, features, hasTenant: activeTeam !== null };
    const sidebarUser = {
        ...USERS[0], name: user?.name ?? '', email: user?.email ?? '',
        role: ROLE_PRIORITY.find((role) => roles.includes(role)) ?? 'viewer',
        avatar: user?.name.split(' ').map((part) => part[0]).slice(0, 2).join('').toUpperCase() ?? '',
    };
    useEffect(() => { document.documentElement.lang = locale; }, [locale]);
    useEffect(() => { setNavOpen(false); }, [pathname]);
    useEffect(() => {
        const media = window.matchMedia?.('(min-width: 961px)');
        if (!media) return;
        const update = () => { if (media.matches) setNavOpen(false); };
        media.addEventListener('change', update);
        return () => media.removeEventListener('change', update);
    }, []);

    const onNav = useCallback((id: SidebarSection) => {
        const route = SECTION_ROUTES[id];
        if (route.includes('$teamHash')) {
            if (activeTeam === null) return;
            void navigate({ to: route, params: { teamHash: activeTeam.hash } });
        } else { void navigate({ to: route }); }
        setNavOpen(false);
    }, [navigate, activeTeam]);
    const onHub = (target: NavHub) => {
        if (activeTeam) void navigate({ to: hubHref(target, activeTeam.hash) });
        else {
            const first = visibleHubItems(target, access, true)[0];
            if (first) onNav(first.id);
        }
        setNavOpen(false);
    };
    const onAreaChange = (target: NavArea) => {
        if (target === 'workspace') { onNav('my-kb'); return; }
        const first = NAV_HUBS.find((candidate) => candidate.area === target && visibleHubItems(candidate, access, true).length);
        if (first) onHub(first);
    };
    const item = NAV_ITEMS.find((candidate) => candidate.id === section);
    const crumbs: BreadcrumbItem[] = [{ label: t(NAV_AREAS.find((candidate) => candidate.id === area)!.label), onClick: () => onAreaChange(area) }];
    if (hub) crumbs.push({ label: t(hub.label), onClick: () => onHub(hub) });
    if (item && !hubRoute) crumbs.push({ label: t(navLabel(item)) });
    const sidebarProps = { active: section, onNav, user: sidebarUser, projectCount: activeTeam?.projects.length ?? 0,
        features, hasTenants: activeTeam !== null, roles, permissions, teamHash: activeTeam?.hash ?? null,
        area, activeHubId: hub?.id, onHub, onAreaChange };

    const inactive = section !== null && !moduleEnabled(navItem(section), features);
    return <div className="editorial-app" data-testid="appshell-root" data-section={section} data-area={area}>
        <a className="skip-navigation" href="#main-content">{t('Skip to content')}</a>
        <div className="desktop-sidebar" id="app-desktop-navigation" hidden={sidebarCollapsed}>{!sidebarCollapsed && <Sidebar {...sidebarProps} />}</div>
        <Dialog.Root open={navOpen} onOpenChange={setNavOpen}>
            <Dialog.Portal><Dialog.Overlay className="navigation-overlay" /><Dialog.Content className="navigation-drawer" onCloseAutoFocus={(event) => { event.preventDefault(); document.getElementById('app-navigation-trigger')?.focus(); }}>
                <Dialog.Title className="sr-only">{t('Primary navigation')}</Dialog.Title>
                <Dialog.Description className="sr-only">{t('Workspace area')}</Dialog.Description>
                <Sidebar {...sidebarProps} onClose={() => setNavOpen(false)} />
            </Dialog.Content></Dialog.Portal>
        </Dialog.Root>
        <div className="editorial-app-main">
            <Topbar team={activeTeam} teams={teams} onTeamChange={(target) => {
                if (!tenantScoped || !pathname.startsWith('/app/' + activeTeam?.hash + '/')) {
                    useTeamStore.getState().switchTeam(target.tenant_id);
                    return;
                }
                const rest = pathname.replace(/^\/app\/[^/]+/, '');
                void navigate({ to: '/app/' + target.hash + rest });
            }} theme={theme} setTheme={setTheme} onToggleTweaks={() => setTweaksOpen((open) => !open)}
                crumbs={crumbs} onOpenNav={() => setNavOpen(true)} navOpen={navOpen}
                sidebarCollapsed={sidebarCollapsed} onToggleSidebar={() => setSidebarCollapsed(!sidebarCollapsed)} />
            {!hubRoute && <SectionNavigation hub={hub} section={section} access={access} teamHash={activeTeam?.hash ?? null} />}
            <main className="editorial-app-content" tabIndex={-1} key={tenantScoped ? activeTeam?.tenant_id ?? 'no-tenant' : 'personal'} id="main-content">
                {inactive ? <section className="feature-unavailable editorial-surface" role="status"><EditorialArtwork artwork={hub?.artwork ?? 'trust'} className="editorial-surface-art" resolution="small" /><h1>{item && t(navLabel(item))}</h1><p>{t('This feature is not enabled in this installation.')}</p></section> : children ?? <Outlet />}
            </main>
        </div>
        <CommandPalette />
        <TweaksPanel open={tweaksOpen} onClose={() => setTweaksOpen(false)} theme={theme} setTheme={setTheme} density={density} setDensity={setDensity}
            font={font} setFont={setFont} section={section ?? 'my-kb'} setSection={onNav} userRole={sidebarUser.role} />
    </div>;
}
