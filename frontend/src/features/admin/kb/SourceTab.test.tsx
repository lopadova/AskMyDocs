import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { EditorView } from '@codemirror/view';

/*
 * PR10 / Phase G3 — SourceTab Vitest scenarios.
 *
 * We stub useKbRaw + useUpdateKbRaw so the component's state machine
 * can be exercised without a network or a real CodeMirror mount. The
 * CodeMirror-specific assertions are delegated to the Playwright
 * suite where a real DOM runs — jsdom's approximation of contenteditable
 * would make keystroke simulation brittle.
 */

type RawState = {
    data: { content: string; path: string; disk: string; mime: string; content_hash: string } | undefined;
    isLoading: boolean;
    isError: boolean;
};

const rawState: RawState = {
    data: undefined,
    isLoading: false,
    isError: false,
};

const mutationState: {
    isPending: boolean;
    mutate: ReturnType<typeof vi.fn>;
} = {
    isPending: false,
    mutate: vi.fn(),
};

vi.mock('./kb-document.api', () => ({
    useKbRaw: () => ({
        data: rawState.isError ? undefined : rawState.data,
        isLoading: rawState.isLoading,
        isError: rawState.isError,
    }),
    useUpdateKbRaw: () => ({
        isPending: mutationState.isPending,
        mutate: mutationState.mutate,
    }),
}));

// ToastHost uses a module-level singleton; we don't need to assert on
// toasts here (Playwright covers that). Mock so the module doesn't
// pull in unrelated DOM behaviour.
vi.mock('../shared/Toast', () => ({
    useToast: () => ({
        success: vi.fn(),
        error: vi.fn(),
        info: vi.fn(),
    }),
    ToastHost: () => null,
    pushToast: vi.fn(),
    dismissToast: vi.fn(),
}));

import { SourceTab, computeLineDiff } from './SourceTab';

function wrap(ui: React.ReactElement) {
    const qc = new QueryClient({
        defaultOptions: { queries: { retry: false } },
    });
    return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>);
}

function seedRaw(content = '# Hello\n\nBody here.\n') {
    rawState.data = {
        content,
        path: 'docs/hello.md',
        disk: 'kb',
        mime: 'text/markdown',
        content_hash: 'hash-' + content.length,
    };
    rawState.isLoading = false;
    rawState.isError = false;
}

beforeEach(() => {
    rawState.data = undefined;
    rawState.isLoading = false;
    rawState.isError = false;
    mutationState.isPending = false;
    mutationState.mutate = vi.fn();
});

describe('computeLineDiff', () => {
    it('flags equal / changed / added / removed line-by-line', () => {
        const rows = computeLineDiff('a\nb\nc', 'a\nB\nc\nd');
        expect(rows.map((r) => r.kind)).toEqual(['equal', 'changed', 'equal', 'added']);
    });

    it('marks trailing removed lines when buffer is shorter', () => {
        const rows = computeLineDiff('a\nb\nc', 'a');
        expect(rows.map((r) => r.kind)).toEqual(['equal', 'removed', 'removed']);
    });
});

describe('SourceTab', () => {
    it('renders the loading state while raw is in-flight', () => {
        rawState.isLoading = true;
        wrap(<SourceTab documentId={7} />);
        const host = screen.getByTestId('kb-source');
        expect(host).toHaveAttribute('data-state', 'loading');
    });

    it('disables Save and Cancel when buffer matches raw (no pending edits)', () => {
        seedRaw();
        wrap(<SourceTab documentId={7} />);
        expect(screen.getByTestId('kb-editor-save')).toBeDisabled();
        expect(screen.getByTestId('kb-editor-cancel')).toBeDisabled();
    });

    it('enables Save and Cancel once the buffer diverges from the saved baseline', async () => {
        // Copilot #6 fix: the prior test never dispatched an actual
        // edit — it just asserted the buttons existed. Now we reach
        // into the mounted `EditorView` (CodeMirror attaches one per
        // `.cm-editor` subtree) and dispatch a real transaction; the
        // component's `updateListener.of` fires and flips `isDirty`.
        seedRaw('# Original\n');
        wrap(<SourceTab documentId={7} />);

        expect(screen.getByTestId('kb-editor-save')).toBeDisabled();
        expect(screen.getByTestId('kb-editor-cancel')).toBeDisabled();

        const host = screen.getByTestId('kb-editor-cm');
        const cmNode = host.querySelector('.cm-editor');
        expect(cmNode).not.toBeNull();
        const view = EditorView.findFromDOM(cmNode as HTMLElement);
        expect(view).not.toBeNull();

        await act(async () => {
            view!.dispatch({
                changes: {
                    from: view!.state.doc.length,
                    insert: '\n\nAppended by Vitest.',
                },
            });
        });

        // Buffer !== saved → both buttons should now be interactive.
        expect(screen.getByTestId('kb-editor-save')).not.toBeDisabled();
        expect(screen.getByTestId('kb-editor-cancel')).not.toBeDisabled();
    });

    it('shows per-field frontmatter error surface when save returns 422', async () => {
        // Copilot #7 fix: the original test never actually clicked
        // Save (the button stayed disabled because `isDirty` was
        // never flipped), so `onError` was never called and the
        // error surface was never exercised. Dispatch a real CM
        // edit → Save → mutate's onError fires → assert the
        // per-key testid renders.
        seedRaw('---\nstatus: accepted\ntype: decision\n---\nbody\n');
        mutationState.mutate = vi.fn((_content: string, opts?: {
            onSuccess?: (r: unknown) => void;
            onError?: (e: unknown) => void;
        }) => {
            opts?.onError?.({
                response: {
                    status: 422,
                    data: {
                        message: 'Invalid canonical frontmatter.',
                        errors: { frontmatter: { status: ['Missing or invalid `status`.'] } },
                    },
                },
            });
        });

        wrap(<SourceTab documentId={7} />);

        const host = screen.getByTestId('kb-editor-cm');
        const cmNode = host.querySelector('.cm-editor');
        const view = EditorView.findFromDOM(cmNode as HTMLElement);
        expect(view).not.toBeNull();

        // Make the buffer dirty so Save becomes enabled.
        await act(async () => {
            view!.dispatch({
                changes: {
                    from: view!.state.doc.length,
                    insert: '\nMutation trigger.',
                },
            });
        });

        // Before the click the error surface should not be present.
        expect(screen.queryByTestId('kb-editor-error-frontmatter')).toBeNull();

        const save = screen.getByTestId('kb-editor-save');
        expect(save).not.toBeDisabled();
        await act(async () => {
            await userEvent.click(save);
        });

        expect(mutationState.mutate).toHaveBeenCalledTimes(1);

        // onError was invoked synchronously inside mutate → the
        // component should now render the per-key error surface for
        // frontmatter.status.
        const bag = screen.getByTestId('kb-editor-error-frontmatter');
        expect(bag).toBeInTheDocument();
        expect(bag.textContent).toMatch(/status/i);
    });

    it('Cancel button is disabled while buffer is in sync with disk', async () => {
        seedRaw();
        wrap(<SourceTab documentId={7} />);
        const cancel = screen.getByTestId('kb-editor-cancel');
        expect(cancel).toBeDisabled();

        // Diff button is always clickable (toggles the diff pane).
        const diff = screen.getByTestId('kb-editor-diff');
        await act(async () => {
            await userEvent.click(diff);
        });
        expect(screen.getByTestId('kb-editor-diff-panel')).toBeInTheDocument();
    });
});
