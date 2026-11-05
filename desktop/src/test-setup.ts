import '@testing-library/jest-dom/vitest';
import { vi } from 'vitest';
Object.defineProperty(window, 'matchMedia', { value: vi.fn(() => ({ matches: false, addEventListener() {}, removeEventListener() {} })) });
Element.prototype.scrollIntoView = vi.fn();
HTMLDialogElement.prototype.showModal = function () { this.open = true; };
HTMLDialogElement.prototype.close = function () { this.open = false; };
