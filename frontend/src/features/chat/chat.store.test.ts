import { describe, it, expect, beforeEach } from 'vitest';
import { useChatStore } from './chat.store';

describe('useChatStore', () => {
    beforeEach(() => {
        useChatStore.setState({
            activeConversationId: null,
            draft: '',
            isListening: false,
            showGraph: false,
            sidebarOpen: true,
        });
    });

    it('tracks active conversation and resets graph visibility', () => {
        const { setActiveConversation, toggleGraph } = useChatStore.getState();
        toggleGraph();
        expect(useChatStore.getState().showGraph).toBe(true);
        setActiveConversation(42);
        expect(useChatStore.getState().activeConversationId).toBe(42);
        expect(useChatStore.getState().showGraph).toBe(false);
    });

    it('accumulates composer draft via appendToDraft', () => {
        const { setDraft, appendToDraft, clearDraft } = useChatStore.getState();
        setDraft('hello');
        appendToDraft(' world');
        expect(useChatStore.getState().draft).toBe('hello world');
        clearDraft();
        expect(useChatStore.getState().draft).toBe('');
    });

    it('toggles listening and graph flags independently', () => {
        const { setListening, toggleGraph } = useChatStore.getState();
        setListening(true);
        expect(useChatStore.getState().isListening).toBe(true);
        expect(useChatStore.getState().showGraph).toBe(false);
        toggleGraph();
        expect(useChatStore.getState().showGraph).toBe(true);
        expect(useChatStore.getState().isListening).toBe(true);
    });
});
