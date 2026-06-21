import { useQuery } from '@tanstack/react-query';
import { type ReactNode } from 'react';

import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Markdown } from '../../lib/markdown';
import { chatApi, type MessageCitation } from './chat.api';

export interface CitationDocumentModalProps {
    /**
     * The citation to open. Its `document_id` MUST be non-null — ChatView only
     * opens the modal for citations that resolve to a concrete document.
     */
    citation: MessageCitation;
    onClose: () => void;
    /**
     * Optional "open the full page in the KB admin surface" action, wired by
     * ChatView ONLY for users who can reach it (admin / super-admin). Omitted
     * for everyone else so the modal never offers a link that dead-ends on 403.
     */
    onOpenInKb?: (citation: MessageCitation) => void;
}

const ORIGIN_LABEL: Record<string, string> = {
    primary: 'primary',
    related: 'related',
    rejected: 'rejected',
};

function fileName(path: string | null | undefined): string | null {
    if (!path) {
        return null;
    }
    const segments = path.replace(/[\\/]+$/, '').split(/[\\/]/);
    const last = segments[segments.length - 1];
    return last.length > 0 ? last : null;
}

/**
 * Modal that opens a CITED document inline in the chat — "the documents used to
 * ground the answer". Fetches the full source text on demand
 * (`GET /api/kb/documents/{id}/preview`, scoped server-side to the reader's
 * tenant + AccessScope), and renders it through the same Markdown pipeline as
 * the chat answers (so wikilinks/callouts resolve).
 *
 * Works for EVERY reader, not only admins (who previously got navigated away to
 * the admin KB page). a11y comes from the Radix Dialog: focus trap, Escape,
 * `aria-modal`, restore-focus. R11/R29: stable testids; R14: the error path
 * surfaces a visible, retryable alert rather than a silent blank.
 */
export function CitationDocumentModal({ citation, onClose, onOpenInKb }: CitationDocumentModalProps): ReactNode {
    const documentId = citation.document_id;

    const { data, isLoading, isError, isFetching, refetch } = useQuery({
        queryKey: ['citation-document', documentId],
        queryFn: () => chatApi.fetchCitationDocument(documentId as number),
        enabled: documentId != null,
        staleTime: 5 * 60_000,
    });

    const title = citation.title ?? fileName(citation.source_path) ?? `Document #${documentId}`;
    const content = data?.content ?? '';
    const ready = !isLoading && !isError && data !== undefined;
    const empty = ready && content.trim() === '';
    const state = isLoading ? 'loading' : isError ? 'error' : empty ? 'empty' : ready ? 'ready' : 'idle';
    const project = citation.project_key ?? data?.project_key ?? undefined;
    const origin = citation.origin ?? 'primary';

    // Defensive: ChatView + CitationsPopover only open a citation with a
    // concrete document_id; render nothing rather than a blank modal if a
    // future call site passes a null one. After the hooks, so hook order is
    // stable.
    if (documentId == null) {
        return null;
    }

    return (
        <Dialog
            open
            onOpenChange={(next) => {
                if (!next) {
                    onClose();
                }
            }}
        >
            <DialogContent
                data-testid="chat-citation-modal"
                aria-busy={isFetching}
                showCloseButton={false}
                className="max-w-2xl"
                style={{ maxHeight: '85vh', gridTemplateRows: 'auto minmax(0, 1fr) auto', gap: 12 }}
            >
                <DialogHeader>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        <span
                            data-testid="chat-citation-modal-origin"
                            className="pill"
                            style={{
                                padding: '2px 8px',
                                fontSize: 10.5,
                                borderRadius: 99,
                                border: '1px solid var(--panel-border)',
                                color: 'var(--fg-2)',
                                textTransform: 'uppercase',
                                letterSpacing: '.06em',
                                fontFamily: 'var(--font-mono)',
                            }}
                        >
                            {ORIGIN_LABEL[origin] ?? origin}
                        </span>
                        <DialogClose asChild>
                            <button
                                type="button"
                                data-testid="chat-citation-modal-close"
                                aria-label="Close source document"
                                style={{
                                    marginLeft: 'auto',
                                    cursor: 'pointer',
                                    background: 'transparent',
                                    border: 'none',
                                    color: 'var(--fg-3)',
                                    fontSize: 16,
                                    lineHeight: 1,
                                    padding: 4,
                                }}
                            >
                                ✕
                            </button>
                        </DialogClose>
                    </div>
                    <DialogTitle data-testid="chat-citation-modal-title">{title}</DialogTitle>
                    {citation.source_path && (
                        <DialogDescription
                            data-testid="chat-citation-modal-path"
                            className="mono"
                            style={{ wordBreak: 'break-all' }}
                        >
                            {citation.source_path}
                        </DialogDescription>
                    )}
                </DialogHeader>

                <div
                    data-testid="chat-citation-modal-body"
                    data-state={state}
                    style={{ overflowY: 'auto', minHeight: 0, lineHeight: 1.55 }}
                >
                    {isLoading && (
                        <div data-testid="chat-citation-modal-loading" style={{ color: 'var(--fg-3)' }}>
                            Loading source…
                        </div>
                    )}
                    {isError && (
                        <div data-testid="chat-citation-modal-error" role="alert" style={{ color: 'var(--fg-2)' }}>
                            <p style={{ marginBottom: 8 }}>Could not load this document.</p>
                            <button
                                type="button"
                                data-testid="chat-citation-modal-retry"
                                onClick={() => void refetch()}
                                style={{
                                    cursor: 'pointer',
                                    padding: '4px 12px',
                                    borderRadius: 6,
                                    border: '1px solid var(--panel-border)',
                                    background: 'var(--bg-2)',
                                    color: 'var(--fg-1)',
                                }}
                            >
                                Retry
                            </button>
                        </div>
                    )}
                    {empty && (
                        <div data-testid="chat-citation-modal-empty" style={{ color: 'var(--fg-3)' }}>
                            This document has no indexed content yet.
                        </div>
                    )}
                    {ready && !empty && (
                        <div data-testid="chat-citation-modal-content">
                            <Markdown source={content} project={project ?? undefined} />
                        </div>
                    )}
                </div>

                {onOpenInKb && documentId != null && (
                    <div
                        style={{
                            display: 'flex',
                            justifyContent: 'flex-end',
                            borderTop: '1px solid var(--panel-border)',
                            paddingTop: 10,
                        }}
                    >
                        <button
                            type="button"
                            data-testid="chat-citation-modal-open-kb"
                            onClick={() => onOpenInKb(citation)}
                            style={{
                                cursor: 'pointer',
                                padding: '6px 14px',
                                borderRadius: 8,
                                border: '1px solid var(--panel-border)',
                                background: 'var(--bg-2)',
                                color: 'var(--fg-1)',
                                fontSize: 12.5,
                            }}
                        >
                            Open in Knowledge Base ↗
                        </button>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
