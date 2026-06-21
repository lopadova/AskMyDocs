import { useEffect, useRef, useState } from "react";
import { ApiError, fetchDocumentPreview } from "../lib/api";
import type { DocumentPreview } from "../lib/types";
import { Markdown } from "./Markdown";

// Minimal hint a caller already knows (from a search row or a citation), shown
// in the header while the full body loads so the modal never opens blank.
export interface DocumentRef {
  documentId: number;
  title?: string | null;
  projectKey?: string | null;
  sourcePath?: string | null;
}

interface Props {
  token: string;
  tenantId?: string;
  target: DocumentRef;
  onClose: () => void;
}

type State = "loading" | "ready" | "empty" | "error";

export function DocumentModal({ token, tenantId, target, onClose }: Props) {
  const [doc, setDoc] = useState<DocumentPreview | null>(null);
  const [state, setState] = useState<State>("loading");
  const [error, setError] = useState("");
  const closeRef = useRef<HTMLButtonElement | null>(null);

  // Fetch the full content for this document id. Re-runs if the target changes
  // (e.g. the user opens a different citation without closing first).
  useEffect(() => {
    let cancelled = false;
    setState("loading");
    setError("");
    setDoc(null);
    fetchDocumentPreview(token, target.documentId, tenantId)
      .then((preview) => {
        if (cancelled) {
          return;
        }
        setDoc(preview);
        setState(preview.content.trim() === "" ? "empty" : "ready");
      })
      .catch((err) => {
        if (cancelled) {
          return;
        }
        setError(
          err instanceof ApiError
            ? `${err.message} (HTTP ${err.status})`
            : "Network error — is the backend running?",
        );
        setState("error");
      });
    return () => {
      cancelled = true;
    };
  }, [token, tenantId, target.documentId]);

  // Close on Escape; move focus into the dialog on open and restore it on close.
  useEffect(() => {
    const previouslyFocused = document.activeElement as HTMLElement | null;
    closeRef.current?.focus();
    function onKey(event: KeyboardEvent) {
      if (event.key === "Escape") {
        onClose();
      }
    }
    window.addEventListener("keydown", onKey);
    return () => {
      window.removeEventListener("keydown", onKey);
      previouslyFocused?.focus?.();
    };
  }, [onClose]);

  const title = doc?.title ?? target.title ?? "Document";
  const sourcePath = doc?.source_path ?? target.sourcePath ?? null;
  const projectKey = doc?.project_key ?? target.projectKey ?? null;

  return (
    <div
      className="doc-modal-backdrop"
      data-testid="doc-modal-backdrop"
      onClick={(event) => {
        // Backdrop-only click closes; clicks inside the dialog don't bubble out.
        if (event.target === event.currentTarget) {
          onClose();
        }
      }}
    >
      <div
        className="doc-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="doc-modal-title"
        data-testid="doc-modal"
        data-state={state}
      >
        <header className="doc-modal-head">
          <div className="doc-modal-titles">
            <h2 className="doc-modal-title" id="doc-modal-title">
              {title}
            </h2>
            <div className="doc-modal-meta">
              {projectKey && <span className="badge ghost">{projectKey}</span>}
              {doc?.source_type && (
                <span className="badge ghost">{doc.source_type}</span>
              )}
              {doc?.canonical_type && (
                <span className="badge">{doc.canonical_type}</span>
              )}
              {doc?.canonical_status && (
                <span className="badge ghost">{doc.canonical_status}</span>
              )}
            </div>
            {sourcePath && (
              <div className="doc-modal-path muted small">{sourcePath}</div>
            )}
          </div>
          <button
            type="button"
            ref={closeRef}
            className="btn ghost doc-modal-close"
            onClick={onClose}
            aria-label="Close document"
            data-testid="doc-modal-close"
          >
            ✕
          </button>
        </header>

        <div className="doc-modal-body" data-testid="doc-modal-body">
          {state === "loading" && (
            <div className="doc-modal-status" data-testid="doc-modal-loading">
              <span className="spinner" aria-hidden="true" />
              <span className="muted">Loading document…</span>
            </div>
          )}

          {state === "error" && (
            <p className="error banner" role="alert" data-testid="doc-modal-error">
              {error}
            </p>
          )}

          {state === "empty" && (
            <p className="muted doc-modal-status" data-testid="doc-modal-empty">
              This document has no indexed content yet.
            </p>
          )}

          {state === "ready" && doc && (
            <Markdown content={doc.content} testId="doc-modal-markdown" />
          )}
        </div>
      </div>
    </div>
  );
}
