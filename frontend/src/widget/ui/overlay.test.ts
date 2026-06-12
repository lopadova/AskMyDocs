/**
 * Test dell'OverlaySystem (M4.8 — feedback visivo agentico). Gira in jsdom:
 * jsdom non fa layout, quindi `getBoundingClientRect` torna zeri — montiamo un
 * target reale e mockiamo il suo rect dove serve. Verifichiamo che:
 *   - tourStep crei backdrop + spotlight + cursor + tooltip col messaggio e "1/3";
 *   - clear() rimuova tutto e stacchi i listener scroll/resize;
 *   - pointAt crei la freccia SENZA backdrop;
 *   - i listener di reflow siano agganciati una sola volta e ripuliti.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { OverlaySystem } from './overlay';

/** Crea un target reale e gli dà un rect misurabile (jsdom non fa layout). */
function makeTarget(rect: Partial<DOMRect> = {}): HTMLElement {
    const el = document.createElement('button');
    el.textContent = 'Salva';
    document.body.appendChild(el);
    const r: DOMRect = {
        x: 100, y: 200, top: 200, left: 100, right: 240, bottom: 240,
        width: 140, height: 40, toJSON: () => ({}),
        ...rect,
    } as DOMRect;
    el.getBoundingClientRect = () => r;
    el.scrollIntoView = vi.fn();

    return el;
}

function overlayHost(): HTMLElement | null {
    return document.querySelector('.amd-overlay');
}

