/**
 * UiArtifactRenderer — renderizza gli artifact restituiti dai tool BE
 * nella chat del widget. Supporta una whitelist di componentType (spec §5.3);
 * i tipi non riconosciuti vengono renderizzati come card generica.
 *
 * Difesa in profondità: ogni testo passa per sanitizeText (spec §15).
 * Nessun HTML arbitrario dal BE — solo textContent assegnato via DOM API.
 */
import type { Artifact } from '../core/bridge';
import { sanitizeText } from '../dom/sanitize';

/** Whitelist di componentType supportati (spec §5.3). */
const ALLOWED_COMPONENT_TYPES: ReadonlySet<string> = new Set([
    'ui-data-table',
    'ui-kpi',
    'ui-kpi-grid',
    'ui-alert',
    'ui-card',
    'ui-badge',
    'ui-toast',
    'ui-list',
    'ui-chart',
    'markdown',
    'code-block',
    'citations',
]);

export class UiArtifactRenderer {
    /**
     * Renderizza un artifact in un HTMLElement.
     * Il chiamante (panel.ts) lo appende al messaggio.
     */
    render(artifact: Artifact, hasResults: boolean, interactionMode: string): HTMLElement {
        // F1.7 — gli host tool gescat possono restituire componentType extra non
        // nativi (ui-articolo-card, ui-categoria-card): li mappiamo su un renderer
        // supportato normalizzandone le props PRIMA della whitelist, così il
        // fallback è informativo invece di una card vuota.
        const { componentType, componentProps } = this.normalizeExtraType(artifact);
        const type = this.sanitizeType(componentType);
        const wrapper = document.createElement('div');
        wrapper.className = `amd-artifact amd-artifact--${type}`;
        wrapper.dataset.testid = 'askmydocs-widget-artifact-container';
        // Conserva il tipo originale per debugging/E2E (fallback osservabile).
        wrapper.dataset.sourceComponentType = this.safe(artifact.componentType, 64);
        wrapper.setAttribute('data-interactionMode', interactionMode);
        wrapper.setAttribute('data-hasResults', String(hasResults));

        switch (type) {
            case 'ui-data-table':
                this.renderDataTable(componentProps, wrapper);
                break;
            case 'ui-kpi':
                this.renderKpi(componentProps, wrapper);
                break;
            case 'ui-kpi-grid':
                this.renderKpiGrid(componentProps, wrapper);
                break;
            case 'ui-alert':
                this.renderAlert(componentProps, wrapper);
                break;
            case 'ui-card':
                this.renderCard(componentProps, wrapper);
                break;
            case 'ui-badge':
                this.renderBadge(componentProps, wrapper);
                break;
            case 'ui-toast':
                this.renderToast(componentProps, wrapper);
                break;
            case 'ui-list':
                this.renderList(componentProps, wrapper);
                break;
            case 'ui-chart':
                this.renderChart(componentProps, wrapper);
                break;
            case 'markdown':
                this.renderMarkdown(componentProps, wrapper);
                break;
            case 'code-block':
                this.renderCodeBlock(componentProps, wrapper);
                break;
            case 'citations':
                this.renderCitations(componentProps, wrapper);
                break;
            default:
                this.renderGeneric(componentProps, wrapper);
        }

        return wrapper;
    }

    // --- Sanitizza il componentType contro la whitelist ---

    private sanitizeType(type: string): string {
        if (ALLOWED_COMPONENT_TYPES.has(type)) {
            return type;
        }

        return 'ui-card'; // fallback generico
    }

    /**
     * F1.7 — Mapping dei componentType extra gescat su renderer supportati.
     *
     * gescat (AiArtifactComponentEnum) può restituire:
     *   ui-kpi-grid, ui-badge, ui-toast, ui-chart, code-block, ui-alert  → GIÀ nativi
     *     (whitelist + renderer dedicato), nessuna normalizzazione necessaria.
     *   ui-articolo-card, ui-categoria-card                              → FALLBACK
     *     verso ui-card normalizzando le props di dominio (title/body/footer),
     *     così la card è informativa invece che vuota.
     *   qualsiasi altro tipo sconosciuto                                 → resta com'è
     *     e cade nel fallback generico di sanitizeType (ui-card → renderGeneric con
     *     dump JSON sicuro), senza rompere.
     */
    private normalizeExtraType(artifact: Artifact): { componentType: string; componentProps: Record<string, unknown> } {
        const props = artifact.componentProps ?? {};

        if (artifact.componentType === 'ui-articolo-card' || artifact.componentType === 'ui-categoria-card') {
            return { componentType: 'ui-card', componentProps: this.cardPropsFromDomainCard(props) };
        }

        return { componentType: artifact.componentType, componentProps: props };
    }

