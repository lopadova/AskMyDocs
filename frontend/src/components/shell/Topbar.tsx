import { Fragment } from 'react';
import { PanelLeftClose, PanelLeftOpen } from 'lucide-react';
import { Link } from '@tanstack/react-router';
import { Icon } from '../Icons';
import { Button } from '../Button';
import { TeamSwitcher } from './TeamSwitcher';
import { Tooltip } from './Tooltip';
import { UserMenu } from './UserMenu';
import { LanguagePicker } from './LanguagePicker';
import { NotificationBell } from '../../features/notifications/NotificationBell';
import { useAuthStore } from '../../lib/auth-store';
import { useUiTranslation } from '../../lib/ui-locale';
import type { Team } from '../../lib/team-store';
import type { Theme } from './hooks';

export type BreadcrumbItem = string | { label: string; onClick?: () => void };
export type TopbarProps = {
    team: Team | null; teams: Team[]; onTeamChange: (team: Team) => void;
    theme: Theme; setTheme: (theme: Theme) => void; onToggleTweaks: () => void;
    crumbs?: BreadcrumbItem[]; onOpenNav?: () => void; navOpen?: boolean;
    sidebarCollapsed?: boolean; onToggleSidebar?: () => void;
};

export function Topbar({ team, teams, onTeamChange, theme, setTheme, onToggleTweaks, crumbs = [], onOpenNav, navOpen = false, sidebarCollapsed = false, onToggleSidebar }: TopbarProps) {
    const { t } = useUiTranslation();
    const roles = useAuthStore((state) => state.roles);
    const canCheckHealth = roles.some((role) => role === 'admin' || role === 'super-admin');
    return <header className="app-topbar" data-testid="app-topbar">
        <div className="app-topbar-leading">
            {onToggleSidebar && <Tooltip label={t(sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar')}><Button variant="quiet" size="sm" iconOnly className="desktop-navigation-trigger" aria-label={t(sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar')} aria-expanded={!sidebarCollapsed} aria-controls="app-desktop-navigation" onClick={onToggleSidebar}>{sidebarCollapsed ? <PanelLeftOpen size={18} /> : <PanelLeftClose size={18} />}</Button></Tooltip>}
            {onOpenNav && <Button variant="quiet" size="sm" iconOnly id="app-navigation-trigger" className="mobile-navigation-trigger" aria-label={t('Open navigation')} aria-expanded={navOpen} aria-haspopup="dialog" onClick={onOpenNav}><Icon.Menu size={20} /></Button>}
            {team !== null && teams.length > 0 && <TeamSwitcher team={team} teams={teams} onChange={onTeamChange} />}
            <nav className="app-topbar-crumbs" aria-label={t('Breadcrumb')}>
                {crumbs.map((crumb, index) => {
                    const label = typeof crumb === 'string' ? crumb : crumb.label;
                    const action = typeof crumb === 'string' ? undefined : crumb.onClick;
                    return <Fragment key={index}><Icon.Chevron size={12} />{action && index !== crumbs.length - 1 ? <button type="button" onClick={action}>{label}</button> : <span data-current={index === crumbs.length - 1 ? 'true' : 'false'}>{label}</span>}</Fragment>;
                })}
            </nav>
        </div>
        <div className="app-topbar-actions">
            {team && canCheckHealth && <Link className="app-service-link" to="/app/$teamHash/admin" params={{ teamHash: team.hash }} title={t('Service status')}><Icon.Activity size={15} /><span>{t('Service status')}</span></Link>}
            <div className="app-topbar-tools" aria-label={t('Workspace tools')}>
                <LanguagePicker />
                {team !== null && <NotificationBell />}
                <Tooltip label={t(theme === 'dark' ? "Light mode" : "Dark mode")}><Button variant="quiet" size="sm" iconOnly className="app-topbar-icon-button" aria-label={t('Toggle theme')} onClick={() => setTheme(theme === 'dark' ? 'light' : 'dark')}>{theme === 'dark' ? <Icon.Sun size={15} /> : <Icon.Moon size={15} />}</Button></Tooltip>
                <Tooltip label={t('Interface settings')}><Button variant="quiet" size="sm" iconOnly className="app-topbar-icon-button" aria-label={t('Interface settings')} onClick={onToggleTweaks}><Icon.Sliders size={15} /></Button></Tooltip>
            </div>
            <UserMenu />
        </div>
    </header>;
}
