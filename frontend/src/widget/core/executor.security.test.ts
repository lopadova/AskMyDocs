/**
 * Guardie di sicurezza dell'Executor (Phase 3):
 *   #2 — `type` non scrive in input password/hidden; `submit_form` non fa
 *        fallback a document.forms[0] (niente submit di form arbitrarie).
 *   #9 — `navigate_to` rifiuta gli URL protocol-relative (incl. '/\evil.com'
 *        normalizzato dai browser in '//evil.com') e gli schemi non-http(s).
 *
 * jsdom non naviga davvero: testiamo solo i RAMI DI RIFIUTO (che ritornano
 * prima di toccare location.href) e il path type su input normale.
 */
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { Executor } from './executor';

afterEach(() => {
    document.body.innerHTML = '';
});

describe('Executor — security guards (#2 / #9)', () => {
    let executor: Executor;

    beforeEach(() => {
        executor = new Executor();
    });

    it('#2 — type RIFIUTA un input password e non ne scrive il valore', async () => {
        document.body.innerHTML = `<div data-kitt-field="pwd"><input data-kitt-input type="password" name="pwd"></div>`;
        const res = await executor.run('type', { field: 'pwd', value: 'hunter2' });
        expect(res.ok).toBe(false);
        expect((document.querySelector('input') as HTMLInputElement).value).toBe('');
    });

    it('#2 — type RIFIUTA un input hidden', async () => {
        document.body.innerHTML = `<div data-kitt-field="tok"><input data-kitt-input type="hidden" name="tok"></div>`;
        const res = await executor.run('type', { field: 'tok', value: 'x' });
        expect(res.ok).toBe(false);
    });

    it('#2 — type RIFIUTA un campo carta (autocomplete=cc-number) anche se type=text', async () => {
        document.body.innerHTML = `<div data-kitt-field="card"><input data-kitt-input type="text" name="card" autocomplete="cc-number"></div>`;
        const res = await executor.run('type', { field: 'card', value: '4111111111111111' });
        expect(res.ok).toBe(false);
        expect((document.querySelector('input') as HTMLInputElement).value).toBe('');
    });

    it('#2 — type RIFIUTA un campo current-password (autocomplete)', async () => {
        document.body.innerHTML = `<div data-kitt-field="cur"><input data-kitt-input type="text" name="cur" autocomplete="current-password"></div>`;
        const res = await executor.run('type', { field: 'cur', value: 'secret' });
        expect(res.ok).toBe(false);
        expect((document.querySelector('input') as HTMLInputElement).value).toBe('');
    });

    it('#2 — type RIFIUTA un campo new-password (autocomplete)', async () => {
        document.body.innerHTML = `<div data-kitt-field="newpw"><input data-kitt-input type="text" name="newpw" autocomplete="new-password"></div>`;
        const res = await executor.run('type', { field: 'newpw', value: 'secret' });
        expect(res.ok).toBe(false);
        expect((document.querySelector('input') as HTMLInputElement).value).toBe('');
    });

    it('#2 — type accetta un input testo normale', async () => {
        document.body.innerHTML = `<div data-kitt-field="name"><input data-kitt-input type="text" name="name"></div>`;
        const res = await executor.run('type', { field: 'name', value: 'Mario' });
        expect(res.ok).toBe(true);
        expect((document.querySelector('input') as HTMLInputElement).value).toBe('Mario');
    });

    it('#9 — navigate_to RIFIUTA /\\evil.com (open redirect via backslash)', async () => {
        const res = await executor.run('navigate_to', { url: '/\\evil.com' });
        expect(res.ok).toBe(false);
    });

    it('#9 — navigate_to RIFIUTA il percent-encoding %5c', async () => {
        const res = await executor.run('navigate_to', { url: '/%5cevil.com' });
        expect(res.ok).toBe(false);
    });

    it('#9 — navigate_to RIFIUTA lo schema javascript:', async () => {
        const res = await executor.run('navigate_to', { url: 'javascript:alert(1)' });
        expect(res.ok).toBe(false);
    });

    it('#2 — submit_form NON fa fallback a document.forms[0] (form non annotata/non focalizzata)', async () => {
        document.body.innerHTML = `<form id="other"><input name="q"></form>`;
        const res = await executor.run('submit_form', {});
        expect(res.ok).toBe(false);
    });

    it('#38 — wait_for con timeout_ms non numerico non produce NaN e valuta la condizione', async () => {
        document.body.innerHTML = `<button data-kitt-action="ready">Ready</button>`;
        // Number('fast') = NaN → clampato al default 5000; la condizione è già
        // vera → ok immediato (nessun hang, nessun "not met within NaNms").
        const res = await executor.run('wait_for', { condition: 'ready', timeout_ms: 'fast' });
        expect(res.ok).toBe(true);
    });
});
