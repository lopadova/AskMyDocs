import type { IconName } from '../Icons';

/**
 * Single source of truth for the unified admin navigation.
 *
 * Before this, the host had TWO overlapping navs — the primary `Sidebar`
 * and a secondary `AdminShell` rail — so on every `/app/admin/*` page eight
 * sections appeared twice. They are now merged into ONE grouped, collapsible
 * sidebar; `AdminShell` keeps only the content area. Both the sidebar (render)
 * and `AppShell` (routing + active-section detection) read this file, so the
 * id ↔ label ↔ icon ↔ route mapping can never drift between them.
 */

export type SidebarSection =
    | 'chat'
    | 'dashboard'
    | 'insights'
    | 'projects'
    | 'users'
    | 'roles'
    | 'kb'
    | 'collections'
    | 'synonyms'
    | 'kb-insights'
    | 'analysis-settings'
    | 'content-gaps'
    | 'wiki-health'
    | 'wiki-indices'
    | 'wiki-explorer'
    | 'autowiki-settings'
    | 'tabular-reviews'
    | 'workflows'
    | 'ai-act-compliance'
    | 'compliance-reports'
    | 'evidence-risk-review'
    | 'pii-redactor'
    | 'connectors'
    | 'flows'
    | 'eval-harness'
    | 'mcp-tools'
    | 'mcp-tokens'
    | 'widget'
    | 'logs'
    | 'maintenance'
    | 'digest'
    | 'my-kb'
    | 'engagement';

export interface NavItem {
    id: SidebarSection;
    label: string;
    icon: IconName;
    /** Absolute SPA route this entry navigates to / highlights for. */
    route: string;
}

export interface NavGroup {
    id: string;
    label: string;
    items: NavItem[];
}

export const NAV_GROUPS: NavGroup[] = [
    {
        id: 'workspace',
        label: 'Workspace',
        items: [
            { id: 'chat', label: 'Chat', icon: 'Chat', route: '/app/$teamHash/chat' },
            // v8.15/W4.2 — personal "your KB" dashboard; any authenticated user.
            { id: 'my-kb', label: 'My KB', icon: 'Sparkles', route: '/app/me' },
            // v8.15/W3.2 — per-user digest (feed + preferences); any authenticated user.
            { id: 'digest', label: 'Digest', icon: 'Calendar', route: '/app/digest' },
        ],
    },
    {
        id: 'administration',
        label: 'Administration',
        items: [
            { id: 'dashboard', label: 'Dashboard', icon: 'Grid', route: '/app/$teamHash/admin' },
            { id: 'engagement', label: 'Engagement', icon: 'Activity', route: '/app/$teamHash/admin/engagement' },
            { id: 'insights', label: 'AI Insights', icon: 'Sparkles', route: '/app/$teamHash/admin/insights' },
            { id: 'projects', label: 'Projects', icon: 'Cube', route: '/app/$teamHash/admin/projects' },
            { id: 'users', label: 'Users', icon: 'Users', route: '/app/$teamHash/admin/users' },
            { id: 'roles', 'icon': 'Shield', label: 'Roles', route: '/app/$teamHash/admin/roles' },
        ],
    },
    {
        id: 'knowledge',
        label: 'Knowledge',
        items: [
            { id: 'kb', label: 'Knowledge Base', icon: 'Book', route: '/app/$teamHash/admin/kb' },
            { id: 'collections', label: 'Collections', icon: 'Folder', route: '/app/$teamHash/admin/collections' },
            { id: 'synonyms', label: 'Synonyms', icon: 'Tag', route: '/app/$teamHash/admin/kb/synonyms' },
            { id: 'kb-insights', label: 'Doc Insights', icon: 'Eye', route: '/app/$teamHash/admin/kb/insights' },
            { id: 'analysis-settings', label: 'Analysis Gate', icon: 'Sliders', route: '/app/$teamHash/admin/kb/analysis-settings' },
            { id: 'content-gaps', label: 'Content Gaps', icon: 'Search', route: '/app/$teamHash/admin/kb/content-gaps' },
            { id: 'wiki-health', label: 'Wiki Health', icon: 'Activity', route: '/app/$teamHash/admin/kb/wiki-health' },
            { id: 'wiki-indices', label: 'Wiki Indices', icon: 'Book', route: '/app/$teamHash/admin/kb/wiki-indices' },
            { id: 'wiki-explorer', label: 'Wiki Explorer', icon: 'Folder', route: '/app/$teamHash/admin/kb/wiki-explorer' },
            { id: 'autowiki-settings', label: 'Auto-Wiki Settings', icon: 'Sliders', route: '/app/$teamHash/admin/kb/autowiki-settings' },
            { id: 'tabular-reviews', label: 'Tabular Reviews', icon: 'Grid', route: '/app/$teamHash/admin/tabular-reviews' },
            { id: 'workflows', label: 'Workflows', icon: 'Branch', route: '/app/$teamHash/admin/workflows' },
        ],
    },
    {
        id: 'compliance',
        label: 'Compliance',
        items: [
            { id: 'ai-act-compliance', label: 'AI Act', icon: 'Shield', route: '/app/$teamHash/admin/ai-act-compliance' },
            { id: 'compliance-reports', label: 'Compliance', icon: 'Check', route: '/app/$teamHash/admin/compliance/reports' },
            { id: 'evidence-risk-review', label: 'Evidence & Risk', icon: 'Alert', route: '/app/$teamHash/admin/evidence-risk-review' },
            { id: 'pii-redactor', label: 'PII Redactor', icon: 'Eye', route: '/app/$teamHash/admin/pii-redactor' },
        ],
    },
    {
        id: 'operations',
        label: 'Operations',
        items: [
            { id: 'connectors', label: 'Connectors', icon: 'Link', route: '/app/$teamHash/admin/connectors' },
            { id: 'flows', label: 'Flows', icon: 'Bolt', route: '/app/$teamHash/admin/flows' },
            { id: 'eval-harness', label: 'Eval Harness', icon: 'Brain', route: '/app/$teamHash/admin/eval-harness' },
            { id: 'mcp-tools', label: 'MCP Tools', icon: 'Terminal', route: '/app/$teamHash/admin/mcp-tools' },
            { id: 'mcp-tokens', label: 'MCP Tokens', icon: 'Command', route: '/app/$teamHash/admin/mcp/tokens' },
            { id: 'widget', label: 'Widget', icon: 'Chat', route: '/app/$teamHash/admin/widget' },
            { id: 'logs', label: 'Logs', icon: 'Activity', route: '/app/$teamHash/admin/logs' },
            { id: 'maintenance', label: 'Maintenance', icon: 'Wrench', route: '/app/$teamHash/admin/maintenance' },
        ],
    },
];

