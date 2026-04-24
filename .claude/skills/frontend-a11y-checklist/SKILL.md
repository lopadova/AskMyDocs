---
name: frontend-a11y-checklist
description: Every actionable element in the React SPA must be programmatically announced to assistive tech AND reachable via keyboard. Applies to inputs, buttons, dropdowns, tooltips, checkboxes, tree widgets, dialog controls. Trigger when editing any component under frontend/src/, especially forms (inputs + selects + textareas), custom tree/tab/listbox widgets, tooltip/popover components, and dialog-hosted permission matrices. Complements R11 (testid contract) with the a11y surface.
---

# Frontend a11y checklist

## Rule

Every interactive element in the React SPA must:

1. Be **programmatically labelled** — `<label htmlFor>`,
   `aria-label`, or `aria-labelledby`. Placeholder is NOT a label.
2. Be **keyboard-reachable** — not hidden via `display:none` when it's
   a real input; tab order makes sense; `:focus` style visible.
3. Carry role / state on the **focusable** element, not on a wrapper
   that never receives focus.
4. Respond to focus/blur (and ideally hover/mouseenter) — keyboard
   users can't discover mouse-only affordances.

## Symptoms in a review diff

- `<input placeholder="Email" />` with no `aria-label` / `<label>`.
- `<input type="checkbox" style={{display:'none'}} />` + a visual
  proxy — screen-readers cannot perceive `display:none` inputs.
- Visible label rendered as `<div>` / `<span>` without any
  association to the input (no `htmlFor`, no wrapping `<label>`, no
  `aria-labelledby`).
- `role="treeitem"` / `aria-expanded` / `aria-selected` on an `<li>`
  wrapper whose focusable child is a nested `<button>`.
- `<Tooltip>` wired to `onMouseEnter` / `onMouseLeave` without
  `onFocus` / `onBlur`.
- Icon-only `<button><Icon/></button>` with no `aria-label`.
- `<select>` without an associated label.

## How to detect in-code

```bash
# Placeholder-only inputs
rg -n 'placeholder=' frontend/src/ | rg -v 'aria-label|htmlFor'

# display:none checkboxes
rg -n "display:\s*'none'" frontend/src/ -B1 -A1 | rg -B1 -A1 'type="checkbox"'

# role= on wrappers whose child is a button
rg -n 'role="treeitem"|role="tab"|role="option"' frontend/src/

# Tooltip without focus handlers
rg -n 'onMouseEnter' frontend/src/components/ | rg -L 'onFocus'

# Icon-only buttons (heuristic)
rg -n '<button[^>]*>\s*<[A-Z]' frontend/src/ | rg -v 'aria-label'
```

## Fix templates

### Label placeholder-only inputs (PR #24 TreeView)

```tsx
// ❌
<input
  data-testid="kb-tree-search"
  placeholder="Search"
  value={q}
  onChange={(e) => setQ(e.target.value)}
/>

// ✅ — option A (visible label)
<label htmlFor="kb-tree-search" className="sr-only">Search KB</label>
<input
  id="kb-tree-search"
  data-testid="kb-tree-search"
  type="search"
  placeholder="Search"
  value={q}
  onChange={(e) => setQ(e.target.value)}
/>

// ✅ — option B (no visible label)
<input
  data-testid="kb-tree-search"
  type="search"
  aria-label="Search KB"
  placeholder="Search"
  value={q}
  onChange={(e) => setQ(e.target.value)}
/>
```

### Replace `display:none` with the visually-hidden pattern (PR #23 RoleDialog)

```tsx
// ❌
<input type="checkbox" style={{display:'none'}} checked={has} onChange={...} />
<div aria-hidden="true" className={has ? 'visual-checked' : 'visual-unchecked'} />

// ✅ — CSS visually-hidden keeps the input in the a11y tree
// frontend/src/styles/a11y.css
.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
// component
<label>
  <input type="checkbox" className="visually-hidden" checked={has} onChange={...} />
  <span aria-hidden="true" className={has ? 'visual-checked' : 'visual-unchecked'} />
  <span className="visually-hidden">{permission} on {module}</span>
</label>
```

### Bind visible labels to inputs (PR #23 UserForm)

```tsx
// ❌
<div style={{fontWeight:600}}>Email</div>
<input data-testid="user-form-email" value={email} onChange={...} />

// ✅
<label htmlFor="user-form-email" style={{fontWeight:600}}>Email</label>
<input
  id="user-form-email"
  data-testid="user-form-email"
  name="email"
  type="email"
  value={email}
  onChange={...}
/>
```

### Put role/state on the focusable element (PR #24 TreeView treeitem)

```tsx
// ❌ — li is not focusable, button is
<li role="treeitem" aria-expanded={open} aria-selected={selected}>
  <button onClick={toggle}>{label}</button>
</li>

// ✅
<li>
  <button
    role="treeitem"
    aria-expanded={open}
    aria-selected={selected}
    onClick={toggle}
  >
    {label}
  </button>
</li>
```

### Tooltip responds to focus (PR #19 Tooltip)

```tsx
// ❌
<div onMouseEnter={show} onMouseLeave={hide}>
  <IconInfo />
  {visible && <div role="tooltip">{text}</div>}
</div>

// ✅
<button
  type="button"
  aria-describedby={visible ? id : undefined}
  onMouseEnter={show}
  onMouseLeave={hide}
  onFocus={show}
  onBlur={hide}
>
  <IconInfo aria-hidden />
  <span className="visually-hidden">More info</span>
</button>
{visible && <div id={id} role="tooltip">{text}</div>}
```

## Related rules

- R11 — testid + `data-state` contract (orthogonal surface).
- R12 — Playwright selectors use `getByRole` + accessible name, which
  ONLY works if the accessible name exists. R15 feeds R12.

## Enforcement

- `eslint-plugin-jsx-a11y` catches the most common offenders
  (`label-has-associated-control`, `click-events-have-key-events`,
  `no-static-element-interactions`). Verify the repo's
  `eslint.config.js` keeps these rules at `error`.
- Playwright's `expect(locator).toHaveAccessibleName(...)` assertions
  in the e2e specs catch regressions the linter misses.
- The `copilot-review-anticipator` sub-agent greps for the symptoms
  above on every diff.

## Counter-example

```tsx
// ❌ Every finding in PR #23 + PR #24 in one component
<div>
  <div>Project key</div>                             {/* unbound label */}
  <input placeholder="project" />                   {/* placeholder-only */}
  <input type="checkbox" style={{display:'none'}} />{/* display:none input */}
  <ul>
    <li role="treeitem" aria-expanded>              {/* role on non-focusable */}
      <button>{label}</button>
    </li>
  </ul>
  <div onMouseEnter={show}><IconInfo/></div>        {/* tooltip mouse-only */}
  <button><IconTrash/></button>                     {/* icon-only, unlabelled */}
</div>
```
