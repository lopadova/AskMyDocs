import { NAV_ITEMS, type NavItem, type SidebarSection } from './nav-config';
import type { AuthFeatures } from '../../lib/auth-store';
import type { IconName } from '../Icons';

export type NavArea = 'workspace' | 'admin' | 'system';
export type Artwork = 'knowledge' | 'dialogue' | 'archive' | 'connections' | 'processes' | 'community' | 'trust' | 'observatory';
export type HubId = 'overview' | 'knowledge' | 'integrations' | 'automation' | 'organisation' | 'governance' | 'operations' | 'system';
export type NavAccess = { roles: string[]; permissions?: string[]; features: AuthFeatures; hasTenant: boolean };
export type NavTopic = { label: string; items: SidebarSection[] };
export type NavHub = { id: HubId; area: NavArea; label: string; description: string; icon: IconName; artwork: Artwork; topics: NavTopic[] };

export const NAV_AREAS: { id: NavArea; label: string }[] = [
    { id: 'workspace', label: 'My workspace' }, { id: 'admin', label: 'Administration' }, { id: 'system', label: 'System' },
];
export const WORKSPACE_ITEMS: SidebarSection[] = ['my-kb', 'chat', 'kb-browse', 'digest', 'connected-apps'];
export const NAV_HUBS: NavHub[] = [
    { id: 'overview', area: 'admin', label: 'Overview', description: 'See what needs attention and choose your next step.', icon: 'Grid', artwork: 'observatory', topics: [{ label: 'Overview', items: ['dashboard', 'engagement'] }] },
    { id: 'knowledge', area: 'admin', label: 'Knowledge', description: 'A place for everything your team knows.', icon: 'Book', artwork: 'archive', topics: [
        { label: 'Documents', items: ['kb', 'collections'] },
        { label: 'Quality', items: ['insights', 'kb-insights', 'content-gaps'] },
        { label: 'Wiki', items: ['wiki-explorer', 'wiki-health', 'wiki-indices'] },
        { label: 'Settings', items: ['synonyms', 'retrieval-profiles', 'analysis-settings', 'autowiki-settings', 'source-acl'] },
    ] },
    { id: 'integrations', area: 'admin', label: 'Integrations', description: 'Connect sources and follow their synchronisation.', icon: 'Link', artwork: 'connections', topics: [{ label: 'Integrations', items: ['connectors', 'api-connectors', 'ingestion'] }] },
    { id: 'automation', area: 'admin', label: 'Automation', description: 'Turn shared knowledge into repeatable work.', icon: 'Branch', artwork: 'processes', topics: [{ label: 'Automation', items: ['workflows', 'tabular-reviews', 'flows'] }] },
    { id: 'organisation', area: 'admin', label: 'People and projects', description: 'Bring people, access, and projects together.', icon: 'Users', artwork: 'community', topics: [{ label: 'People and projects', items: ['projects', 'teams', 'users', 'roles', 'invitations'] }] },
    { id: 'governance', area: 'admin', label: 'Governance', description: 'Keep oversight of privacy, evidence, and compliance.', icon: 'Shield', artwork: 'trust', topics: [{ label: 'Governance', items: ['ai-act-compliance', 'compliance-reports', 'evidence-risk-review', 'pii-redactor'] }] },
    { id: 'operations', area: 'admin', label: 'Operations', description: 'Observe services and keep your workspace running.', icon: 'Activity', artwork: 'observatory', topics: [{ label: 'Operations', items: ['eval-harness', 'widget', 'logs', 'maintenance'] }] },
    { id: 'system', area: 'system', label: 'System', description: 'Manage the platform and its shared configuration.', icon: 'Sliders', artwork: 'trust', topics: [{ label: 'System', items: ['tenant-control', 'super-admins', 'appearance', 'app-settings', 'mcp-tools', 'mcp-tokens'] }] },
];

const PERSONAL = new Set<SidebarSection>(['my-kb', 'digest', 'connected-apps']);
const WORKSPACE = new Set<SidebarSection>([...WORKSPACE_ITEMS, 'sessions']);
const SUPER_ONLY = new Set<SidebarSection>(['mcp-tools', 'mcp-tokens', 'app-settings', 'appearance']);
const DPO = new Set<SidebarSection>(['ai-act-compliance', 'evidence-risk-review', 'pii-redactor', 'flows']);
const VIEWER_READ = new Set<SidebarSection>(['tabular-reviews', 'workflows']);
export const NAV_LABELS: Partial<Record<SidebarSection, string>> = { 'my-kb': 'Home', chat: 'Quick chat', sessions: 'Organised sessions', 'kb-browse': 'Library', digest: 'Updates', 'connected-apps': 'Personal apps' };

