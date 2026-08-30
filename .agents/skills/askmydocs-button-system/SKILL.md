---
name: askmydocs-button-system
description: Design, implement, or audit AskMyDocs interface buttons. Use whenever adding or modifying buttons, action groups, icon buttons, submit controls, or reviewing a UI that contains inconsistent action styling.
---

# AskMyDocs Button System

Create buttons that feel precise and quiet: obvious hierarchy, compact geometry, restrained tactile depth, and complete interaction states.

## Use the canonical component

- Use `frontend/src/components/Button.tsx` for new React buttons.
- Treat the global `.btn` class as a compatibility layer for legacy call sites, not the preferred API.
- Use the shared variants (`primary`, `secondary`, `quiet`, `danger`) and sizes (`sm`, `md`, `lg`) before adding contextual classes.
- Keep contextual CSS limited to layout or exceptional semantic color. Do not redefine radius, shadow, padding, or interaction motion per screen.
- Review the live specimen at `/app/{teamHash}/developer/buttons` when visual judgment is needed.

## Establish hierarchy before styling

1. Identify the action users most likely need next.
2. Use at most one primary action in a local action cluster.
3. Use secondary for ordinary product actions and quiet for low-priority chrome.
4. Reserve danger for destructive or difficult-to-reverse work.
5. If several choices are mutually exclusive, use `.ui-button-group` with `aria-pressed` rather than a row of competing primary buttons.

## Write and compose controls deliberately

- Default to sentence case and a short verb-led label.
- Use `caps` only for technical utilities or status labels of roughly twelve characters or fewer.
- Use one leading icon when it improves recognition. Use a trailing chevron only when it explains navigation or progression.
- Give icon-only controls an `aria-label` and tooltip/title when the meaning is not universally clear.
- Never use color or an icon as the only carrier of meaning.

## Preserve the interaction contract

- Support default, hover, active, focus-visible, disabled, and busy states.
- Keep the layout stable while busy. Use the component `busy` prop rather than swapping in arbitrary loaders.
- Retain the two-edge construction: an outer boundary plus a restrained inner hairline.
- Never translate a button on hover; use luminance, edge and glow changes instead.
- Reserve at most one optical pixel of movement for the active press, and remove it under `prefers-reduced-motion`.
- Verify contrast and edge definition in both light and dark themes.

## Audit nearby buttons

When touching a screen that contains existing buttons, inspect the nearby action cluster. Migrate clear outliers to the canonical component when the change is in scope and low risk. Do not perform an unrelated application-wide rewrite.

Read [references/button-contract.md](references/button-contract.md) for exact measurements, exceptions, and the review checklist.

## Verify

1. Run component tests and type checking.
2. Exercise the control with keyboard focus and activation.
3. Inspect the affected surface and the demo in light and dark themes.
4. Check narrow widths, long labels, loading, and disabled behavior.
