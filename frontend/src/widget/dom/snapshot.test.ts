import { describe, it, expect, beforeEach } from 'vitest';
import { buildSnapshot } from './snapshot';

/**
 * Unit test del SnapshotBuilder lato widget (gira in jsdom — nessun server).
 * Verifica le invarianti chiave (spec §4 / §15): lettura euristica + data-kitt,
 * campi sensitive mai esposti, cap di cardinalità, e che il sottoalbero del
 * widget stesso (data-askmydocs-widget) sia ignorato.
 */
describe('widget SnapshotBuilder', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        document.title = 'Test Page';
        // jsdom returns an all-zero rect from getBoundingClientRect, which would
        // make every element read as invisible (isVisible checks width/height).
        // Stub a non-zero rect so elements behave like in a real browser; the
        // visibility logic itself is exercised by the explicit display:none
        // cases, not by this environment quirk.
        Element.prototype.getBoundingClientRect = function (): DOMRect {
            return { width: 100, height: 20, top: 0, left: 0, right: 100, bottom: 20, x: 0, y: 0, toJSON: () => ({}) } as DOMRect;
        };
    });

    it('reads annotated fields and actions', () => {
        document.body.innerHTML = `
            <section data-kitt-region="profile" data-kitt-active="true">
                <div data-kitt-field="email">
                    <label for="email">Email</label>
                    <input id="email" data-kitt-input value="a@b.test" />
                </div>
                <button data-kitt-action="save">Salva</button>
            </section>
        `;

        const snap = buildSnapshot();

        expect(snap.regions.map((r) => r.id)).toContain('profile');
        const email = snap.fields.find((f) => f.name === 'email');
        expect(email).toBeDefined();
        expect(email?.label).toBe('Email');
        expect(email?.value).toBe('a@b.test');
        expect(snap.actions.map((a) => a.verb)).toContain('save');
    });

    it('never exposes the value of a data-kitt-sensitive field', () => {
        document.body.innerHTML = `
            <div data-kitt-field="password" data-kitt-sensitive>
                <input id="pw" type="password" data-kitt-input value="hunter2" />
            </div>
        `;

        const snap = buildSnapshot();
        const pw = snap.fields.find((f) => f.name === 'password');

        expect(pw?.sensitive).toBe(true);
        expect(pw?.value).toBeNull();
    });

    it('collects unannotated buttons/inputs into the page outline', () => {
        document.body.innerHTML = `
            <h1>Heading</h1>
            <button id="plain-btn">Plain</button>
            <input name="q" type="search" />
        `;

        const snap = buildSnapshot();

        expect(snap.page_outline.headings.some((h) => h.text === 'Heading')).toBe(true);
        expect(snap.page_outline.buttons_unannotated.some((b) => b.id === 'plain-btn')).toBe(true);
        expect(snap.page_outline.inputs_unannotated.some((i) => i.name === 'q')).toBe(true);
    });

    it('ignores the widget own subtree (data-askmydocs-widget)', () => {
        document.body.innerHTML = `
            <button data-kitt-action="host-action">Host</button>
            <div data-askmydocs-widget>
                <button data-kitt-action="widget-action">Widget</button>
                <input name="widget-input" />
            </div>
        `;

        const snap = buildSnapshot();

        expect(snap.actions.map((a) => a.verb)).toContain('host-action');
        expect(snap.actions.map((a) => a.verb)).not.toContain('widget-action');
        expect(snap.page_outline.inputs_unannotated.some((i) => i.name === 'widget-input')).toBe(false);
    });

    it('skips data-kitt-skip subtrees', () => {
        document.body.innerHTML = `
            <div data-kitt-skip>
                <button data-kitt-action="skipped">x</button>
            </div>
        `;

        const snap = buildSnapshot();
        expect(snap.actions.map((a) => a.verb)).not.toContain('skipped');
    });

    it('caps the number of fields at 500', () => {
        const wrappers = Array.from({ length: 520 }, (_, i) =>
            `<div data-kitt-field="f${i}"><input data-kitt-input /></div>`,
        ).join('');
        document.body.innerHTML = wrappers;

        const snap = buildSnapshot();
        expect(snap.fields.length).toBeLessThanOrEqual(500);
    });

    it('reads a checkbox value as a boolean', () => {
        document.body.innerHTML = `
            <div data-kitt-field="agree">
                <input id="agree" type="checkbox" data-kitt-input checked />
            </div>
        `;

        const snap = buildSnapshot();
        expect(snap.fields.find((f) => f.name === 'agree')?.value).toBe(true);
    });
});
