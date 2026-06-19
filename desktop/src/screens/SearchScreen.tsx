import { useEffect, useState } from "react";
import { ApiError, searchDocs } from "../lib/api";
import type { DocSearchResult } from "../lib/types";

interface Props {
  token: string;
  tenantId?: string;
}

type State = "idle" | "loading" | "ready" | "empty" | "error";

export function SearchScreen({ token, tenantId }: Props) {
  const [query, setQuery] = useState("");
  const [results, setResults] = useState<DocSearchResult[]>([]);
  const [state, setState] = useState<State>("idle");
  const [error, setError] = useState("");

  // Debounced search. The backend requires q >= 2 chars.
  useEffect(() => {
    const needle = query.trim();
    if (needle.length < 2) {
      setResults([]);
      setState("idle");
      setError("");
      return;
    }

    setState("loading");
    let cancelled = false;
    const handle = setTimeout(async () => {
      try {
        const found = await searchDocs(token, needle, tenantId);
        if (cancelled) {
          return;
        }
        setResults(found);
        setState(found.length === 0 ? "empty" : "ready");
      } catch (err) {
        if (cancelled) {
          return;
        }
        setError(
          err instanceof ApiError
            ? `${err.message} (HTTP ${err.status})`
            : "Network error — is the backend running?",
        );
        setState("error");
      }
    }, 350);

    return () => {
      cancelled = true;
      clearTimeout(handle);
    };
  }, [query, token, tenantId]);

  return (
    <div className="search" data-testid="search-screen" data-state={state}>
      <div className="search-bar">
        <input
          type="search"
          className="search-input"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Search knowledge base documents… (min 2 characters)"
          aria-label="Search documents"
          data-testid="search-input"
        />
      </div>

      {state === "error" && (
        <p className="error banner" role="alert" data-testid="search-error">
          {error}
        </p>
      )}

      {state === "loading" && (
        <p className="muted" data-testid="search-loading">
          Searching…
        </p>
      )}

      {state === "empty" && (
        <p className="muted" data-testid="search-empty">
          No documents match “{query.trim()}”.
        </p>
      )}

      {state === "idle" && (
        <p className="muted" data-testid="search-hint">
          Type at least 2 characters to search.
        </p>
      )}

      {state === "ready" && (
        <ul className="results" data-testid="search-results">
          {results.map((doc) => (
            <li key={doc.id} className="result" data-testid={`search-result-${doc.id}`}>
              <div className="result-title">{doc.title}</div>
              <div className="result-meta">
                <span className="badge ghost">{doc.project_key}</span>
                <span className="badge ghost">{doc.source_type}</span>
                {doc.canonical_type && (
                  <span className="badge">{doc.canonical_type}</span>
                )}
              </div>
              {doc.source_path && (
                <div className="result-path muted small">{doc.source_path}</div>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