    /**
     * F1.7 — Normalizza le props di una card di dominio gescat (articolo/categoria)
     * nello shape atteso da renderCard ({title, body, footer}). Best effort sui nomi
     * di campo più comuni; se non trova nulla, ricade su un dump compatto leggibile.
     */
    private cardPropsFromDomainCard(props: Record<string, unknown>): Record<string, unknown> {
        // Se già nello shape ui-card, passa attraverso.
        if (props.title !== undefined || props.body !== undefined || props.footer !== undefined) {
            return props;
        }

        const title = props.nome ?? props.descrizione ?? props.label ?? props.codice ?? props.name ?? '';

        const bodyParts: string[] = [];
        for (const [k, v] of Object.entries(props)) {
            if (v === null || v === undefined || typeof v === 'object') {
                continue;
            }
            if (k === 'nome' || k === 'descrizione' || k === 'label' || k === 'name') {
                continue;
            }
            bodyParts.push(`${k}: ${String(v)}`);
        }

        return {
            title,
            body: bodyParts.length > 0 ? bodyParts.join('\n') : undefined,
        };
    }

    // --- Helpers ---

    /** Sanitizza + tronca un testo per output sicuro. */
    private safe(value: unknown, max = 1024): string {
        return sanitizeText(value).slice(0, max);
    }

    // --- Renderers per tipo (spec §5.3) ---

    /**
     * ui-data-table — tabella con colonne {key, label}, rows, rowKey,
     * interactionMode opzionale ('selection' | 'view').
     */
    private renderDataTable(props: Record<string, unknown>, container: HTMLElement): void {
        const columns = Array.isArray(props.columns)
            ? (props.columns as Array<Record<string, string>>).map((c) => ({
                  key: this.safe(c.key ?? '', 64),
                  label: this.safe(c.label ?? c.key ?? '', 256),
              }))
            : [];
        const rows = Array.isArray(props.rows) ? (props.rows as Record<string, unknown>[]) : [];
        const rowKey = this.safe(props.rowKey ?? 'id', 64);
        const interactionMode = props.interactionMode === 'selection' ? 'selection' : 'view';

        const heading = document.createElement('div');
        heading.className = 'amd-artifact__heading';
        heading.textContent = this.safe(props.title ?? 'Dati');
        container.append(heading);

        if (columns.length === 0 || rows.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'amd-artifact__empty';
            empty.textContent = 'Nessun dato disponibile.';
            container.append(empty);

            return;
        }

        const table = document.createElement('table');
        table.className = 'amd-artifact__table';
        table.dataset.testid = 'askmydocs-widget-artifact-table';
        table.dataset.interactionMode = interactionMode;

        const thead = document.createElement('thead');
        const headerRow = document.createElement('tr');
        for (const col of columns) {
            const th = document.createElement('th');
            th.textContent = col.label;
            headerRow.append(th);
        }
        thead.append(headerRow);

        const tbody = document.createElement('tbody');
        for (const row of rows.slice(0, 20)) {
            const tr = document.createElement('tr');
            tr.dataset.key = this.safe(row[rowKey] ?? '');
            for (const col of columns) {
                const td = document.createElement('td');
                td.textContent = this.safe(row[col.key] ?? '');
                tr.append(td);
            }
            tbody.append(tr);
        }

        table.append(thead, tbody);
        container.append(table);
    }

    /** ui-kpi — singolo indicatore {label, value, unit?, trend?}. */
    private renderKpi(props: Record<string, unknown>, container: HTMLElement): void {
        const card = document.createElement('div');
        card.className = 'amd-artifact__kpi';
        card.dataset.testid = 'askmydocs-widget-kpi';

        const label = document.createElement('div');
        label.className = 'amd-artifact__kpi-label';
        label.textContent = this.safe(props.label ?? '', 256);
        card.append(label);

        const value = document.createElement('div');
        value.className = 'amd-artifact__kpi-value';
        value.textContent = this.safe(props.value ?? '', 64);
        card.append(value);

        if (props.unit) {
            const unit = document.createElement('span');
            unit.className = 'amd-artifact__kpi-unit';
            unit.textContent = this.safe(props.unit, 16);
            value.append(unit);
        }

        if (props.trend !== undefined) {
            const trend = document.createElement('div');
            trend.className = 'amd-artifact__kpi-trend';
            trend.textContent = this.safe(props.trend, 32);
            card.append(trend);
        }

        container.append(card);
    }

