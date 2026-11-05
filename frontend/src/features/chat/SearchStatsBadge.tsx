import { type ReactNode } from 'react';

/**
 * Compact badge showing how many knowledge-base searches and MCP/API tool
 * calls an agent turn actually ran ("livello di approfondimento" made
 * observable — see AgentResultProjector::searchStats() on the BE).
 *
 * Absent (renders null) on any message that never went through the agent
 * pipeline — legacy sync/stream turns carry no `search_stats` at all, and
 * the absence of data is itself information, same rationale as
 * ConfidenceBadge's legacy-row guard.
 *
 * R11 — `data-testid="search-stats-badge"`. R15 — `role="status"` +
 * a full `aria-label` sentence for a screen-reader user; the visible text
 * stays terse ("3 searches · 2 calls").
 */

export interface SearchStats {
    kb_searches: number;
    tool_calls: number;
}

export interface SearchStatsBadgeProps {
    stats: SearchStats | null | undefined;
}

export function SearchStatsBadge({ stats }: SearchStatsBadgeProps): ReactNode {
    if (stats == null || !Number.isFinite(stats.kb_searches) || stats.kb_searches < 0) {
        return null;
    }

    const kbSearches = Math.round(stats.kb_searches);
    const toolCalls = Number.isFinite(stats.tool_calls) ? Math.max(0, Math.round(stats.tool_calls)) : 0;

    const visible = [`${kbSearches} search${kbSearches === 1 ? '' : 'es'}`];
    if (toolCalls > 0) {
        visible.push(`${toolCalls} tool call${toolCalls === 1 ? '' : 's'}`);
    }
    const label = visible.join(' · ');

    return (
        <span
            data-testid="search-stats-badge"
            role="status"
            aria-label={`Investigation: ${label}`}
            style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: 4,
                padding: '2px 7px',
                fontSize: 10.5,
                fontFamily: 'var(--font-mono, monospace)',
                lineHeight: 1.4,
                color: 'var(--fg-2, #6b6b76)',
                background: 'var(--bg-3, rgba(120,120,135,.12))',
                border: '1px solid var(--panel-border, rgba(120,120,135,.35))',
                borderRadius: 99,
                whiteSpace: 'nowrap',
            }}
        >
            <span aria-hidden="true">🔍</span>
            {label}
        </span>
    );
}
