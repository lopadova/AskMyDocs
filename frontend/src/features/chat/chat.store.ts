import { create } from 'zustand';

/**
 * UI-only chat state. Server state (conversations, messages, citations)
 * lives in TanStack Query; this store tracks composer draft, voice
 * input toggle, graph panel visibility, and the active conversation id.
 *
 * Keep this store small — anything server-derived belongs in a query
 * cache. The one exception is optimistic user messages, which flow
 * through the mutation cache, not here.
 */
export interface ChatUiState {
    activeConversationId: number | null;
    draft: string;
    isListening: boolean;
    showGraph: boolean;
    sidebarOpen: boolean;
    setActiveConversation: (id: number | null) => void;
    setDraft: (value: string) => void;
    appendToDraft: (chunk: string) => void;
    clearDraft: () => void;
    setListening: (value: boolean) => void;
    toggleGraph: () => void;
    setSidebarOpen: (open: boolean) => void;
}

export const useChatStore = create<ChatUiState>((set) => ({
    activeConversationId: null,
    draft: '',
    isListening: false,
    showGraph: false,
    sidebarOpen: true,
    setActiveConversation: (id) => set({ activeConversationId: id, showGraph: false }),
    setDraft: (value) => set({ draft: value }),
    appendToDraft: (chunk) => set((s) => ({ draft: s.draft + chunk })),
    clearDraft: () => set({ draft: '' }),
    setListening: (value) => set({ isListening: value }),
    toggleGraph: () => set((s) => ({ showGraph: !s.showGraph })),
    setSidebarOpen: (open) => set({ sidebarOpen: open }),
}));
