import { BookOpen, ExternalLink, Info, Sparkles } from 'lucide-react';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { CopyButton } from './CopyButton';

/** Repo docs the operator can hand to whoever integrates the widget. */
const DOCS_BASE =
    'https://github.com/lopadova/AskMyDocs/blob/main/docs/kitt';

/**
 * The "hand-off command": a ready-to-paste prompt for an AI coding agent
 * (Claude Code / Copilot / Cursor) that annotates the host site's DOM with
 * the `data-kitt-*` contract KITT reads. `\${…}` is escaped so the
 * interpolation tokens reach the clipboard literally, not evaluated by JS.
 *
 * Mirrors docs/kitt/agent-annotation-prompt.md — keep both in sync.
 */
const HANDOFF_PROMPT = `ROLE
You annotate a web page's DOM with the \`data-kitt-*\` contract used by the
AskMyDocs KITT widget. KITT is an embeddable AI assistant that reads a snapshot
of the page and can fill forms and navigate for the user. Your annotations are
what KITT sees: without them it guesses from the raw outline (headings, buttons,
unlabelled inputs).

ABSOLUTE RULE
Add ONLY \`data-kitt-*\` attributes (plus an \`id\`/\`for\` when needed to bind a
label to its input). Do NOT change structure, tags, CSS classes, visible text,
JS handlers or logic. Output a minimal, additive, idempotent diff.

VOCABULARY (exactly these attributes — KITT reads no others)

1. REGION — sections / wizard steps
   <section data-kitt-region="<stable-id>"
            data-kitt-active="true"            (only on the currently visible region)
            data-kitt-help="What this section is for.">

2. FIELD — input fields
   <div data-kitt-field="<stable_name>"        (snake_case, stable over time)
        data-kitt-required                      (if mandatory)
        data-kitt-sensitive                     (PII/secret: KITT never reads the value)
        data-kitt-help="How to fill it.">
     <label for="x">Label</label>               (ALWAYS bind label to input via for/id)
     <input id="x" name="x" data-kitt-input>    (mark the real input inside the wrapper)
   </div>
   • Async combobox: add data-kitt-options-source="<url>" on the wrapper and
     role="combobox" on the input so KITT searches options instead of inventing them.

3. ACTION — clickable buttons / links
   <button data-kitt-action="<stable-verb>"     (e.g. "submit", "next", "back", "delete")
           data-kitt-help="What it does."
           data-kitt-reason-disabled="Why it's disabled, when it is.">
   • The verb is a STABLE identity: do not change it when text/locale/CSS change.

4. MESSAGE — banners KITT must read (validation errors, warnings)
   <div data-kitt-message="error">…</div>       (levels: "error" | "warning" | "info")

5. LOCALE — language switcher (if multilingual)
   <button data-kitt-locale="it" data-kitt-active="true">IT</button>

6. SKIP — subtrees to ignore (promo banners, debug, third-party widgets)
   <aside data-kitt-skip> … </aside>

GUIDELINES
• Safety first: mark data-kitt-sensitive on every password, IBAN, card number,
  tax id/SSN, token, or health field.
• data-kitt-help is high value: write concrete fill instructions (format, examples).
• Names (data-kitt-field, data-kitt-action) are a stable API — no volatile indices.
• Idempotent: if a data-kitt-* already exists, leave it untouched.

OUTPUT
Return the annotated markup (or an additive diff). At the end, list the created
fields and actions in a table (name/verb, required?, sensitive?) so I can verify.

MARKUP TO ANNOTATE
<<< paste the HTML / component / Blade-React-Vue template here >>>`;

/**
 * Widget admin → Integration guide tab.
 *
 * A mini-guide for whoever embeds the widget, plus the copy-paste "hand-off
 * command": the prompt to drop into an AI coding agent so it annotates the
 * host page's DOM with the `data-kitt-*` contract. The more the page is
 * annotated, the less KITT improvises — and the better it supports form
 * filling and navigation. R11 testids, R15 a11y.
 */