    /** ui-kpi-grid — griglia di KPI {items: [{label, value, unit?, trend?}]}. */
    private renderKpiGrid(props: Record<string, unknown>, container: HTMLElement): void {
        const grid = document.createElement('div');
        grid.className = 'amd-artifact__kpi-grid';
        grid.dataset.testid = 'askmydocs-widget-kpi-grid';

        const items = Array.isArray(props.items) ? (props.items as Record<string, unknown>[]) : [];
        for (const item of items.slice(0, 12)) {
            this.renderKpi(item, grid);
        }

        container.append(grid);
    }

    /** ui-alert — messaggio {level: info|warning|error|success, title?, body?}. */
    private renderAlert(props: Record<string, unknown>, container: HTMLElement): void {
        const level = ['info', 'warning', 'error', 'success'].includes(String(props.level))
            ? String(props.level)
            : 'info';

        const alert = document.createElement('div');
        alert.className = `amd-artifact__alert amd-artifact__alert--${level}`;
        alert.dataset.testid = 'askmydocs-widget-alert';
        alert.dataset.level = level;

        if (props.title) {
            const title = document.createElement('div');
            title.className = 'amd-artifact__alert-title';
            title.textContent = this.safe(props.title, 256);
            alert.append(title);
        }

        if (props.body) {
            const body = document.createElement('div');
            body.className = 'amd-artifact__alert-body';
            body.textContent = this.safe(props.body, 1024);
            alert.append(body);
        }

        container.append(alert);
    }

    /** ui-card — card generica {title?, body?, footer?}. */
    private renderCard(props: Record<string, unknown>, container: HTMLElement): void {
        const card = document.createElement('div');
        card.className = 'amd-artifact__card';
        card.dataset.testid = 'askmydocs-widget-artifact-card';

        if (props.title) {
            const title = document.createElement('div');
            title.className = 'amd-artifact__card-title';
            title.textContent = this.safe(props.title, 256);
            card.append(title);
        }

        if (props.body) {
            const body = document.createElement('div');
            body.className = 'amd-artifact__card-body';
            body.textContent = this.safe(props.body, 2048);
            card.append(body);
        }

        if (props.footer) {
            const footer = document.createElement('div');
            footer.className = 'amd-artifact__card-footer';
            footer.textContent = this.safe(props.footer, 256);
            card.append(footer);
        }

        container.append(card);
    }

    /** ui-badge — piccolo badge {label, variant?}. */
    private renderBadge(props: Record<string, unknown>, container: HTMLElement): void {
        const variant = props.variant ? this.safe(props.variant, 32) : 'default';
        const badge = document.createElement('span');
        badge.className = `amd-artifact__badge amd-artifact__badge--${variant}`;
        badge.dataset.testid = 'askmydocs-widget-badge';
        badge.textContent = this.safe(props.label ?? '', 64);
        container.append(badge);
    }

    /** ui-toast — notifica temporanea {message, level?}. */
    private renderToast(props: Record<string, unknown>, container: HTMLElement): void {
        const level = ['info', 'warning', 'error', 'success'].includes(String(props.level))
            ? String(props.level)
            : 'info';

        const toast = document.createElement('div');
        toast.className = `amd-artifact__toast amd-artifact__toast--${level}`;
        toast.dataset.testid = 'askmydocs-widget-toast';
        toast.dataset.level = level;
        toast.textContent = this.safe(props.message ?? '', 512);
        container.append(toast);
    }

    /** ui-list — lista di elementi {title?, items: [{label, value?}]}. */
    private renderList(props: Record<string, unknown>, container: HTMLElement): void {
        if (props.title) {
            const heading = document.createElement('div');
            heading.className = 'amd-artifact__heading';
            heading.textContent = this.safe(props.title, 256);
            container.append(heading);
        }

        const items = Array.isArray(props.items) ? (props.items as Array<Record<string, unknown>>) : [];
        const ul = document.createElement('ul');
        ul.className = 'amd-artifact__list-items';
        ul.dataset.testid = 'askmydocs-widget-list';

        for (const item of items.slice(0, 50)) {
            const li = document.createElement('li');
            li.className = 'amd-artifact__list-item';
            if (item.value !== undefined) {
                const label = document.createElement('span');
                label.className = 'amd-artifact__list-item-label';
                label.textContent = this.safe(item.label ?? '', 256);
                const value = document.createElement('span');
                value.className = 'amd-artifact__list-item-value';
                value.textContent = this.safe(item.value, 256);
                li.append(label, ': ', value);
            } else {
                li.textContent = this.safe(item.label ?? item.text ?? '', 256);
            }
            ul.append(li);
        }

        container.append(ul);
    }

