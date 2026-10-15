import type { MouseEvent } from 'react';
import { Icon } from '../Icons';
import { Button } from '../Button';
import { Avatar } from './Avatar';
import type { SidebarSection } from './nav-config';
import type { SeedUser } from '../../lib/seed';
import type { AuthFeatures } from '../../lib/auth-store';
import { useUiTranslation } from '../../lib/ui-locale';
import { areaForSection, canAccessNav, hubForSection, hubHref, NAV_AREAS, NAV_HUBS, navHref, navItem, navLabel, visibleHubItems, WORKSPACE_ITEMS, type HubId, type NavArea, type NavHub } from './navigation';

export type { SidebarSection } from './nav-config';
export type SidebarProps = {
    active: SidebarSection | null; onNav: (id: SidebarSection) => void; collapsed?: boolean;
    user: SeedUser; projectCount: number; features?: AuthFeatures; hasTenants?: boolean;
    roles?: string[]; permissions?: string[]; teamHash?: string | null; area?: NavArea;
    activeHubId?: HubId; onHub?: (hub: NavHub) => void; onAreaChange?: (area: NavArea) => void; onClose?: () => void;
};

function activate(event: MouseEvent<HTMLAnchorElement>, action: () => void) {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault(); action();
}

export function Sidebar({ active, onNav, user, projectCount, features = {}, hasTenants = true, roles, permissions,
    teamHash = null, area, activeHubId, onHub, onAreaChange, onClose }: SidebarProps) {
    const { t } = useUiTranslation();
    const access = { roles: roles ?? [user.role], permissions, features, hasTenant: hasTenants };
    const currentArea = area ?? areaForSection(active);
    const currentHub = activeHubId ?? hubForSection(active)?.id;
    const accessibleHubs = NAV_HUBS.filter((hub) => visibleHubItems(hub, access, true).length > 0);
    const availableAreas = NAV_AREAS.filter((candidate) => candidate.id === 'workspace' || accessibleHubs.some((hub) => hub.area === candidate.id));
    const workspace = WORKSPACE_ITEMS.map(navItem).filter((item) => canAccessNav(item, access));

    return <aside aria-label={t('Primary navigation')} data-testid="sidebar-nav" className="editorial-sidebar">
        <div className="editorial-brand"><Icon.Logo size={27} /><span>AskMyDocs<span className="editorial-brand-dot">.</span></span>
            {onClose && <Button variant="quiet" size="sm" iconOnly aria-label={t('Close navigation')} className="sidebar-mobile-close" onClick={onClose}><Icon.Close size={18} /></Button>}
        </div>
        <label className="sidebar-area-label"><span className="sr-only">{t('Workspace area')}</span>
            <select aria-label={t('Workspace area')} data-testid="sidebar-area-switcher" value={currentArea} onChange={(event) => onAreaChange?.(event.target.value as NavArea)}>
                {availableAreas.map((candidate) => <option key={candidate.id} value={candidate.id}>{t(candidate.label)}</option>)}
            </select>
        </label>
        <Button variant="quiet" className="sidebar-search" size="md" data-testid="sidebar-command-palette" aria-label={t('Open command palette')} onClick={() => window.dispatchEvent(new CustomEvent('amd:palette'))}>
            <Icon.Search size={17} /><span>{t('Search…')}</span><kbd>⌘K</kbd>
        </Button>
        <nav className="editorial-sidebar-items">
            <p className="editorial-eyebrow">{t(NAV_AREAS.find((candidate) => candidate.id === currentArea)?.label ?? 'My workspace')}</p>
            {currentArea === 'workspace' && workspace.map((item) => {
                const ItemIcon = Icon[item.icon];
                const selected = active === item.id || (item.id === 'chat' && active === 'sessions');
                return <a key={item.id} href={navHref(item, teamHash)} data-testid={`sidebar-nav-${item.id}`} aria-current={selected ? 'page' : undefined} onClick={(event) => activate(event, () => onNav(item.id))}>
                    <ItemIcon size={18} /><span>{t(item.id === 'chat' ? "Conversations" : navLabel(item))}</span>
                </a>;
            })}
            {currentArea !== 'workspace' && accessibleHubs.filter((hub) => hub.area === currentArea).map((hub) => {
                const HubIcon = Icon[hub.icon];
                const first = visibleHubItems(hub, access, true)[0];
                const href = teamHash ? hubHref(hub, teamHash) : navHref(first, teamHash);
                return <a key={hub.id} href={href} data-testid={`sidebar-hub-${hub.id}`} aria-current={currentHub === hub.id ? 'page' : undefined} onClick={(event) => activate(event, () => onHub ? onHub(hub) : onNav(first.id))}>
                    <HubIcon size={18} /><span>{t(hub.label)}</span>
                </a>;
            })}
        </nav>
        <div className="editorial-sidebar-context"><Icon.Book size={15} /><span>{projectCount} {projectCount === 1 ? t('Project').toLowerCase() : t('Projects').toLowerCase()}</span></div>
        <div className="editorial-sidebar-user"><Avatar user={user} size={32} /><div><strong>{user.name}</strong><span>{user.role}</span></div></div>
    </aside>;
}
