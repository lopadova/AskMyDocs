import { useState, type ReactNode } from 'react';
import { Button } from '../../components/Button';
import { Icon } from '../../components/Icons';

const hierarchy = [
    { variant: 'primary' as const, label: 'Primary action', note: 'One per action group' },
    { variant: 'secondary' as const, label: 'Secondary action', note: 'Standard product action' },
    { variant: 'quiet' as const, label: 'Quiet action', note: 'Low-emphasis utility' },
    { variant: 'danger' as const, label: 'Destructive action', note: 'Irreversible operations' },
];

export function ButtonSystemDemo(): ReactNode {
    const [view, setView] = useState<'overview' | 'details' | 'activity'>('overview');

    return (
        <main className="button-demo" data-testid="button-system-demo">
            <header className="button-demo-hero">
                <span className="button-demo-eyebrow">Interface foundations · Buttons</span>
                <div className="button-demo-hero-copy">
                    <div>
                        <h1>Quiet precision, tactile response.</h1>
                        <p>
                            One disciplined action language for light and dark surfaces. The double
                            hairline gives definition; light confirms intent without competing with content.
                        </p>
                    </div>
                    <Button variant="primary" size="lg" leadingIcon={<Icon.Plus size={16} />}>
                        Create connection
                    </Button>
                </div>
            </header>

            <section className="button-demo-section" aria-labelledby="hierarchy-title">
                <DemoHeading
                    index="01"
                    title="Action hierarchy"
                    description="Use weight to express priority, never decoration alone."
                    id="hierarchy-title"
                />
                <div className="button-demo-hierarchy">
                    {hierarchy.map((item) => (
                        <div className="button-demo-specimen" key={item.variant}>
                            <Button
                                variant={item.variant}
                                leadingIcon={item.variant === 'danger'
                                    ? <Icon.Trash size={14} />
                                    : <Icon.Sparkles size={14} />}
                            >
                                {item.label}
                            </Button>
                            <span>{item.note}</span>
                        </div>
                    ))}
                </div>
            </section>

            <section className="button-demo-section" aria-labelledby="size-title">
                <DemoHeading
                    index="02"
                    title="Scale and composition"
                    description="Three sizes, predictable icon rhythm, no one-off geometry."
                    id="size-title"
                />
                <div className="button-demo-grid">
                    <DemoCard label="Sizes">
                        <div className="button-demo-row button-demo-row-baseline">
                            <Button size="sm">Compact</Button>
                            <Button size="md">Default</Button>
                            <Button size="lg">Prominent</Button>
                        </div>
                    </DemoCard>
                    <DemoCard label="Icons">
                        <div className="button-demo-row">
                            <Button leadingIcon={<Icon.Plus size={14} />}>New chat</Button>
                            <Button trailingIcon={<Icon.Chevron size={13} />}>Continue</Button>
                            <Button iconOnly aria-label="More actions"><Icon.MoreH size={14} /></Button>
                            <Button iconOnly variant="quiet" aria-label="Search"><Icon.Search size={14} /></Button>
                        </div>
                    </DemoCard>
                    <DemoCard label="Technical labels">
                        <div className="button-demo-row">
                            <Button size="sm" caps leadingIcon={<Icon.Api size={12} />}>API live</Button>
                            <Button size="sm" caps leadingIcon={<Icon.Mcp size={12} />}>MCP connected</Button>
                        </div>
                        <small>Uppercase is reserved for short status and utility labels.</small>
                    </DemoCard>
                    <DemoCard label="Grouped choice">
                        <div className="ui-button-group" aria-label="Demo view">
                            {(['overview', 'details', 'activity'] as const).map((option) => (
                                <Button
                                    key={option}
                                    variant="quiet"
                                    size="sm"
                                    aria-pressed={view === option}
                                    onClick={() => setView(option)}
                                >
                                    {option[0].toUpperCase() + option.slice(1)}
                                </Button>
                            ))}
                        </div>
                    </DemoCard>
                </div>
            </section>

            <section className="button-demo-section" aria-labelledby="states-title">
                <DemoHeading
                    index="03"
                    title="Interaction states"
                    description="Every state remains legible, stable and keyboard-visible."
                    id="states-title"
                />
                <div className="button-demo-state-grid">
                    <State label="Default"><Button>Review result</Button></State>
                    <State label="Hover"><Button data-demo-state="hover">Review result</Button></State>
                    <State label="Pressed"><Button data-demo-state="pressed">Review result</Button></State>
                    <State label="Keyboard focus"><Button data-demo-state="focus">Review result</Button></State>
                    <State label="Disabled"><Button disabled>Review result</Button></State>
                    <State label="Working"><Button busy>Connecting</Button></State>
                </div>
            </section>

            <section className="button-demo-anatomy" aria-labelledby="anatomy-title">
                <div>
                    <span className="button-demo-eyebrow">The contract</span>
                    <h2 id="anatomy-title">Nothing arbitrary.</h2>
                    <p>Every detail has a job: discoverability, hierarchy, response or accessibility.</p>
                </div>
                <ol>
                    <li><strong>Outer edge</strong><span>Separates the control from any surface.</span></li>
                    <li><strong>Inner hairline</strong><span>Adds tactile definition without gloss.</span></li>
                    <li><strong>Optical type</strong><span>Sentence case, compact weight, stable line height.</span></li>
                    <li><strong>Light response</strong><span>Hover changes luminance without moving the control.</span></li>
                </ol>
            </section>
        </main>
    );
}

function DemoHeading({ index, title, description, id }: {
    index: string;
    title: string;
    description: string;
    id: string;
}): ReactNode {
    return (
        <div className="button-demo-heading">
            <span>{index}</span>
            <div><h2 id={id}>{title}</h2><p>{description}</p></div>
        </div>
    );
}

function DemoCard({ label, children }: { label: string; children: ReactNode }): ReactNode {
    return <div className="button-demo-card"><span>{label}</span>{children}</div>;
}

function State({ label, children }: { label: string; children: ReactNode }): ReactNode {
    return <div className="button-demo-state"><span>{label}</span>{children}</div>;
}
