import { Link } from '@tanstack/react-router';
import { EditorialHero } from '../../components/editorial/EditorialHero';
import { Icon } from '../../components/Icons';
import { useAuthStore } from '../../lib/auth-store';
import { selectCurrentHash, useTeamStore } from '../../lib/team-store';
import { useUiTranslation } from '../../lib/ui-locale';
import { moduleEnabled, NAV_HUBS, navDescription, navHref, navLabel, visibleHubItems } from '../../components/shell/navigation';

export function AreaHubView({ hubId }: { hubId: string }) {
    const { t } = useUiTranslation();
    const roles = useAuthStore((state) => state.roles);
    const permissions = useAuthStore((state) => state.permissions);
    const features = useAuthStore((state) => state.features);
    const teamHash = useTeamStore(selectCurrentHash);
    const hub = NAV_HUBS.find((candidate) => candidate.id === hubId);
    const access = { roles, permissions, features, hasTenant: teamHash !== null };
    if (!hub || !visibleHubItems(hub, access, true).length) return <section className="editorial-page"><h1>{t('Available pages')}</h1><Link to="/app/me">{t('Home')}</Link></section>;
    return <section className="editorial-page" data-testid={`area-hub-${hub.id}`}>
        <EditorialHero artwork={hub.artwork} eyebrow={t(hub.area === 'system' ? 'System' : 'Administration')} title={t(hub.label)} description={t(hub.description)} />
        {hub.topics.map((topic) => {
            const items = visibleHubItems({ ...hub, topics: [topic] }, access, true);
            if (!items.length) return null;
            return <section className="hub-topic" key={topic.label}>
                <h2>{t(topic.label)}</h2><div className="hub-card-grid">
                    {items.map((item) => {
                        const ItemIcon = Icon[item.icon];
                        const enabled = moduleEnabled(item, features);
                        const content = <><span className="hub-card-icon"><ItemIcon size={22} /></span><span><strong>{t(navLabel(item))}</strong><small>{t(enabled ? navDescription(item) : 'This feature is not enabled in this installation.')}</small></span>{enabled ? <Icon.Chevron size={17} /> : <span className="module-badge">{t('Not enabled')}</span>}</>;
                        return enabled ? <Link className="hub-card" key={item.id} to={navHref(item, teamHash)} data-testid={`hub-link-${item.id}`}>{content}</Link> : <div className="hub-card is-inactive" key={item.id} aria-disabled="true" data-testid={`hub-inactive-${item.id}`}>{content}</div>;
                    })}
                </div>
            </section>;
        })}
    </section>;
}
