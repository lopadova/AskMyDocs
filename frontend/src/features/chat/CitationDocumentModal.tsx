import { useQuery } from '@tanstack/react-query';
import { type ReactNode } from 'react';
import { ChevronDown, FileText, Quote, ExternalLink, XIcon } from 'lucide-react';

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
import { Button } from '@/components/Button';
import './citation-document-modal.css';

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
    const claims = Array.isArray(citation.claims) ? citation.claims : [];

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
                className="citation-document-dialog"
            >
                <DialogHeader className="citation-document-header">
                    <span className="citation-document-icon"><FileText size={30} aria-hidden /></span>
                    <div className="citation-document-heading">
                        <div className="citation-document-eyebrow">
                            <span>Source document</span>
                            <span data-testid="chat-citation-modal-origin" className="citation-document-origin">
                                {ORIGIN_LABEL[origin] ?? origin}
                            </span>
                        </div>
                        <DialogTitle data-testid="chat-citation-modal-title" className="citation-document-title">{title}</DialogTitle>
                        <DialogDescription>
                            Read the original source and the passages behind this answer.
                        </DialogDescription>
                    </div>
                    <DialogClose asChild>
                        <Button variant="quiet" size="sm" iconOnly
                            data-testid="chat-citation-modal-close" aria-label="Close source document"
                            className="citation-document-close">
                            <XIcon aria-hidden size={16} />
                        </Button>
                    </DialogClose>
                </DialogHeader>

                <div
                    data-testid="chat-citation-modal-body"
                    data-state={state}
                    className={`citation-document-body${ready && !empty && claims.length > 0 ? ' has-evidence' : ''}`}
                >
                    {isLoading && (
                        <div data-testid="chat-citation-modal-loading" style={{ color: 'var(--fg-3)' }}>
                            Loading source…
                        </div>
                    )}
                    {isError && (
                        <div data-testid="chat-citation-modal-error" role="alert" style={{ color: 'var(--fg-2)' }}>
                            <p style={{ marginBottom: 8 }}>Could not load this document.</p>
                            <Button size="sm" data-testid="chat-citation-modal-retry"
                                onClick={() => void refetch()} busy={isFetching}>
                                Retry
                            </Button>
                        </div>
                    )}
                    {empty && (
                        <div data-testid="chat-citation-modal-empty" style={{ color: 'var(--fg-3)' }}>
                            This document has no indexed content yet.
                        </div>
                    )}
                    {ready && !empty && (
                        <>
                            {claims.length > 0 && (
                                <section data-testid="chat-citation-modal-evidence" className="citation-document-evidence">
                                    <h3 className="citation-document-section-label"><Quote size={26} aria-hidden />
                                        {claims.length === 1 ? 'Passaggio usato nella risposta' : 'Passaggi usati nella risposta'}
                                    </h3>
                                    {claims.map((claim) => (
                                        <div key={`${claim.evidence_hash}:${claim.text}`} className="citation-document-claim">
                                            <blockquote className="citation-document-claim-text"><Markdown source={claim.text} project={project} /></blockquote>
                                            {claim.quote && (
                                                <details className="citation-document-excerpt">
                                                    <summary>Estratto originale <ChevronDown size={14} aria-hidden /></summary>
                                                    <blockquote><Markdown source={claim.quote} project={project} /></blockquote>
                                                </details>
                                            )}
                                        </div>
                                    ))}
                                </section>
                            )}
                            <section className="citation-document-reader" aria-label="Document content" tabIndex={0}>
                                <h3 className="citation-document-section-label"><FileText size={20} aria-hidden />Document content</h3>
                                <div data-testid="chat-citation-modal-content" className="citation-document-content">
                                    <Markdown source={content} project={project ?? undefined} />
                                </div>
                            </section>
                        </>
                    )}
                </div>

                <footer className="citation-document-footer">
                    <details className="citation-document-details">
                        <summary>Source details <ChevronDown size={14} aria-hidden /></summary>
                        <div className="citation-document-metadata">
                            <span>Document #{documentId}{project ? ` · ${project}` : ''}</span>
                            {citation.source_path && (
                                <p data-testid="chat-citation-modal-path">{citation.source_path}</p>
                            )}
                        </div>
                    </details>
                    {onOpenInKb && (
                        <Button variant="primary" size="md" data-testid="chat-citation-modal-open-kb"
                            onClick={() => onOpenInKb(citation)} trailingIcon={<ExternalLink aria-hidden size={14} />}>
                            Open in Knowledge Base
                        </Button>
                    )}
                </footer>
            </DialogContent>
        </Dialog>
    );
}
