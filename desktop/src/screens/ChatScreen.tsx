import { FormEvent, useEffect, useRef, useState } from "react";
import { ApiError, chat } from "../lib/api";
import { loadThreads, saveThreads } from "../lib/store";
import type { Citation, LocalMessage, Thread } from "../lib/types";

interface Props {
  token: string;
  tenantId?: string;
}

function newId(): string {
  if (typeof crypto !== "undefined" && "randomUUID" in crypto) {
    return crypto.randomUUID();
  }
  return `${Date.now()}-${Math.round(Math.random() * 1e9)}`;
}

function titleFrom(question: string): string {
  const trimmed = question.trim().replace(/\s+/g, " ");
  if (trimmed === "") {
    return "New chat";
  }
  return trimmed.length > 48 ? `${trimmed.slice(0, 48)}…` : trimmed;
}

function citationLabel(citation: Citation): string {
  return (
    (typeof citation.title === "string" && citation.title) ||
    (typeof citation.source_path === "string" && citation.source_path) ||
    "source"
  );
}

export function ChatScreen({ token, tenantId }: Props) {
  const [threads, setThreads] = useState<Thread[]>([]);
  const [activeId, setActiveId] = useState<string | null>(null);
  const [draft, setDraft] = useState("");
  const [sending, setSending] = useState(false);
  const [error, setError] = useState("");
  const [loaded, setLoaded] = useState(false);
  const endRef = useRef<HTMLDivElement | null>(null);

  // Hydrate threads from disk once.
  useEffect(() => {
    let cancelled = false;
    loadThreads().then((stored) => {
      if (cancelled) {
        return;
      }
      setThreads(stored);
      setActiveId(stored.length > 0 ? stored[0].id : null);
      setLoaded(true);
    });
    return () => {
      cancelled = true;
    };
  }, []);

  // Persist on every change once the initial load has happened, so we never
  // clobber stored threads with the empty bootstrap state.
  useEffect(() => {
    if (loaded) {
      void saveThreads(threads);
    }
  }, [threads, loaded]);

  const active = threads.find((t) => t.id === activeId) ?? null;

  useEffect(() => {
    endRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [active?.messages.length, sending]);

  function startThread() {
    setError("");
    const thread: Thread = {
      id: newId(),
      title: "New chat",
      createdAt: Date.now(),
      messages: [],
    };
    setThreads((prev) => [thread, ...prev]);
    setActiveId(thread.id);
    setDraft("");
  }

  function deleteThread(id: string) {
    const next = threads.filter((t) => t.id !== id);
    setThreads(next);
    if (id === activeId) {
      setActiveId(next.length > 0 ? next[0].id : null);
    }
  }

  async function send(event: FormEvent) {
    event.preventDefault();
    const question = draft.trim();
    if (question === "" || sending) {
      return;
    }
    setError("");

    const existing = activeId ? threads.find((t) => t.id === activeId) : undefined;
    const target: Thread = existing ?? {
      id: newId(),
      title: titleFrom(question),
      createdAt: Date.now(),
      messages: [],
    };
    const isFirstTurn = target.messages.length === 0;
    const userMessage: LocalMessage = { role: "user", content: question };
    const withUser: Thread = {
      ...target,
      title: isFirstTurn ? titleFrom(question) : target.title,
      messages: [...target.messages, userMessage],
    };

    setThreads((prev) =>
      existing
        ? prev.map((t) => (t.id === target.id ? withUser : t))
        : [withUser, ...prev],
    );
    setActiveId(target.id);
    setDraft("");
    setSending(true);

    try {
      const res = await chat(token, question, tenantId);
      const assistant: LocalMessage = {
        role: "assistant",
        content: res.answer,
        citations: res.citations,
        confidence: res.confidence ?? null,
        refusalReason: res.refusal_reason ?? null,
        meta: res.meta,
      };
      setThreads((prev) =>
        prev.map((t) =>
          t.id === target.id
            ? { ...t, messages: [...t.messages, assistant] }
            : t,
        ),
      );
    } catch (err) {
      setError(
        err instanceof ApiError
          ? `${err.message} (HTTP ${err.status})`
          : "Network error — is the backend running?",
      );
    } finally {
      setSending(false);
    }
  }

  return (
    <div className="chat" data-testid="chat-screen">
      <aside className="chat-sidebar" aria-label="Conversations">
        <button
          type="button"
          className="btn primary block"
          onClick={startThread}
          data-testid="chat-new-thread"
        >
          + New chat
        </button>
        <ul className="thread-list" data-testid="chat-thread-list">
          {threads.length === 0 && (
            <li className="muted small thread-empty" data-testid="chat-thread-empty">
              No conversations yet
            </li>
          )}
          {threads.map((thread) => (
            <li
              key={thread.id}
              className={
                thread.id === activeId ? "thread-item active" : "thread-item"
              }
            >
              <button
                type="button"
                className="thread-open"
                onClick={() => {
                  setActiveId(thread.id);
                  setError("");
                }}
                aria-current={thread.id === activeId}
                data-testid={`chat-thread-${thread.id}-open`}
              >
                {thread.title}
              </button>
              <button
                type="button"
                className="thread-delete"
                onClick={() => deleteThread(thread.id)}
                aria-label={`Delete conversation ${thread.title}`}
                data-testid={`chat-thread-${thread.id}-delete`}
              >
                ×
              </button>
            </li>
          ))}
        </ul>
      </aside>

      <section className="chat-main">
        <div
          className="message-list"
          data-testid="chat-messages"
          data-state={sending ? "loading" : "ready"}
          aria-live="polite"
        >
          {!active && (
            <div className="placeholder" data-testid="chat-placeholder">
              <p>Ask a question grounded in your knowledge base.</p>
              <p className="muted small">
                Each answer cites its sources. Conversations are stored locally.
              </p>
            </div>
          )}

          {active?.messages.map((message, index) => (
            <article
              key={index}
              className={`bubble ${message.role}`}
              data-testid={`chat-message-${message.role}`}
            >
              <div className="bubble-body">{message.content}</div>

              {message.role === "assistant" &&
                typeof message.confidence === "number" && (
                  <div className="badge-row">
                    <span className="badge" title="Answer confidence">
                      confidence {message.confidence}
                    </span>
                    {message.refusalReason && (
                      <span className="badge warn" title="Refusal reason">
                        {message.refusalReason}
                      </span>
                    )}
                    {message.meta?.model && (
                      <span className="badge ghost">{message.meta.model}</span>
                    )}
                  </div>
                )}

              {message.role === "assistant" &&
                message.citations &&
                message.citations.length > 0 && (
                  <ul className="citations" data-testid="chat-citations">
                    {message.citations.map((citation, ci) => (
                      <li key={ci} className="citation">
                        {citationLabel(citation)}
                      </li>
                    ))}
                  </ul>
                )}
            </article>
          ))}

          {sending && (
            <article className="bubble assistant pending" data-testid="chat-pending">
              <div className="bubble-body muted">Thinking…</div>
            </article>
          )}
          <div ref={endRef} />
        </div>

        {error && (
          <p className="error banner" role="alert" data-testid="chat-error">
            {error}
          </p>
        )}

        <form className="composer" onSubmit={send}>
          <textarea
            className="composer-input"
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter" && !e.shiftKey) {
                e.preventDefault();
                void send(e as unknown as FormEvent);
              }
            }}
            placeholder="Ask a question…  (Enter to send, Shift+Enter for newline)"
            rows={2}
            aria-label="Message"
            data-testid="chat-input"
          />
          <button
            type="submit"
            className="btn primary"
            disabled={sending || draft.trim() === ""}
            data-testid="chat-send"
          >
            Send
          </button>
        </form>
      </section>
    </div>
  );
}
