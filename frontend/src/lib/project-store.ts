import { create } from 'zustand';

/*
 * Active-project store — the single source of truth for "which project is
 * currently selected" across the whole authenticated shell.
 *
 * Before this, two places disagreed:
 *   - AppShell/Topbar ProjectSwitcher kept the selection in AppShell-local
 *     state (`projectIndex`), and
 *   - ChatView hard-read `PROJECTS[0]` from the seed mock,
 * so changing the project in the topbar never affected the chat scope, and
 * the chat always claimed "HR Portal" regardless of the user's real
 * memberships. This store lifts the selection above both so the switcher
 * (writer) and the chat (reader) share ONE value.
 *
 * `activeProjectKey === null` means "no project chosen yet" — callers treat
 * it as unscoped until the shell hydrates the first real membership.
 */
export interface ActiveProjectState {
    activeProjectKey: string | null;
    setActiveProject: (key: string | null) => void;
}

export const useProjectStore = create<ActiveProjectState>((set) => ({
    activeProjectKey: null,
    setActiveProject: (key) => set({ activeProjectKey: key }),
}));
