/**
 * CSS del widget, iniettato come stringa nello shadow root (closed) così non
 * eredita né perde stili verso/da la pagina ospite (isolamento, D5). Niente
 * Tailwind: poche regole scritte a mano per tenere il bundle leggero.
 */
export const WIDGET_CSS = `
:host { all: initial; }
*, *::before, *::after { box-sizing: border-box; }
.amd-root {
    font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
    --amd-accent: #2563eb;
    --amd-bg: #ffffff;
    --amd-fg: #1f2937;
    --amd-muted: #6b7280;
    --amd-border: #e5e7eb;
    color: var(--amd-fg);
}
.amd-launcher {
    position: fixed; right: 20px; bottom: 20px; z-index: 2147483000;
    display: inline-flex; align-items: center; gap: 8px;
    padding: 12px 16px; border: none; border-radius: 999px;
    background: var(--amd-accent); color: #fff; font-size: 14px; font-weight: 600;
    cursor: pointer; box-shadow: 0 6px 20px rgba(0,0,0,.18);
}
.amd-launcher:focus-visible { outline: 3px solid #93c5fd; outline-offset: 2px; }
.amd-panel {
    position: fixed; right: 20px; bottom: 84px; z-index: 2147483000;
    width: 380px; max-width: calc(100vw - 40px); height: 560px; max-height: calc(100vh - 120px);
    display: none; flex-direction: column;
    background: var(--amd-bg); border: 1px solid var(--amd-border); border-radius: 14px;
    box-shadow: 0 12px 40px rgba(0,0,0,.22); overflow: hidden;
}
.amd-panel[data-open="true"] { display: flex; }
.amd-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 14px; background: var(--amd-accent); color: #fff;
}
.amd-title { font-size: 14px; font-weight: 700; }
.amd-close { background: transparent; border: none; color: #fff; font-size: 20px; cursor: pointer; line-height: 1; }
.amd-messages { flex: 1; overflow-y: auto; padding: 14px; display: flex; flex-direction: column; gap: 10px; }
.amd-msg { max-width: 88%; padding: 9px 12px; border-radius: 12px; font-size: 14px; line-height: 1.45; white-space: pre-wrap; word-wrap: break-word; }
.amd-msg.user { align-self: flex-end; background: var(--amd-accent); color: #fff; border-bottom-right-radius: 4px; }
.amd-msg.assistant { align-self: flex-start; background: #f3f4f6; color: var(--amd-fg); border-bottom-left-radius: 4px; }
.amd-msg.system { align-self: center; background: #fff7ed; color: #9a3412; font-size: 12.5px; }
.amd-msg.error { align-self: center; background: #fef2f2; color: #b91c1c; font-size: 12.5px; }
.amd-citations { margin-top: 6px; display: flex; flex-wrap: wrap; gap: 4px; }
.amd-cite { font-size: 11px; background: #e0e7ff; color: #3730a3; padding: 2px 6px; border-radius: 6px; }
.amd-status { padding: 0 14px 6px; font-size: 12px; color: var(--amd-muted); min-height: 16px; }
.amd-confirm { margin: 6px 14px; padding: 10px; border: 1px solid #fde68a; background: #fffbeb; border-radius: 10px; font-size: 13px; }
.amd-confirm-actions { margin-top: 8px; display: flex; gap: 8px; }
.amd-btn { padding: 7px 12px; border-radius: 8px; border: 1px solid var(--amd-border); background: #fff; font-size: 13px; cursor: pointer; }
.amd-btn.primary { background: var(--amd-accent); color: #fff; border-color: var(--amd-accent); }
.amd-composer { display: flex; gap: 8px; padding: 10px; border-top: 1px solid var(--amd-border); }
.amd-input { flex: 1; resize: none; border: 1px solid var(--amd-border); border-radius: 10px; padding: 9px 10px; font: inherit; font-size: 14px; min-height: 40px; max-height: 120px; }
.amd-input:focus-visible { outline: 2px solid var(--amd-accent); outline-offset: 0; }
.amd-send { padding: 0 14px; border: none; border-radius: 10px; background: var(--amd-accent); color: #fff; font-weight: 600; cursor: pointer; }
.amd-send:disabled { opacity: .5; cursor: not-allowed; }
.amd-ask-options { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
`;