export function WidgetIntegrationGuideView() {
    return (
        <section data-testid="admin-widget-guide-view" className="grid gap-5">
            <header>
                <h1 className="m-0 flex items-center gap-2 text-[22px] font-semibold">
                    <BookOpen aria-hidden className="size-5 text-[var(--accent-a)]" />
                    Make KITT smarter on your page
                </h1>
                <p className="text-muted-foreground mt-1.5 text-sm">
                    KITT can fill forms and navigate for the visitor. It works out of the box,
                    but it shines when the host page declares its structure with{' '}
                    <code className="font-mono">data-kitt-*</code> attributes. Hand the command
                    below to an AI agent and let it annotate the DOM for you.
                </p>
            </header>

            {/* The three tiers — annotate vs improvise */}
            <Alert variant="info">
                <Info aria-hidden />
                <AlertTitle>How KITT reads a page (annotate → it knows; skip → it improvises)</AlertTitle>
                <AlertDescription>
                    <ol className="list-decimal space-y-0.5 pl-4">
                        <li>
                            <strong>Annotated</strong> — elements marked with{' '}
                            <code className="font-mono">data-kitt-*</code> give KITT a precise
                            map: fields, required flags, options, steps, actions. Form filling is
                            reliable.
                        </li>
                        <li>
                            <strong>Auto-annotated</strong> — the assistant skill can inject the
                            attributes via CSS rules, so common patterns are covered without
                            touching the host HTML.
                        </li>
                        <li>
                            <strong>Heuristic</strong> — with no annotations, KITT still reads a
                            rough outline (headings, buttons, inputs) and improvises. It works,
                            but it guesses.
                        </li>
                    </ol>
                </AlertDescription>
            </Alert>

            {/* The hand-off command */}
            <Card data-testid="admin-widget-guide-handoff">
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <Sparkles aria-hidden className="size-4 text-[var(--accent-a)]" />
                        Hand-off command for an AI agent
                        <Badge variant="muted">copy &amp; paste</Badge>
                    </CardTitle>
                    <CardDescription>
                        Open your page (or component) in Claude Code, GitHub Copilot, Cursor, or
                        any coding agent, paste this prompt, and append the markup to annotate at
                        the bottom. Review the diff: it must only <em>add</em> attributes — never
                        change structure or logic.
                    </CardDescription>
                </CardHeader>
                <CardContent className="grid gap-3">
                    <div className="overflow-hidden rounded-md border border-border">
                        <div className="bg-muted flex items-center justify-between gap-2 border-b border-border py-1.5 pr-2 pl-3">
                            <span className="text-muted-foreground font-mono text-[10px] font-semibold tracking-widest uppercase">
                                Agent prompt
                            </span>
                            <CopyButton
                                value={HANDOFF_PROMPT}
                                testId="admin-widget-guide-handoff-copy"
                                label="Copy command"
                            />
                        </div>
                        <pre
                            data-testid="admin-widget-guide-handoff-text"
                            className="text-foreground max-h-80 overflow-auto bg-[var(--bg-2)] p-3 font-mono text-xs leading-relaxed whitespace-pre-wrap [font-variant-ligatures:none]"
                        >
                            {HANDOFF_PROMPT}
                        </pre>
                    </div>
                </CardContent>
            </Card>

            {/* Attribute cheat-sheet */}
            <Card data-testid="admin-widget-guide-cheatsheet">
                <CardHeader>
                    <CardTitle className="text-base">The attributes KITT reads</CardTitle>
                    <CardDescription>
                        The full vocabulary — nothing else is interpreted.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div className="overflow-x-auto rounded-lg border border-border">
                        <table className="w-full border-collapse text-[13px]">
                            <thead>
                                <tr className="text-muted-foreground border-b border-border text-left [&>th]:px-3 [&>th]:py-2 [&>th]:font-medium">
                                    <th>Attribute</th>
                                    <th>Put it on</th>
                                    <th>What it does</th>
                                </tr>
                            </thead>
                            <tbody className="[&>tr]:border-b [&>tr]:border-border last:[&>tr]:border-0 [&_td]:px-3 [&_td]:py-2 [&_td]:align-top">
                                <tr>
                                    <td className="font-mono text-xs">data-kitt-region</td>
                                    <td>section / step wrapper</td>
                                    <td>
                                        Declares a section. Add{' '}
                                        <code className="font-mono">data-kitt-active="true"</code>{' '}
                                        on the visible one.
                                    </td>
                                </tr>
                                <tr>
                                    <td className="font-mono text-xs">data-kitt-field</td>
                                    <td>input wrapper</td>
                                    <td>
                                        A form field. Combine with{' '}
                                        <code className="font-mono">data-kitt-required</code> /{' '}
                                        <code className="font-mono">data-kitt-sensitive</code> /{' '}
                                        <code className="font-mono">data-kitt-help</code>.
                                    </td>
                                </tr>
                                <tr>
                                    <td className="font-mono text-xs">data-kitt-input</td>
                                    <td>the real input</td>
                                    <td>
                                        Marks the actual{' '}
                                        <code className="font-mono">
                                            &lt;input&gt;/&lt;select&gt;/&lt;textarea&gt;
                                        </code>{' '}
                                        inside the wrapper.
                                    </td>
                                </tr>
                                <tr>
                                    <td className="font-mono text-xs">data-kitt-action</td>
                                    <td>button / link</td>
                                    <td>
                                        A clickable action with a stable verb. Add{' '}
                                        <code className="font-mono">data-kitt-reason-disabled</code>{' '}
                                        when it can be disabled.
                                    </td>
                                </tr>
                                <tr>
                                    <td className="font-mono text-xs">data-kitt-message</td>
                                    <td>banner / alert</td>
                                    <td>
                                        Validation / status text KITT reads.{' '}
                                        <code className="font-mono">error|warning|info</code>.
                                    </td>
                                </tr>
                                <tr>
                                    <td className="font-mono text-xs">data-kitt-locale</td>
                                    <td>language switch</td>
                                    <td>An available locale; mark the active one.</td>
                                </tr>
                                <tr>
                                    <td className="font-mono text-xs">data-kitt-skip</td>
                                    <td>any subtree</td>
                                    <td>Hides that subtree from KITT entirely.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>

            {/* Reference docs */}
            <div className="flex flex-wrap gap-4">
                <a
                    href={`${DOCS_BASE}/example-annotated-page.html`}
                    target="_blank"
                    rel="noreferrer"
                    className="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1 text-xs"
                    data-testid="admin-widget-guide-example-link"
                >
                    <ExternalLink aria-hidden className="size-3" />
                    Golden reference: a fully annotated multi-step form
                </a>
                <a
                    href={`${DOCS_BASE}/agent-annotation-prompt.md`}
                    target="_blank"
                    rel="noreferrer"
                    className="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1 text-xs"
                    data-testid="admin-widget-guide-prompt-link"
                >
                    <ExternalLink aria-hidden className="size-3" />
                    Full hand-off prompt &amp; checklist
                </a>
            </div>
        </section>
    );
}