    /** ui-chart — placeholder per chart {type, title?, data}. Render statico (no lib). */
    private renderChart(props: Record<string, unknown>, container: HTMLElement): void {
        const chart = document.createElement('div');
        chart.className = 'amd-artifact__chart';
        chart.dataset.testid = 'askmydocs-widget-chart';
        chart.dataset.chartType = this.safe(props.type ?? 'bar', 32);

        if (props.title) {
            const title = document.createElement('div');
            title.className = 'amd-artifact__heading';
            title.textContent = this.safe(props.title, 256);
            chart.append(title);
        }

        // Chart richiede una libreria — per ora mostriamo un placeholder testuale
        const placeholder = document.createElement('div');
        placeholder.className = 'amd-artifact__chart-placeholder';
        placeholder.dataset.testid = 'askmydocs-widget-chart-placeholder';
        placeholder.textContent = `Grafico ${this.safe(props.type ?? 'bar', 32)} — dati disponibili`;
        chart.append(placeholder);

        container.append(chart);
    }

    /** markdown — testo formattato come markdown {content}. */
    private renderMarkdown(props: Record<string, unknown>, container: HTMLElement): void {
        const wrapper = document.createElement('div');
        wrapper.className = 'amd-artifact__markdown';
        wrapper.dataset.testid = 'askmydocs-widget-markdown';

        // Il content è sanitizzato: niente HTML raw dal BE.
        // Per ora renderizzo come testo preformattato (no parser MD);
        // un'integrazione futura con un parser MD sicuro è possibile.
        const content = document.createElement('div');
        content.className = 'amd-artifact__markdown-content';
        content.textContent = this.safe(props.content ?? '', 8192);
        wrapper.append(content);

        container.append(wrapper);
    }

    /** code-block — blocco di codice {language?, code}. */
    private renderCodeBlock(props: Record<string, unknown>, container: HTMLElement): void {
        const wrapper = document.createElement('div');
        wrapper.className = 'amd-artifact__code-block';
        wrapper.dataset.testid = 'askmydocs-widget-code-block';

        if (props.language) {
            const lang = document.createElement('div');
            lang.className = 'amd-artifact__code-lang';
            lang.textContent = this.safe(props.language, 32);
            wrapper.append(lang);
        }

        const pre = document.createElement('pre');
        pre.className = 'amd-artifact__code-content';
        pre.textContent = this.safe(props.code ?? '', 8192);
        wrapper.append(pre);

        container.append(wrapper);
    }

    /** citations — lista di citazioni {items: [{title, source_path?, origin?}]}. */
    private renderCitations(props: Record<string, unknown>, container: HTMLElement): void {
        const heading = document.createElement('div');
        heading.className = 'amd-artifact__heading';
        heading.textContent = 'Fonti';
        container.append(heading);

        const items = Array.isArray(props.items) ? (props.items as Array<Record<string, unknown>>) : [];
        if (items.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'amd-artifact__empty';
            empty.textContent = 'Nessuna citazione disponibile.';
            container.append(empty);

            return;
        }

        const ol = document.createElement('ol');
        ol.className = 'amd-artifact__citations';
        ol.dataset.testid = 'askmydocs-widget-citations';

        for (const item of items.slice(0, 20)) {
            const li = document.createElement('li');
            li.className = 'amd-artifact__citation';
            li.textContent = this.safe(item.title ?? '', 256);
            if (item.source_path) {
                const path = document.createElement('span');
                path.className = 'amd-artifact__citation-path';
                path.textContent = this.safe(item.source_path, 512);
                li.append(path);
            }
            ol.append(li);
        }

        container.append(ol);
    }

    /** Fallback generico per tipi non riconosciuti. */
    private renderGeneric(props: Record<string, unknown>, container: HTMLElement): void {
        const card = document.createElement('div');
        card.className = 'amd-artifact__card';
        card.dataset.testid = 'askmydocs-widget-artifact-generic';

        const pre = document.createElement('pre');
        pre.className = 'amd-artifact__generic-content';
        pre.textContent = this.safe(JSON.stringify(props), 2048);
        card.append(pre);

        container.append(card);
    }
}