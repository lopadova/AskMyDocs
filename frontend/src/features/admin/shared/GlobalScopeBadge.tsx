/*
 * Small "global" chip for admin surfaces whose data is deployment-wide
 * BY DESIGN and therefore does NOT change with the topbar team switcher
 * (Spatie roles, PII strategy config, application log tail, queue
 * tables). Rendering it next to the page/tab title tells the operator
 * the absence of team filtering is intentional, not a wiring bug —
 * see .claude/skills/team-scope-wiring (checklist point 6).
 */
export function GlobalScopeBadge({ testId }: { testId: string }) {
    return (
        <span
            data-testid={testId}
            title="Deployment-wide data — not filtered by the selected team"
            style={{
                marginLeft: 6,
                padding: '1px 6px',
                fontSize: 9.5,
                fontFamily: 'var(--font-mono)',
                textTransform: 'uppercase',
                letterSpacing: '0.05em',
                color: 'var(--fg-3)',
                border: '1px solid var(--hairline)',
                borderRadius: 999,
                verticalAlign: 'middle',
            }}
        >
            global
        </span>
    );
}