describe('OverlaySystem', () => {
    let overlay: OverlaySystem;

    beforeEach(() => {
        overlay = new OverlaySystem();
    });

    afterEach(() => {
        overlay.clear();
        document.body.replaceChildren();
        document.getElementById('amd-overlay-styles')?.remove();
    });

    it('tourStep monta backdrop + spotlight + cursor + tooltip con messaggio e step "1/3"', () => {
        const target = makeTarget();
        overlay.tourStep(target, 'Clicca qui per salvare', 0, 3);

        const host = overlayHost();
        expect(host).not.toBeNull();
        expect(host?.querySelector('.amd-backdrop')).not.toBeNull();
        expect(host?.querySelector('.amd-spotlight')).not.toBeNull();
        expect(host?.querySelector('.amd-cursor')).not.toBeNull();

        const tooltip = host?.querySelector('.amd-tooltip');
        expect(tooltip).not.toBeNull();
        expect(tooltip?.querySelector('.amd-tooltip-body')?.textContent).toBe('Clicca qui per salvare');
        // index 0-based + 1 → "1/3"
        expect(tooltip?.querySelector('.amd-tooltip-step')?.textContent).toBe('1/3');
    });

    it('tourStep posiziona lo spotlight sul rect del target (con padding)', () => {
        const target = makeTarget({ top: 200, left: 100, width: 140, height: 40 });
        overlay.tourStep(target, 'msg', 0, 1);

        const spot = overlayHost()?.querySelector('.amd-spotlight') as HTMLElement;
        // padding di 8px attorno al rect
        expect(spot.style.top).toBe('192px');
        expect(spot.style.left).toBe('92px');
        expect(spot.style.width).toBe('156px');
        expect(spot.style.height).toBe('56px');
        expect(spot.style.display).toBe('block');
    });

    it('un secondo tourStep aggiorna in-place messaggio e step senza duplicare gli elementi', () => {
        const t1 = makeTarget();
        overlay.tourStep(t1, 'Primo passo', 0, 3);
        const t2 = makeTarget({ top: 400 });
        overlay.tourStep(t2, 'Secondo passo', 1, 3);

        const host = overlayHost();
        expect(host?.querySelectorAll('.amd-spotlight')).toHaveLength(1);
        expect(host?.querySelectorAll('.amd-tooltip')).toHaveLength(1);
        expect(host?.querySelector('.amd-tooltip-body')?.textContent).toBe('Secondo passo');
        expect(host?.querySelector('.amd-tooltip-step')?.textContent).toBe('2/3');
    });

    it('pointAt crea la freccia/cursore SENZA backdrop ne tooltip', () => {
        const target = makeTarget();
        overlay.pointAt(target);

        const host = overlayHost();
        expect(host).not.toBeNull();
        expect(host?.querySelector('.amd-cursor')).not.toBeNull();
        expect(host?.querySelector('.amd-cursor svg')).not.toBeNull();
        expect(host?.querySelector('.amd-backdrop')).toBeNull();
        expect(host?.querySelector('.amd-tooltip')).toBeNull();
    });

    it('passare da tour a pointAt rimuove backdrop/spotlight/tooltip e tiene solo la freccia', () => {
        const target = makeTarget();
        overlay.tourStep(target, 'msg', 0, 2);
        expect(overlayHost()?.querySelector('.amd-backdrop')).not.toBeNull();

        overlay.pointAt(makeTarget({ top: 500 }));
        const host = overlayHost();
        expect(host?.querySelector('.amd-backdrop')).toBeNull();
        expect(host?.querySelector('.amd-spotlight')).toBeNull();
        expect(host?.querySelector('.amd-tooltip')).toBeNull();
        expect(host?.querySelector('.amd-cursor')).not.toBeNull();
    });

    it('clear() rimuove l host dell overlay e tutti i suoi elementi', () => {
        overlay.tourStep(makeTarget(), 'msg', 0, 1);
        expect(overlayHost()).not.toBeNull();

        overlay.clear();
        expect(overlayHost()).toBeNull();
        expect(document.querySelector('.amd-backdrop')).toBeNull();
        expect(document.querySelector('.amd-cursor')).toBeNull();
    });

    it('pointAt(null) pulisce l overlay invece di mostrare una freccia orfana', () => {
        overlay.tourStep(makeTarget(), 'msg', 0, 1);
        overlay.pointAt(null);
        expect(overlayHost()).toBeNull();
    });

    it('aggancia i listener scroll/resize e li stacca in clear() (cleanup)', () => {
        const addSpy = vi.spyOn(window, 'addEventListener');
        const removeSpy = vi.spyOn(window, 'removeEventListener');

        overlay.tourStep(makeTarget(), 'msg', 0, 1);
        const scrollAdds = addSpy.mock.calls.filter((c) => c[0] === 'scroll');
        const resizeAdds = addSpy.mock.calls.filter((c) => c[0] === 'resize');
        expect(scrollAdds).toHaveLength(1);
        expect(resizeAdds).toHaveLength(1);

        overlay.clear();
        const scrollRemoves = removeSpy.mock.calls.filter((c) => c[0] === 'scroll');
        const resizeRemoves = removeSpy.mock.calls.filter((c) => c[0] === 'resize');
        expect(scrollRemoves).toHaveLength(1);
        expect(resizeRemoves).toHaveLength(1);

        addSpy.mockRestore();
        removeSpy.mockRestore();
    });

    it('non duplica i listener quando tourStep e chiamato piu volte', () => {
        const addSpy = vi.spyOn(window, 'addEventListener');
        overlay.tourStep(makeTarget(), 'a', 0, 2);
        overlay.tourStep(makeTarget({ top: 300 }), 'b', 1, 2);
        const scrollAdds = addSpy.mock.calls.filter((c) => c[0] === 'scroll');
        expect(scrollAdds).toHaveLength(1);
        addSpy.mockRestore();
    });

    it('riposiziona lo spotlight su scroll (rect aggiornato del target)', () => {
        const target = makeTarget({ top: 200 });
        overlay.tourStep(target, 'msg', 0, 1);
        const spot = overlayHost()?.querySelector('.amd-spotlight') as HTMLElement;
        expect(spot.style.top).toBe('192px');

        // Il target "scrolla" verso l'alto: nuovo rect, poi evento scroll.
        target.getBoundingClientRect = () =>
            ({ x: 100, y: 50, top: 50, left: 100, right: 240, bottom: 90, width: 140, height: 40, toJSON: () => ({}) }) as DOMRect;
        window.dispatchEvent(new Event('scroll'));
        expect(spot.style.top).toBe('42px');
    });

    it('tour senza target misurabile mostra il backdrop pieno e nasconde lo spotlight', () => {
        // target null → backdrop a schermo intero, spotlight nascosto, tooltip al centro.
        overlay.tourStep(null, 'Benvenuto nel tour', 0, 2);
        const host = overlayHost();
        expect(host?.querySelector('.amd-backdrop')).not.toBeNull();
        const spot = host?.querySelector('.amd-spotlight') as HTMLElement;
        expect(spot.style.display).toBe('none');
        expect(host?.querySelector('.amd-tooltip-body')?.textContent).toBe('Benvenuto nel tour');
    });

    it('clamp dello step: total piu piccolo di index+1 non produce "2/1"', () => {
        overlay.tourStep(makeTarget(), 'msg', 1, 1);
        // index+1 = 2, total = 1 → clamp del denominatore a 2 → "2/2"
        expect(overlayHost()?.querySelector('.amd-tooltip-step')?.textContent).toBe('2/2');
    });

    it('il backdrop cattura i click (pointer-events auto) ma il wrapper no (none)', () => {
        overlay.tourStep(makeTarget(), 'msg', 0, 1);
        const host = overlayHost() as HTMLElement;
        const backdrop = host.querySelector('.amd-backdrop') as HTMLElement;
        // Wrapper trasparente ai click; il backdrop li intercetta per non far
        // interagire con la pagina dimmata. Nessun tabindex → niente focus trap.
        expect(host.style.pointerEvents).toBe('none');
        expect(backdrop.style.pointerEvents).toBe('auto');
        expect(host.hasAttribute('tabindex')).toBe(false);
        expect(backdrop.hasAttribute('tabindex')).toBe(false);
    });
});
