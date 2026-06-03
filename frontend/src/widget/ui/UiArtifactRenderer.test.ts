import { describe, it, expect } from 'vitest';
import { UiArtifactRenderer } from '../ui/UiArtifactRenderer';
import type { Artifact } from '../core/bridge';

describe('UiArtifactRenderer', () => {
    const renderer = new UiArtifactRenderer();

    /** Helper: renderizza artifact e ritorna il wrapper. */
    function render(type: string, props: Record<string, unknown> = {}, hasResults = true, interactionMode = 'view'): HTMLElement {
        const artifact: Artifact = { componentType: type, componentProps: props };

        return renderer.render(artifact, hasResults, interactionMode);
    }

    // --- Whitelist ---

    it('renders all spec §5.3 component types without fallback', () => {
        const allowedTypes = [
            'ui-data-table', 'ui-kpi', 'ui-kpi-grid', 'ui-alert',
            'ui-card', 'ui-badge', 'ui-toast', 'ui-list',
            'ui-chart', 'markdown', 'code-block', 'citations',
        ];
        for (const type of allowedTypes) {
            const el = render(type);
            // La classe CSS riflette il tipo originale (non il fallback ui-card)
            expect(el.classList.contains(`amd-artifact--${type}`)).toBe(true);
        }
    });

    it('falls back to ui-card for unknown componentType', () => {
        const el = render('evil-component');
        expect(el.classList.contains('amd-artifact--ui-card')).toBe(true);
    });

    it('sanitizes text to prevent XSS in all rendered content', () => {
        const el = render('ui-card', {
            title: '<script>alert(1)</script>',
            body: '```evil-code```',
        });
        // textContent è assegnato via DOM API (non innerHTML), ma sanitizeText
        // strip ulteriormente < > e backtick fence
        expect(el.textContent).not.toContain('<script>');
        expect(el.textContent).not.toContain('```');
    });

    // --- ui-data-table ---

    it('renders ui-data-table with columns and rows', () => {
        const el = render('ui-data-table', {
            columns: [{ key: 'id', label: 'ID' }, { key: 'name', label: 'Nome' }],
            rows: [{ id: 1, name: 'Alice' }, { id: 2, name: 'Bob' }],
            rowKey: 'id',
        });
        expect(el.querySelector('table')).not.toBeNull();
        expect(el.querySelectorAll('thead th').length).toBe(2);
        expect(el.querySelectorAll('tbody tr').length).toBe(2);
    });

    it('ui-data-table caps rows at 20', () => {
        const rows = Array.from({ length: 30 }, (_, i) => ({ id: i, name: `n${i}` }));
        const el = render('ui-data-table', {
            columns: [{ key: 'id', label: 'ID' }],
            rows,
        });
        expect(el.querySelectorAll('tbody tr').length).toBe(20);
    });

    it('ui-data-table shows empty state when no rows', () => {
        const el = render('ui-data-table', {
            columns: [{ key: 'id', label: 'ID' }],
            rows: [],
        });
        expect(el.textContent).toContain('Nessun dato disponibile');
    });

    // --- ui-kpi ---

    it('renders ui-kpi with label, value and optional unit', () => {
        const el = render('ui-kpi', { label: 'Revenue', value: '42K', unit: 'EUR' });
        expect(el.textContent).toContain('Revenue');
        expect(el.textContent).toContain('42K');
        expect(el.textContent).toContain('EUR');
    });

    // --- ui-kpi-grid ---

    it('renders ui-kpi-grid with multiple items', () => {
        const el = render('ui-kpi-grid', {
            items: [{ label: 'A', value: '1' }, { label: 'B', value: '2' }],
        });
        expect(el.querySelectorAll('.amd-artifact__kpi').length).toBe(2);
    });

    it('ui-kpi-grid caps items at 12', () => {
        const items = Array.from({ length: 20 }, (_, i) => ({ label: `K${i}`, value: String(i) }));
        const el = render('ui-kpi-grid', { items });
        expect(el.querySelectorAll('.amd-artifact__kpi').length).toBe(12);
    });

    // --- ui-alert ---

    it('renders ui-alert with level and body', () => {
        const el = render('ui-alert', { level: 'warning', title: 'Attenzione', body: 'Controlla i dati' });
        expect(el.textContent).toContain('Attenzione');
        expect(el.textContent).toContain('Controlla i dati');
    });

    it('ui-alert defaults to info for invalid level', () => {
        const el = render('ui-alert', { level: 'nonexistent' });
        const alertEl = el.querySelector('[data-level]');
        expect(alertEl?.getAttribute('data-level')).toBe('info');
    });

    // --- ui-badge ---

    it('renders ui-badge with label', () => {
        const el = render('ui-badge', { label: 'New', variant: 'success' });
        expect(el.textContent).toContain('New');
    });

    // --- ui-toast ---

    it('renders ui-toast with message', () => {
        const el = render('ui-toast', { message: 'Salvato!', level: 'success' });
        expect(el.textContent).toContain('Salvato!');
    });

    // --- ui-list ---

    it('renders ui-list with items', () => {
        const el = render('ui-list', {
            title: 'Lista',
            items: [{ label: 'A' }, { label: 'B', value: 'val' }],
        });
        expect(el.querySelector('ul')).not.toBeNull();
        expect(el.querySelectorAll('li').length).toBe(2);
    });

    // --- markdown ---

    it('renders markdown content as plain text', () => {
        const el = render('markdown', { content: '# Hello **world**' });
        expect(el.textContent).toContain('# Hello **world**');
    });

    it('markdown sanitizes code fences from content', () => {
        const el = render('markdown', { content: '```js\nevil()\n```' });
        expect(el.textContent).not.toContain('```');
    });

    // --- code-block ---

    it('renders code-block with language and code', () => {
        const el = render('code-block', { language: 'typescript', code: 'const x = 1;' });
        expect(el.textContent).toContain('typescript');
        expect(el.textContent).toContain('const x = 1;');
    });

    // --- citations ---

    it('renders citations list', () => {
        const el = render('citations', {
            items: [{ title: 'Doc A', source_path: '/docs/a' }],
        });
        expect(el.querySelector('ol')).not.toBeNull();
        expect(el.textContent).toContain('Doc A');
    });

    it('citations shows empty state when no items', () => {
        const el = render('citations', { items: [] });
        expect(el.textContent).toContain('Nessuna citazione disponibile');
    });

    // --- Generic fallback ---

    it('renders fallback card for unknown componentType', () => {
        const el = render('totally-unknown-xyz');
        // Unknown types fall back to ui-card via sanitizeType
        expect(el.classList.contains('amd-artifact--ui-card')).toBe(true);
    });

    // --- Metadata ---

    it('sets data attributes for hasResults and interactionMode on wrapper', () => {
        const el = render('ui-data-table', {}, false, 'selection');
        // setAttribute con camelCase preserva il case
        expect(el.getAttribute('data-hasResults')).toBe('false');
        expect(el.getAttribute('data-interactionMode')).toBe('selection');
    });
});