/**
 * Snapshot security/egress guards (pentest-as-fixed-tests).
 *
 * Locks the two data-egress invariants the KITT security model relies on
 * (see docs/kitt/INTEGRATION.md §14):
 *
 *   1. `data-kitt-skip` removes a subtree from EVERY captured collection —
 *      not just actions: field VALUES, actions and outline headings of a
 *      skipped region must never appear in the snapshot that leaves the
 *      browser. This is the host site's data-egress control. The `ignored()`
 *      guard is the SAME code path for every collector, so proving it on the
 *      visibility-independent collectors (fields/actions) + a heading covers
 *      the lot.
 *   2. Sensitive inputs (password / hidden / autocomplete cc-* /
 *      current-password / new-password) have their VALUE nulled even WITHOUT
 *      `data-kitt-sensitive`, so a credential is never serialized.
 *
 * A regression here would silently egress sensitive page content to the LLM,
 * so these assertions are deliberately strict.
 *
 * jsdom note: it computes no layout, so headings/messages (which gate on
 * isVisible) need a non-zero bounding rect stub to exercise their POSITIVE
 * branch; fields/actions don't gate on visibility and need no stub.
 */
import { afterEach, describe, expect, it, vi } from 'vitest';
import { buildSnapshot } from './snapshot';

afterEach(() => {
    document.body.innerHTML = '';
    vi.restoreAllMocks();
});

describe('snapshot — data-kitt-skip excludes captured content (egress control)', () => {
    it('excludes a skipped field VALUE while keeping a sibling field', () => {
        document.body.innerHTML = `
            <aside data-kitt-skip>
                <div data-kitt-field="ssn"><input data-kitt-input type="text" name="ssn" value="123-45-6789"></div>
            </aside>
            <div data-kitt-field="name"><input data-kitt-input type="text" name="name" value="Mario"></div>
        `;
        const snap = buildSnapshot();

        // The skipped field is absent entirely…
        expect(snap.fields.map((f) => f.name)).not.toContain('ssn');
        // …and its secret value appears nowhere in the serialized snapshot.
        expect(JSON.stringify(snap)).not.toContain('123-45-6789');
        // The non-skipped field is still captured (skip is scoped, not global).
        expect(snap.fields.map((f) => f.name)).toContain('name');
    });

    it('excludes a skipped action while keeping a sibling action', () => {
        document.body.innerHTML = `
            <div data-kitt-skip>
                <button data-kitt-action="delete-all">Delete everything</button>
            </div>
            <button data-kitt-action="save">Save</button>
        `;
        const snap = buildSnapshot();
        const verbs = snap.actions.map((a) => a.verb);

        expect(verbs).not.toContain('delete-all');
        expect(verbs).toContain('save');
    });

    it('excludes a skipped heading from the page outline (sibling kept)', () => {
        // Headings gate on isVisible → give jsdom a non-zero layout so the
        // POSITIVE branch (sibling heading present) is real, not a no-op.
        vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockReturnValue({
            width: 200, height: 24, top: 0, left: 0, right: 200, bottom: 24, x: 0, y: 0, toJSON: () => ({}),
        } as DOMRect);

        document.body.innerHTML = `
            <section data-kitt-skip><h1>Confidential Q3 revenue</h1></section>
            <h2>Public help</h2>
        `;
        const snap = buildSnapshot();
        const headingTexts = snap.page_outline.headings.map((h) => h.text);

        expect(headingTexts).not.toContain('Confidential Q3 revenue');
        expect(headingTexts).toContain('Public help');
        expect(JSON.stringify(snap)).not.toContain('Confidential Q3 revenue');
    });
});

describe('snapshot — sensitive input values are never serialized', () => {
    it('nulls a password field value (no data-kitt-sensitive needed)', () => {
        document.body.innerHTML = `
            <div data-kitt-field="pwd"><input data-kitt-input type="password" name="pwd" value="hunter2"></div>
        `;
        const snap = buildSnapshot();
        const field = snap.fields.find((f) => f.name === 'pwd');

        expect(field).toBeDefined();
        expect(field?.sensitive).toBe(true);
        expect(field?.value).toBeNull();
        expect(JSON.stringify(snap)).not.toContain('hunter2');
    });

    it('nulls a credit-card field value via autocomplete=cc-number', () => {
        document.body.innerHTML = `
            <div data-kitt-field="card"><input data-kitt-input type="text" name="card" autocomplete="cc-number" value="4111111111111111"></div>
        `;
        const snap = buildSnapshot();
        const field = snap.fields.find((f) => f.name === 'card');

        expect(field?.sensitive).toBe(true);
        expect(field?.value).toBeNull();
        expect(JSON.stringify(snap)).not.toContain('4111111111111111');
    });
});
