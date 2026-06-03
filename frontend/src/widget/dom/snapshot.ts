/**
 * SnapshotBuilder — legge il DOM della pagina ospite e produce lo snapshot
 * JSON che il backend passa al modello (port di KITT core/SnapshotBuilder.js,
 * spec §4). Strategia ibrida (D3): elementi annotati `data-kitt-*` quando
 * presenti, più un `page_outline` euristico per le pagine non annotate.
 *
 * Invarianti (spec §15): ogni testo passa per sanitizeText; ogni array ha un
 * cap; i field `data-kitt-sensitive` NON espongono il valore; il sottoalbero
 * del widget stesso e i `data-kitt-skip` sono ignorati.
 */
import { clean, sanitizeText } from './sanitize';
import type {
    PageOutlineButton,
    PageOutlineInput,
    Snapshot,
    SnapshotAction,
    SnapshotField,
    SnapshotMessage,
    SnapshotRegion,
} from '../types';

const CAPS = {
    regions: 50,
    fields: 500,
    actions: 200,
    messages: 50,
    locales: 20,
    label: 256,
    help: 600,
    options: 50,
    messageText: 1024,
    headings: 30,
    breadcrumbs: 6,
    buttons: 80,
    inputs: 100,
};

let counter = 0;

export function buildSnapshot(root: Document | HTMLElement = document): Snapshot {
    counter += 1;
    const scope: ParentNode = root;

    return {
        snapshot_id: `snp_${counter.toString(36)}`,
        captured_at: new Date().toISOString(),
        page: { url: location.href, title: sanitizeText(document.title) },
        viewport: {
            width: window.innerWidth,
            height: window.innerHeight,
            scrollY: Math.round(window.scrollY),
            maxScrollY: Math.max(0, document.body.scrollHeight - window.innerHeight),
        },
        active_context: activeContext(),
        regions: regions(scope),
        fields: fields(scope),
        actions: actions(scope),
        messages: messages(scope),
        locales_available: locales(scope),
        page_outline: pageOutline(scope),
    };
}

function ignored(el: Element): boolean {
    return el.closest('[data-askmydocs-widget]') !== null || el.closest('[data-kitt-skip]') !== null;
}

function isVisible(el: Element): boolean {
    if (!(el instanceof HTMLElement)) {
        return true;
    }
    if (el.hidden) {
        return false;
    }
    const style = getComputedStyle(el);
    if (style.display === 'none' || style.visibility === 'hidden') {
        return false;
    }
    const rect = el.getBoundingClientRect();

    return rect.width > 0 || rect.height > 0;
}

function attr(el: Element, name: string): string | null {
    const v = el.getAttribute(name);

    return v === null ? null : v;
}

function helpOf(el: Element): string | null {
    const raw = el.getAttribute('data-kitt-help') ?? el.getAttribute('data-kitt-hint');

    return raw === null ? null : clean(raw, CAPS.help);
}

function activeContext(): Snapshot['active_context'] {
    const activeRegion = document.querySelector('[data-kitt-region][data-kitt-active="true"]');
    const activeLocale = document.querySelector('[data-kitt-locale][data-kitt-active="true"]');
    const focus = document.activeElement?.closest('[data-kitt-field]') ?? null;
    const modal = document.querySelector('[role="dialog"], dialog[open], .modal.show, .modal[open]');

    return {
        region: activeRegion ? attr(activeRegion, 'data-kitt-region') : null,
        locale: activeLocale ? attr(activeLocale, 'data-kitt-locale') : null,
        focus_field: focus ? attr(focus, 'data-kitt-field') : null,
        modal: modal && isVisible(modal) ? (modal.id || 'dialog') : null,
    };
}

function regions(scope: ParentNode): SnapshotRegion[] {
    const out: SnapshotRegion[] = [];
    scope.querySelectorAll('[data-kitt-region]').forEach((el) => {
        if (ignored(el) || out.length >= CAPS.regions) {
            return;
        }
        out.push({
            id: attr(el, 'data-kitt-region') ?? '',
            visible: isVisible(el),
            help: helpOf(el),
            active: el.getAttribute('data-kitt-active') === 'true',
        });
    });

    return out;
}

function resolveInput(wrapper: Element): HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement | null {
    if (wrapper instanceof HTMLInputElement || wrapper instanceof HTMLSelectElement || wrapper instanceof HTMLTextAreaElement) {
        return wrapper;
    }
    const marked = wrapper.querySelector('[data-kitt-input]');
    if (marked instanceof HTMLInputElement || marked instanceof HTMLSelectElement || marked instanceof HTMLTextAreaElement) {
        return marked;
    }
    const first = wrapper.querySelector('input, select, textarea');

    return first instanceof HTMLInputElement || first instanceof HTMLSelectElement || first instanceof HTMLTextAreaElement
        ? first
        : null;
}

function fieldType(input: HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement, wrapper: Element): string {
    if (input instanceof HTMLTextAreaElement) {
        return 'textarea';
    }
    if (input instanceof HTMLSelectElement) {
        return input.multiple ? 'select-multi' : 'select';
    }
    if (wrapper.hasAttribute('data-kitt-options-source') || input.getAttribute('role') === 'combobox') {
        return 'combobox-async';
    }

    return input.type || 'text';
}

function fieldValue(input: HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement): string | string[] | boolean {
    if (input instanceof HTMLInputElement && input.type === 'checkbox') {
        return input.checked;
    }
    if (input instanceof HTMLSelectElement && input.multiple) {
        return Array.from(input.selectedOptions).map((o) => o.value);
    }

    return input.value;
}

