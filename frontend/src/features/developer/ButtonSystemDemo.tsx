import { useState, type ReactNode } from 'react';
import { Button } from '../../components/Button';
import { Icon } from '../../components/Icons';
import { Alert, AlertDescription, AlertIcon, AlertTitle } from '../../components/ui/alert';

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
                <span className="button-demo-eyebrow">Super-admin · UI Foundations</span>
                <div className="button-demo-hero-copy">
                    <div>
                        <h1>A quiet system for every surface.</h1>
                        <p>
                            Review application chrome, typography, controls and interaction states here
                            before applying visual changes across the product.
                        </p>
                    </div>
                    <Button variant="primary" size="lg" leadingIcon={<Icon.Plus size={16} />}>
                        Create connection
                    </Button>
                </div>
            </header>

            <section className="button-demo-section" aria-labelledby="chrome-title">
                <DemoHeading
                    index="01"
                    title="Application chrome"
                    description="Context, system health, tools and identity breathe as separate groups."
                    id="chrome-title"
                />
                <div className="ui-foundations-chrome-preview">
                    <div className="ui-foundations-chrome-leading">
                        <div className="ui-foundations-context">
                            <span aria-hidden="true" />
                            <strong>Date</strong>
                        </div>
                        <div className="ui-foundations-crumb">
                            <Icon.Chevron size={12} />
                            <span>Chat</span>
                        </div>
                    </div>
                    <div className="ui-foundations-chrome-actions">
                        <div className="app-topbar-status">
                            <span className="pulse-dot" aria-hidden="true" />
                            <span>All systems operational</span>
                        </div>
                        <div className="app-topbar-tools">
                            <Button variant="quiet" size="sm" iconOnly className="app-topbar-icon-button notification-bell-trigger" aria-label="Notifications demo">
                                <Icon.Bell size={15} />
                                <span className="notification-bell-badge" aria-hidden="true">3</span>
                            </Button>
                            <Button variant="quiet" size="sm" iconOnly className="app-topbar-icon-button" aria-label="Theme demo"><Icon.Moon size={15} /></Button>
                            <Button variant="quiet" size="sm" iconOnly className="app-topbar-icon-button" aria-label="Settings demo"><Icon.Sliders size={15} /></Button>
                        </div>
                        <div className="ui-foundations-user"><span>DS</span><strong>Demo Super-Admin</strong><Icon.ChevronDown size={12} /></div>
                    </div>
                </div>
            </section>

            <section className="button-demo-section" aria-labelledby="hierarchy-title">
                <DemoHeading
                    index="02"
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
                    index="03"
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
                    index="04"
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

            <section className="button-demo-section" aria-labelledby="foundations-title">
                <DemoHeading
                    index="05"
                    title="Core foundations"
                    description="Type, fields, status and surfaces share the same optical rhythm."
                    id="foundations-title"
                />
                <div className="ui-foundations-grid">
                    <DemoCard label="Typography">
                        <div className="ui-foundations-type">
                            <strong>Interface heading</strong>
                            <p>Readable product copy with a quiet supporting hierarchy.</p>
                            <code>metadata · 12:42 PM</code>
                        </div>
                    </DemoCard>
                    <DemoCard label="Form field">
                        <label className="ui-foundations-field">
                            <span>Connection name</span>
                            <input className="input" value="Production MCP" readOnly />
                            <small>Use a name your team will recognise.</small>
                        </label>
                    </DemoCard>
                    <DemoCard label="Status language">
                        <div className="button-demo-row">
                            <span className="pill ok">Connected</span>
                            <span className="pill warn">Needs review</span>
                            <span className="pill err">Unavailable</span>
                        </div>
                    </DemoCard>
                    <DemoCard label="Surface">
                        <div className="ui-foundations-surface">
                            <Icon.Sparkles size={16} />
                            <div><strong>Quiet depth</strong><small>Hairlines define structure before shadows do.</small></div>
                        </div>
                    </DemoCard>
                </div>
            </section>

            <section className="button-demo-section" aria-labelledby="feedback-title">
                <DemoHeading
                    index="06"
                    title="Feedback & alerts"
                    description="One structure communicates severity without turning technical details into raw terminal output."
                    id="feedback-title"
                />
                <div className="ui-foundations-alerts">
                    <Alert variant="info">
                        <AlertIcon>
                            <Icon.Info size={16} />
                        </AlertIcon>
                        <AlertTitle>Helpful context</AlertTitle>
                        <AlertDescription>This information can make the next step clearer.</AlertDescription>
                    </Alert>
                    <Alert variant="success">
                        <AlertIcon>
                            <Icon.Check size={16} />
                        </AlertIcon>
                        <AlertTitle>Changes saved</AlertTitle>
                        <AlertDescription>The latest configuration is now active.</AlertDescription>
                    </Alert>
                    <Alert variant="warning">
                        <AlertIcon>
                            <Icon.Alert size={16} />
                        </AlertIcon>
                        <AlertTitle>Review recommended</AlertTitle>
                        <AlertDescription>One setting may need your attention before continuing.</AlertDescription>
                    </Alert>
                    <Alert variant="destructive">
                        <AlertIcon>
                            <Icon.Alert size={16} />
                        </AlertIcon>
                        <AlertTitle>Request not completed</AlertTitle>
                        <AlertDescription>Open the details to understand what happened and try again.</AlertDescription>
                    </Alert>
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

export const UiFoundationsDemo = ButtonSystemDemo;

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