/** Flat list of every nav item, in group order. */
export const NAV_ITEMS: NavItem[] = NAV_GROUPS.flatMap((g) => g.items);

// Precomputed once: items ordered by descending route length so the
// most-specific match wins. NAV_ITEMS is static, so there is no reason to
// re-sort on every deriveSection() call.
const ORDERED_NAV_ITEMS: NavItem[] = [...NAV_ITEMS].sort((a, b) => b.route.length - a.route.length);

/** id → route, derived so it can never drift from the rendered nav. */
export const SECTION_ROUTES: Record<SidebarSection, string> = Object.fromEntries(
    NAV_ITEMS.map((i) => [i.id, i.route]),
) as Record<SidebarSection, string>;

/**
 * The section whose route is the LONGEST prefix of the current path.
 *
 * `matches(route)` should be the host router's fuzzy matcher (true when the
 * current location is `route` or a child of it). Sorting by route length
 * descending makes the most specific entry win — so `/app/admin/kb/synonyms`
 * resolves to `synonyms`, not its parent `kb`, and the bare `/app/admin`
 * resolves to `dashboard` (nothing deeper matches it).
 */
export function deriveSection(matches: (route: string) => boolean): SidebarSection | null {
    for (const item of ORDERED_NAV_ITEMS) {
        if (matches(item.route)) {
            return item.id;
        }
    }
    // No nav entry is a prefix of this route (e.g. /app/admin/notifications,
    // reached from the topbar bell) — return null so NOTHING in the sidebar
    // highlights, rather than mis-attributing it to Dashboard (longest-prefix)
    // or Chat (a hard-coded fallback). Note: routes that DO sit under a nav
    // section still resolve to it — e.g. /app/admin/kb/time-machine/$docId
    // fuzzily matches `kb` and correctly highlights Knowledge.
    return null;
}