export function navLabel(item: NavItem): string { return NAV_LABELS[item.id] ?? item.label; }
export const NAV_DESCRIPTIONS: Partial<Record<SidebarSection, string>> = {
    dashboard: 'Usage, recent activity, and measured service health.', engagement: 'Participation and the impact of shared knowledge.',
    kb: 'Read, organise, and maintain your source documents.', collections: 'Group documents around a topic or a shared purpose.',
    insights: 'Find documents to improve, classify, or promote.', 'kb-insights': 'Review suggestions after a document changes.', 'content-gaps': 'Turn unanswered questions into useful knowledge.',
    'wiki-explorer': 'Explore pages and their connections.', 'wiki-health': 'Find broken links, isolated pages, and missing indices.', 'wiki-indices': 'View and rebuild the map of your wiki.',
    synonyms: 'Connect specialist terms and their everyday equivalents.', 'retrieval-profiles': 'Define the vocabulary and context of your organisation.',
    'analysis-settings': 'Choose which changes receive deeper analysis.', 'autowiki-settings': 'Choose how the wiki is compiled for each project.', 'source-acl': 'Review access inherited from connected sources.',
    connectors: 'Connect document sources and external tools.', 'api-connectors': 'Configure and test live API tools.', ingestion: 'Follow imports, queues, and synchronisation runs.',
    workflows: 'Reuse shared procedures and suggested ways of working.', 'tabular-reviews': 'Compare documents in a structured review table.', flows: 'Follow automated runs and approvals.',
    projects: 'Organise knowledge into projects.', teams: 'Manage your organisation and its identity.', users: 'Manage accounts and project memberships.', roles: 'Review shared roles and their permissions.', invitations: 'Invite people and follow their access journey.',
    'ai-act-compliance': 'Consult oversight and compliance registers.', 'compliance-reports': 'Generate and verify quarterly evidence reports.', 'evidence-risk-review': 'Review evidence, risks, and decisions.', 'pii-redactor': 'Inspect personal-data protection and its audit trail.',
    'eval-harness': 'Evaluate answer quality and compare runs.', widget: 'Manage embedded assistants and their sessions.', logs: 'Inspect activity, errors, and operational events.', maintenance: 'Run approved maintenance and review its history.',
    'tenant-control': 'Manage organisations across the platform.', 'super-admins': 'Review platform administrators and their associations.', appearance: 'Choose the platform’s visual identity.', 'app-settings': 'Manage shared runtime configuration.', 'mcp-tools': 'Manage MCP servers and audit tool calls.', 'mcp-tokens': 'Manage external MCP access tokens.',
};
export function navDescription(item: NavItem): string { return NAV_DESCRIPTIONS[item.id] ?? ''; }
export function navItem(id: SidebarSection): NavItem { return NAV_ITEMS.find((item) => item.id === id)!; }
export function hubForSection(section: SidebarSection | null): NavHub | undefined {
    return NAV_HUBS.find((hub) => hub.topics.some((topic) => topic.items.includes(section as SidebarSection)));
}
export function areaForSection(section: SidebarSection | null): NavArea {
    return section && WORKSPACE.has(section) ? 'workspace' : hubForSection(section)?.area ?? 'workspace';
}
/** Mirror route admission, using ALL roles, never the decorative primary-role label. Laravel remains authoritative. */
export function canAccessNav(item: NavItem, access: NavAccess): boolean {
    if (item.route.includes('$teamHash') && !access.hasTenant) return false;
    if (item.feature === 'system_admin') return access.features.system_admin === true && (access.permissions === undefined || access.permissions.includes('platform.admin'));
    if (PERSONAL.has(item.id) || WORKSPACE.has(item.id)) return true;
    if (SUPER_ONLY.has(item.id)) return access.roles.includes('super-admin');
    const allowed = ['admin', 'super-admin'];
    if (DPO.has(item.id) || item.id === 'eval-harness') allowed.push('dpo');
    if (VIEWER_READ.has(item.id)) allowed.push('viewer');
    if (item.id === 'eval-harness') allowed.push('editor');
    return allowed.some((role) => access.roles.includes(role));
}
export function moduleEnabled(item: NavItem, features: AuthFeatures): boolean {
    return features.modules?.[item.id]?.enabled !== false;
}
export function visibleHubItems(hub: NavHub, access: NavAccess, includeInactive = false): NavItem[] {
    return hub.topics.flatMap((topic) => topic.items.map(navItem)).filter((item) => canAccessNav(item, access) && (includeInactive || moduleEnabled(item, access.features)));
}
export function navHref(item: NavItem, teamHash: string | null): string {
    return item.route.replace('$teamHash', teamHash ?? '');
}
export function hubHref(hub: NavHub, teamHash: string): string { return `/app/${teamHash}/area/${hub.id}`; }

export function searchNavigation(query: string, access: NavAccess, translate: (text: string) => string): NavItem[] {
    const normalize = (value: string) => value.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();
    const terms = normalize(query).trim().split(/\s+/).filter(Boolean);
    return NAV_ITEMS.filter((item) => canAccessNav(item, access) && moduleEnabled(item, access.features)).filter((item) => {
        const hub = hubForSection(item.id);
        const topic = hub?.topics.find((candidate) => candidate.items.includes(item.id));
        const haystack = normalize([item.label, navLabel(item), translate(navLabel(item)), navDescription(item), translate(navDescription(item)), hub?.label, hub && translate(hub.label), topic?.label, topic && translate(topic.label), item.id].join(' '));
        return terms.every((term) => haystack.includes(term));
    });
}
