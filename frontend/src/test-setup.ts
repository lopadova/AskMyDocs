import '@testing-library/jest-dom/vitest';

/*
 * jsdom polyfills for Radix UI primitives (Dialog, Tabs, …) used by the
 * shadcn/ui components. jsdom omits these DOM APIs; Radix calls them
 * during focus management and layout measurement. Each is added only
 * when missing, so a future jsdom that ships them wins.
 */
if (typeof Element !== 'undefined') {
    if (!Element.prototype.hasPointerCapture) {
        Element.prototype.hasPointerCapture = () => false;
    }
    if (!Element.prototype.setPointerCapture) {
        Element.prototype.setPointerCapture = () => {};
    }
    if (!Element.prototype.releasePointerCapture) {
        Element.prototype.releasePointerCapture = () => {};
    }
    if (!Element.prototype.scrollIntoView) {
        Element.prototype.scrollIntoView = () => {};
    }
}

if (typeof globalThis.ResizeObserver === 'undefined') {
    globalThis.ResizeObserver = class {
        observe(): void {}
        unobserve(): void {}
        disconnect(): void {}
    };
}
