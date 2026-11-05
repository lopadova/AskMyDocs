import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ResizableChatHistory } from './ResizableChatHistory';

let notifyResize: () => void;
let availableWidth = 1200;

beforeEach(() => {
    localStorage.removeItem('chat-history-width');
    availableWidth = 1200;
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(() => ({ width: availableWidth }) as DOMRect);
    vi.stubGlobal('ResizeObserver', class {
        constructor(callback: () => void) { notifyResize = callback; }
        observe() {} disconnect() {}
    });
    vi.stubGlobal('PointerEvent', class extends MouseEvent {
        pointerId: number;
        constructor(type: string, init: PointerEventInit = {}) { super(type, init); this.pointerId = init.pointerId ?? 1; }
    });
});
afterEach(() => { vi.restoreAllMocks(); vi.unstubAllGlobals(); });

function rail() {
    return render(<ResizableChatHistory><aside className="chat-conversation-sidebar">Chat list</aside></ResizableChatHistory>);
}

describe('ResizableChatHistory', () => {
    it('supports keyboard limits, reset and a persisted width after remounting', () => {
        const view = rail();
        const handle = screen.getByRole('separator', { name: 'Resize chat list' });
        expect(handle).toHaveAttribute('aria-valuenow', '280');
        expect(document.getElementById(handle.getAttribute('aria-controls')!)).toHaveTextContent('Chat list');
        fireEvent.keyDown(handle, { key: 'Home' });
        fireEvent.keyDown(handle, { key: 'ArrowLeft' });
        expect(handle).toHaveAttribute('aria-valuenow', '240');
        fireEvent.keyDown(handle, { key: 'End' });
        fireEvent.keyDown(handle, { key: 'ArrowRight' });
        expect(handle).toHaveAttribute('aria-valuenow', '480');
        expect(localStorage.getItem('chat-history-width')).toBe('480');
        view.unmount();
        rail();
        const restored = screen.getByRole('separator');
        expect(restored).toHaveAttribute('aria-valuenow', '480');
        fireEvent.doubleClick(restored);
        expect(restored).toHaveAttribute('aria-valuenow', '280');
        expect(localStorage.getItem('chat-history-width')).toBe('280');
    });

    it('clamps a pointer drag and writes the preference only when it finishes', () => {
        rail();
        const handle = screen.getByRole('separator');
        fireEvent.pointerDown(handle, { pointerId: 7, button: 0, clientX: 280 });
        fireEvent.pointerMove(handle, { pointerId: 8, clientX: 500 });
        expect(handle).toHaveAttribute('aria-valuenow', '280');
        fireEvent.pointerMove(handle, { pointerId: 7, clientX: 900 });
        expect(handle).toHaveAttribute('aria-valuenow', '480');
        expect(localStorage.getItem('chat-history-width')).toBeNull();
        fireEvent.pointerMove(handle, { pointerId: 7, clientX: 100 });
        expect(handle).toHaveAttribute('aria-valuenow', '240');
        fireEvent.pointerUp(handle, { pointerId: 7 });
        expect(localStorage.getItem('chat-history-width')).toBe('240');
        expect(document.documentElement).not.toHaveClass('chat-history-is-resizing');
    });

    it('preserves room for the thread when the workspace shrinks and restores the preferred width', () => {
        localStorage.setItem('chat-history-width', '480');
        rail();
        const handle = screen.getByRole('separator');
        act(() => { availableWidth = 800; notifyResize(); });
        expect(handle).toHaveAttribute('aria-valuemax', '320');
        expect(handle).toHaveAttribute('aria-valuenow', '320');
        expect(localStorage.getItem('chat-history-width')).toBe('480');
        act(() => { availableWidth = 1200; notifyResize(); });
        expect(handle).toHaveAttribute('aria-valuenow', '480');
    });

    it('cancels a gesture without persisting it and cleans up on unmount', () => {
        const view = rail();
        const handle = screen.getByRole('separator');
        fireEvent.pointerDown(handle, { button: 0, clientX: 280 });
        fireEvent.pointerMove(handle, { clientX: 400 });
        fireEvent.pointerCancel(handle);
        expect(handle).toHaveAttribute('aria-valuenow', '280');
        expect(localStorage.getItem('chat-history-width')).toBeNull();
        expect(document.documentElement).not.toHaveClass('chat-history-is-resizing');
        fireEvent.pointerDown(handle, { button: 0, clientX: 280 });
        view.unmount();
        expect(document.documentElement).not.toHaveClass('chat-history-is-resizing');
    });

    it('ignores invalid stored preferences and works when storage is unavailable', () => {
        vi.stubGlobal('localStorage', {
            getItem: (key: string) => key === 'chat-history-width' ? 'Infinity' : 'en',
            setItem: () => { throw new Error('Storage unavailable'); },
        });
        rail();
        const handle = screen.getByRole('separator');
        expect(handle).toHaveAttribute('aria-valuenow', '280');
        expect(() => fireEvent.keyDown(handle, { key: 'ArrowRight' })).not.toThrow();
        expect(handle).toHaveAttribute('aria-valuenow', '296');
    });
});
