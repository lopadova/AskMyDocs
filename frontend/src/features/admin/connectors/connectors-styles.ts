/*
 * Interaction styles for the redesigned Connectors page. Inline styles can't
 * express :hover / :focus-visible, so the hover-driven look of the design
 * handoff lives here as a single injected <style> string (the same pattern the
 * app uses for `.conv-row` in tokens.css and the `amd-spin` keyframes). Every
 * colour is a design token so the page follows the app's light + dark themes
 * (the mockup is dark-only; per the agreed brief we keep it theme-aware).
 *
 * Class prefix `amd-cn-` (AskMyDocs ConNectors) avoids collisions with the
 * global utility classes.
 */
export const CONNECTORS_STYLES = `
@keyframes amd-cn-spin { to { transform: rotate(360deg); } }
@keyframes amd-cn-pop { from { opacity: 0; transform: translateY(-4px) scale(.98); } to { opacity: 1; transform: none; } }
@keyframes amd-cn-fade { from { opacity: 0; } to { opacity: 1; } }
@keyframes amd-cn-modal { from { opacity: 0; transform: translateY(10px) scale(.98); } to { opacity: 1; transform: none; } }

.amd-cn-src-tile { transition: border-color .14s ease, background .14s ease; }
.amd-cn-src-tile:hover { border-color: var(--panel-border-strong); }

/* Small square icon buttons (the tile "+", the row "sync now"). */
.amd-cn-icon-btn { transition: border-color .14s ease, background .14s ease, color .14s ease; }
.amd-cn-icon-btn:hover:not(:disabled) { border-color: var(--accent-a); color: var(--fg-0); background: var(--bg-3); }
.amd-cn-icon-btn:disabled { opacity: .55; cursor: not-allowed; }

/* Secondary pill buttons (Sync all, Import). */
.amd-cn-btn { transition: border-color .14s ease, background .14s ease, color .14s ease; }
.amd-cn-btn:hover:not(:disabled) { border-color: var(--panel-border-strong); background: var(--bg-3); color: var(--fg-0); }
.amd-cn-btn:disabled { opacity: .6; cursor: not-allowed; }

/* View-toggle segment. */
.amd-cn-tab { transition: background .14s ease, color .14s ease; }
.amd-cn-tab:hover { color: var(--fg-1); }

/* Table body rows. */
.amd-cn-row { transition: background .12s ease; }
.amd-cn-row:hover { background: var(--bg-2); }

/* ⋮ overflow trigger. */
.amd-cn-menu-btn { transition: background .14s ease, color .14s ease; }
.amd-cn-menu-btn:hover { background: var(--bg-3); color: var(--fg-0); }

/* Dropdown menu items. */
.amd-cn-menu-item { transition: background .12s ease; }
.amd-cn-menu-item:hover:not(:disabled) { background: var(--bg-3); }
.amd-cn-menu-item.danger:hover:not(:disabled) { background: rgba(239, 68, 68, 0.14); }
.amd-cn-menu-item:disabled { opacity: .5; cursor: not-allowed; }

/* Errored "issue" chip / button. */
.amd-cn-issue { transition: background .12s ease; }
.amd-cn-issue:hover { background: rgba(239, 68, 68, 0.2); }
`;