function labelFor(wrapper: Element, input: Element | null): string {
    const id = input?.id;
    if (id) {
        const lbl = document.querySelector(`label[for="${CSS.escape(id)}"]`);
        if (lbl) {
            return clean(lbl.textContent, CAPS.label);
        }
    }
    const inner = wrapper.querySelector('label');
    if (inner) {
        return clean(inner.textContent, CAPS.label);
    }

    return clean(input?.getAttribute('aria-label') ?? '', CAPS.label);
}

function fields(scope: ParentNode): SnapshotField[] {
    const out: SnapshotField[] = [];
    scope.querySelectorAll('[data-kitt-field]').forEach((el) => {
        if (ignored(el) || out.length >= CAPS.fields) {
            return;
        }
        const input = resolveInput(el);
        const sensitive = el.hasAttribute('data-kitt-sensitive');
        const region = el.closest('[data-kitt-region]');

        let options: Array<{ value: string; label: string }> | null = null;
        if (input instanceof HTMLSelectElement && !el.hasAttribute('data-kitt-options-source')) {
            options = Array.from(input.options)
                .slice(0, CAPS.options)
                .map((o) => ({ value: o.value, label: clean(o.label, CAPS.label) }));
        }

        const value = !input ? null : sensitive ? null : fieldValue(input);

        out.push({
            name: attr(el, 'data-kitt-field') ?? '',
            label: labelFor(el, input),
            type: input ? fieldType(input, el) : 'unknown',
            required: el.hasAttribute('data-kitt-required') || (input as HTMLInputElement | null)?.required === true,
            visible: isVisible(el),
            value,
            filled: value !== null && value !== '' && value !== false,
            sensitive,
            options,
            help: helpOf(el),
            region: region ? attr(region, 'data-kitt-region') : null,
        });
    });

    return out;
}

function actions(scope: ParentNode): SnapshotAction[] {
    const out: SnapshotAction[] = [];
    scope.querySelectorAll('[data-kitt-action]').forEach((el) => {
        if (ignored(el) || out.length >= CAPS.actions) {
            return;
        }
        const disabled =
            (el as HTMLButtonElement).disabled === true ||
            el.getAttribute('aria-disabled') === 'true' ||
            el.classList.contains('disabled');
        const verb = attr(el, 'data-kitt-action') ?? '';
        out.push({
            verb,
            label: clean(el.textContent || el.getAttribute('aria-label') || verb, CAPS.label),
            enabled: !disabled,
            reason_disabled: disabled ? attr(el, 'data-kitt-reason-disabled') : null,
            help: helpOf(el),
        });
    });

    return out;
}

function messages(scope: ParentNode): SnapshotMessage[] {
    const out: SnapshotMessage[] = [];
    scope.querySelectorAll('[data-kitt-message]').forEach((el) => {
        if (ignored(el) || !isVisible(el) || out.length >= CAPS.messages) {
            return;
        }
        out.push({
            level: attr(el, 'data-kitt-message') ?? 'info',
            text: clean(el.textContent, CAPS.messageText),
        });
    });

    return out;
}

function locales(scope: ParentNode): string[] {
    const set = new Set<string>();
    scope.querySelectorAll('[data-kitt-locale]').forEach((el) => {
        const code = attr(el, 'data-kitt-locale');
        if (code && set.size < CAPS.locales) {
            set.add(code);
        }
    });

    return Array.from(set);
}

function pageOutline(scope: ParentNode): Snapshot['page_outline'] {
    const headings: Array<{ level: number; text: string }> = [];
    scope.querySelectorAll('h1, h2, h3').forEach((el) => {
        if (ignored(el) || !isVisible(el) || headings.length >= CAPS.headings) {
            return;
        }
        const text = clean(el.textContent, CAPS.label);
        if (text !== '') {
            headings.push({ level: Number(el.tagName.slice(1)), text });
        }
    });

    const breadcrumbs: string[] = [];
    scope.querySelectorAll('[aria-label="breadcrumb"] a, [aria-label="breadcrumb"] li, .breadcrumb a, .breadcrumb li').forEach((el) => {
        if (ignored(el) || breadcrumbs.length >= CAPS.breadcrumbs) {
            return;
        }
        const text = clean(el.textContent, CAPS.label);
        if (text !== '') {
            breadcrumbs.push(text);
        }
    });

    const buttons: PageOutlineButton[] = [];
    scope.querySelectorAll('button, [role="button"], a.btn, a.ui-btn').forEach((el) => {
        if (ignored(el) || el.hasAttribute('data-kitt-action') || buttons.length >= CAPS.buttons) {
            return;
        }
        const text = clean(el.textContent, CAPS.label);
        const testid = attr(el, 'data-testid');
        if (text === '' && !testid) {
            return;
        }
        buttons.push({
            text,
            id: el.id || null,
            testid,
            disabled: (el as HTMLButtonElement).disabled === true || el.getAttribute('aria-disabled') === 'true',
        });
    });

    const inputs: PageOutlineInput[] = [];
    scope.querySelectorAll('input, select, textarea').forEach((el) => {
        if (ignored(el) || el.closest('[data-kitt-field]') || inputs.length >= CAPS.inputs) {
            return;
        }
        if (el instanceof HTMLInputElement && el.type === 'hidden') {
            return;
        }
        inputs.push({
            type: el instanceof HTMLInputElement ? el.type : el.tagName.toLowerCase(),
            name: (el as HTMLInputElement).name || null,
            testid: attr(el, 'data-testid'),
            label: labelFor(el.parentElement ?? el, el),
            visible: isVisible(el),
        });
    });

    return {
        url: location.href,
        title: sanitizeText(document.title),
        headings,
        breadcrumbs,
        buttons_unannotated: buttons,
        inputs_unannotated: inputs,
    };
}
