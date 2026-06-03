import { describe, it, expect, beforeEach, vi } from 'vitest';
import { Observer } from './Observer';
import { AutoAnnotator, type AutoAnnotationRule } from './AutoAnnotator';

// flushMicrotasks helper — usato indirettamente via vi.runAllTimersAsync

describe('Observer', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    // Helper: crea Observer con AutoAnnotator vuoto
    function createObserver(callbacks: { onStale?: () => void } = {}): Observer {
        const rules: AutoAnnotationRule[] = [];
        const annotator = new AutoAnnotator(rules);

        return new Observer(annotator, callbacks);
    }

    // --- Lifecycle ---

    it('can start and stop without errors', () => {
        const observer = createObserver();
        observer.start();
        observer.stop();
    });

    // --- Stale flag ---

    it('starts with stale=false', () => {
        const observer = createObserver();
        expect(observer.isStale()).toBe(false);
    });

    it('marks stale on focusin event', async () => {
        vi.useFakeTimers();
        const observer = createObserver();
        observer.start();

        const input = document.createElement('input');
        document.body.append(input);
        input.dispatchEvent(new Event('focusin', { bubbles: true }));

        await vi.advanceTimersByTimeAsync(200);
        expect(observer.isStale()).toBe(true);

        vi.useRealTimers();
        observer.stop();
    });

    it('marks stale on input event', async () => {
        vi.useFakeTimers();
        const observer = createObserver();
        observer.start();

        const input = document.createElement('input');
        document.body.append(input);
        input.dispatchEvent(new Event('input', { bubbles: true }));

        await vi.advanceTimersByTimeAsync(200);
        expect(observer.isStale()).toBe(true);

        vi.useRealTimers();
        observer.stop();
    });

    it('marks stale on change event', async () => {
        vi.useFakeTimers();
        const observer = createObserver();
        observer.start();

        const input = document.createElement('input');
        document.body.append(input);
        input.dispatchEvent(new Event('change', { bubbles: true }));

        await vi.advanceTimersByTimeAsync(200);
        expect(observer.isStale()).toBe(true);

        vi.useRealTimers();
        observer.stop();
    });

    it('marks stale on scroll event', async () => {
        vi.useFakeTimers();
        const observer = createObserver();
        observer.start();

        window.dispatchEvent(new Event('scroll'));

        await vi.advanceTimersByTimeAsync(200);
        expect(observer.isStale()).toBe(true);

        vi.useRealTimers();
        observer.stop();
    });

    // --- MutationObserver attribute changes ---

    it('marks stale on observed attribute mutations', async () => {
        vi.useFakeTimers();
        const observer = createObserver();
        observer.start();

        // Crea elemento, poi muta un attributo osservato
        const div = document.createElement('div');
        document.body.append(div);
        div.setAttribute('data-kitt-active', 'true');

        await vi.advanceTimersByTimeAsync(300);
        expect(observer.isStale()).toBe(true);

        vi.useRealTimers();
        observer.stop();
    });

    it('calls onStale callback when snapshot becomes stale', async () => {
        vi.useFakeTimers();
        const onStale = vi.fn();
        const observer = createObserver({ onStale });
        observer.start();

        const input = document.createElement('input');
        document.body.append(input);
        input.dispatchEvent(new Event('input', { bubbles: true }));

        await vi.advanceTimersByTimeAsync(300);
        expect(onStale).toHaveBeenCalled();

        vi.useRealTimers();
        observer.stop();
    });

    it('invalidates on data-kitt-completed change', async () => {
        vi.useFakeTimers();
        const observer = createObserver();
        observer.start();

        const step = document.createElement('div');
        document.body.append(step);
        step.setAttribute('data-kitt-completed', 'true');

        await vi.advanceTimersByTimeAsync(300);
        expect(observer.isStale()).toBe(true);

        vi.useRealTimers();
        observer.stop();
    });

    it('invalidates on data-kitt-locale change', async () => {
        vi.useFakeTimers();
        const observer = createObserver();
        observer.start();

        const locale = document.createElement('div');
        document.body.append(locale);
        locale.setAttribute('data-kitt-locale', 'en');

        await vi.advanceTimersByTimeAsync(300);
        expect(observer.isStale()).toBe(true);

        vi.useRealTimers();
        observer.stop();
    });

    it('invalidates on hidden attribute change', async () => {
        vi.useFakeTimers();
        const observer = createObserver();
        observer.start();

        const el = document.createElement('div');
        document.body.append(el);
        el.setAttribute('hidden', '');

        await vi.advanceTimersByTimeAsync(300);
        expect(observer.isStale()).toBe(true);

        vi.useRealTimers();
        observer.stop();
    });

    it('invalidates on disabled attribute change', async () => {
        vi.useFakeTimers();
        const observer = createObserver();
        observer.start();

        const btn = document.createElement('button');
        document.body.append(btn);
        btn.setAttribute('disabled', '');

        await vi.advanceTimersByTimeAsync(300);
        expect(observer.isStale()).toBe(true);

        vi.useRealTimers();
        observer.stop();
    });

    // --- Reset stale ---

    it('resetStale clears the stale flag', async () => {
        vi.useFakeTimers();
        const observer = createObserver();
        observer.start();

        const input = document.createElement('input');
        document.body.append(input);
        input.dispatchEvent(new Event('input', { bubbles: true }));

        await vi.advanceTimersByTimeAsync(300);
        expect(observer.isStale()).toBe(true);

        observer.resetStale();
        expect(observer.isStale()).toBe(false);

        vi.useRealTimers();
        observer.stop();
    });

    // --- Cleanup ---

    it('stop disconnects MutationObserver and removes listeners', () => {
        const observer = createObserver();
        observer.start();
        observer.stop();

        // Dopo stop, nuovi eventi non invalidano stale
        const input = document.createElement('input');
        document.body.append(input);
        input.dispatchEvent(new Event('input', { bubbles: true }));

        // Non c'è debounce attivo, stale resta false
        expect(observer.isStale()).toBe(false);
    });

    // --- AutoAnnotator integration ---

    it('applies AutoAnnotator rules on start', () => {
        const rules: AutoAnnotationRule[] = [
            { selector: 'button', attrs: { 'data-kitt-action': 'test' } },
        ];
        const annotator = new AutoAnnotator(rules);
        const observer = new Observer(annotator);

        document.body.innerHTML = '<button id="btn">Click</button>';
        observer.start();

        const btn = document.getElementById('btn')!;
        expect(btn.getAttribute('data-kitt-action')).toBe('test');

        observer.stop();
    });
});