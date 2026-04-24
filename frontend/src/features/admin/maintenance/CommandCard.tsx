import type { CatalogueEntry } from './maintenance.api';

export interface CommandCardProps {
    command: string;
    spec: CatalogueEntry;
    onRun: () => void;
}

/*
 * Single command tile in the maintenance grid. Clicking "Run" opens
 * the CommandWizard; destructive commands are visually flagged.
 */
export function CommandCard({ command, spec, onRun }: CommandCardProps) {
    return (
        <div
            data-testid={`maintenance-card-${command}`}
            data-destructive={spec.destructive ? 'true' : 'false'}
            style={{
                border: '1px solid var(--hairline)',
                borderLeft: spec.destructive ? '3px solid var(--danger-fg, #b91c1c)' : '1px solid var(--hairline)',
                borderRadius: 8,
                padding: '12px 14px',
                display: 'flex',
                flexDirection: 'column',
                gap: 8,
                background: 'var(--bg-1)',
            }}
        >
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                <code
                    style={{
                        fontSize: 12.5,
                        fontFamily: 'var(--font-mono)',
                        color: 'var(--fg-0)',
                        fontWeight: 600,
                    }}
                >
                    {command}
                </code>
                {spec.destructive ? (
                    <span
                        style={{
                            fontSize: 10,
                            padding: '2px 6px',
                            borderRadius: 3,
                            background: 'var(--danger-bg, #fee)',
                            color: 'var(--danger-fg, #b91c1c)',
                            textTransform: 'uppercase',
                            letterSpacing: '0.05em',
                        }}
                    >
                        destructive
                    </span>
                ) : null}
            </div>
            <p
                style={{
                    margin: 0,
                    fontSize: 12,
                    color: 'var(--fg-3)',
                    lineHeight: 1.4,
                    minHeight: 34,
                }}
            >
                {spec.description}
            </p>
            <div>
                <button
                    type="button"
                    data-testid={`maintenance-card-${command}-run`}
                    onClick={onRun}
                    style={{
                        padding: '5px 12px',
                        fontSize: 12,
                        background: 'var(--bg-0)',
                        border: '1px solid var(--hairline)',
                        borderRadius: 6,
                        cursor: 'pointer',
                    }}
                >
                    Run…
                </button>
            </div>
        </div>
    );
}
