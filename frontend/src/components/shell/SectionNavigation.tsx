import { Link } from '@tanstack/react-router';
import { useUiTranslation } from '../../lib/ui-locale';
import type { SidebarSection } from './nav-config';
import { canAccessNav, moduleEnabled, navHref, navItem, navLabel, type NavAccess, type NavHub } from './navigation';

export function SectionNavigation({ hub, section, access, teamHash }: { hub?: NavHub; section: SidebarSection | null; access: NavAccess; teamHash: string | null }) {
    const { t } = useUiTranslation();
    if (section === 'chat' || section === 'sessions') return <nav className="conversation-modes" aria-label={t('Conversations')}>
        {(['chat', 'sessions'] as const).map((id) => <Link key={id} to={navHref(navItem(id), teamHash)} aria-current={section === id ? 'page' : undefined} title={t(id === 'chat' ? "A direct conversation, with sources and live tools." : "Keep ongoing work in folders, pin important sessions, and return to it later.")}>
            {t(navLabel(navItem(id)))}
        </Link>)}
        <span>{t(section === 'chat' ? "A direct conversation, with sources and live tools." : "Keep ongoing work in folders, pin important sessions, and return to it later.")}</span>
    </nav>;
    if (!hub || !section) return null;
    const topics = hub.topics.map((topic) => ({ ...topic, available: topic.items.map(navItem).filter((item) => canAccessNav(item, access) && moduleEnabled(item, access.features)) })).filter((topic) => topic.available.length);
    const current = topics.find((topic) => topic.items.includes(section)) ?? topics[0];
    if (!current) return null;
    return <div className="section-navigation">
        {topics.length > 1 && <nav className="section-topics" aria-label={t('Section navigation')}>
            {topics.map((topic) => <Link key={topic.label} to={navHref(topic.available[0], teamHash)} aria-current={topic.label === current.label ? 'true' : undefined}>{t(topic.label)}</Link>)}
        </nav>}
        <nav className="section-pages" aria-label={t(current.label)}>
            {current.available.map((item) => <Link key={item.id} to={navHref(item, teamHash)} data-testid={`sidebar-nav-${item.id}`} aria-current={section === item.id ? 'page' : undefined}>{t(navLabel(item))}</Link>)}
        </nav>
    </div>;
}
