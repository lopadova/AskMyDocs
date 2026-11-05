import { Button } from "../components/Button";
import { Icon } from "../components/Icon";
import { FormEvent, useEffect, useRef, useState } from "react";
import { ApiError, chat } from "../lib/api";
import { DocumentModal, type DocumentRef } from "../components/DocumentModal";
import { Markdown } from "../components/Markdown";
import { loadThreads, saveThreads } from "../lib/store";
import type { Citation, LocalMessage, Thread } from "../lib/types";

interface Props {
  token: string;
  tenantId: string;
  projectKey: string;
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

export function ChatScreen({ token, tenantId, projectKey }: Props) {
  const [threads, setThreads] = useState<Thread[]>([]);
  const [activeId, setActiveId] = useState<string | null>(null);
  const [draft, setDraft] = useState("");
  const [sending, setSending] = useState(false);
  const [sendingId, setSendingId] = useState<string | null>(null);
  const [error, setError] = useState("");
  const [loaded, setLoaded] = useState(false);
  const [filter, setFilter] = useState("");
  const [showArchived, setShowArchived] = useState(false);
  const [copied, setCopied] = useState<number | null>(null);
  const composerRef = useRef<HTMLTextAreaElement>(null);
  // Mobile only: the thread list is an off-canvas drawer (CSS-gated under the
  // 640px breakpoint). On desktop the toggle is hidden so this stays false.
  const [drawerOpen, setDrawerOpen] = useState(false);
  // The cited document open in the fullpage MD viewer (null = closed).
  const [openDoc, setOpenDoc] = useState<DocumentRef | null>(null);
  const drawerToggleRef = useRef<HTMLButtonElement>(null);
  const sidebarRef = useRef<HTMLElement>(null);
  const endRef = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    if (!drawerOpen) return;
    sidebarRef.current?.querySelector<HTMLButtonElement>("button")?.focus();
    function onKey(event: KeyboardEvent) {
      if (event.key === "Escape") { setDrawerOpen(false); drawerToggleRef.current?.focus(); }
      if (event.key === "Tab" && sidebarRef.current) {
        const controls = [...sidebarRef.current.querySelectorAll<HTMLElement>("button:not(:disabled), input")];
        const first = controls[0], last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
      }
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [drawerOpen]);

  // Conversation history is isolated by the selected workspace. This avoids
  // showing a tenant/project's local messages after the user switches context.
  useEffect(() => {
    let cancelled = false;
    setLoaded(false);
    setThreads([]);
    setActiveId(null);
    loadThreads(tenantId, projectKey).catch(() => {
      if (!cancelled) setError("Could not load local conversations. Reopen the workspace to retry.");
      return null;
    }).then((stored) => {
      if (cancelled || !stored) {
        return;
      }
      setThreads(stored);
      setActiveId(stored.find(t => !t.archived)?.id ?? null);
      setLoaded(true);
    });
    return () => {
      cancelled = true;
    };
  }, [tenantId, projectKey]);

  // Persist on every change once the initial load has happened, so we never
  // clobber stored threads with the empty bootstrap state.
  useEffect(() => {
    if (loaded) {
      void saveThreads(tenantId, projectKey, threads).catch(() => setError("Could not save conversations on this device. Keep the app open and check available storage."));
    }
  }, [threads, loaded, tenantId, projectKey]);

  const active = threads.find((t) => t.id === activeId) ?? null;
  const visibleThreads = threads.filter(t => Boolean(t.archived) === showArchived && t.title.toLowerCase().includes(filter.toLowerCase()))
    .sort((a, b) => Number(Boolean(b.pinned)) - Number(Boolean(a.pinned)) || b.createdAt - a.createdAt);

  function updateThread(id: string, patch: Partial<Thread>) {
    setThreads(previous => previous.map(thread => thread.id === id ? { ...thread, ...patch } : thread));
  }

  async function copyAnswer(content: string, index: number) {
    try {
      await navigator.clipboard.writeText(content);
      setCopied(index);
    } catch { setError("Could not copy the answer. Select the text to copy it manually."); }
  }

  useEffect(() => {
    endRef.current?.scrollIntoView({ behavior: matchMedia("(prefers-reduced-motion: reduce)").matches ? "instant" : "smooth" });
  }, [active?.messages.length, sending]);

  function startThread() {
    if (!loaded || sending) return;
    setShowArchived(false);
    setFilter("");
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
    setDrawerOpen(false);
  }

  function openThread(id: string) {
    setActiveId(id);
    setCopied(null);
    setError("");
    setDrawerOpen(false);
  }

  function deleteThread(id: string) {
    if (!window.confirm("Delete this conversation from this device? This cannot be undone.")) return;
    const next = threads.filter((t) => t.id !== id);
    setThreads(next);
    if (id === activeId) {
      setActiveId(next.length > 0 ? next[0].id : null);
    }
  }

  async function send(event: FormEvent) {
    event.preventDefault();
    const question = draft.trim();
    if (question === "" || sending || !loaded) {
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
    setSendingId(target.id);

    try {
      const res = await chat(token, question, tenantId, projectKey);
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
      setDraft(current => current || question);
      setError(
        err instanceof ApiError
          ? `${err.message} (HTTP ${err.status})`
          : "Network error — is the backend running?",
      );
    } finally {
      setSending(false);
      setSendingId(null);
    }
  }

  return (
    <div className="chat" data-testid="chat-screen">
      {drawerOpen && (
        <button
          type="button"
          className="chat-backdrop"
          aria-label="Close conversations"
          onClick={() => setDrawerOpen(false)}
          data-testid="chat-drawer-backdrop"
        />
      )}
      <aside
        ref={sidebarRef}
        className={drawerOpen ? "chat-sidebar open" : "chat-sidebar"}
        aria-label="Conversations"
        id="conversation-sidebar"
      >
        <Button
          type="button"
          variant="primary" className="block"
          onClick={startThread}
          disabled={!loaded || sending}
          data-testid="chat-new-thread"
        >
          <Icon name="plus" /> New chat
        </Button>
        <p className="workspace-eyebrow rail-eyebrow">On this device</p>
        <label className="thread-search"><span className="visually-hidden">Filter conversations</span><Icon name="search" /><input type="search" aria-label="Filter conversations" placeholder="Find a conversation" value={filter} onChange={e => setFilter(e.target.value)} /></label>
        <div className="ui-button-group thread-filters" aria-label="Conversation filter">
          <Button variant="quiet" size="sm" aria-pressed={!showArchived} onClick={() => setShowArchived(false)}>Recent</Button>
          <Button variant="quiet" size="sm" aria-pressed={showArchived} onClick={() => setShowArchived(true)}>Archived</Button>
        </div>
        <ul className="thread-list" data-testid="chat-thread-list">
          {visibleThreads.length === 0 && (
            <li className="muted small thread-empty" data-testid="chat-thread-empty">
              {loaded ? "No conversations here yet" : "Loading conversations…"}
            </li>
          )}
          {visibleThreads.map((thread) => (
            <li
              key={thread.id}
              className={
                thread.id === activeId ? "thread-item active" : "thread-item"
              }
            >
              <button
                type="button"
                className="thread-open"
                onClick={() => openThread(thread.id)}
                aria-current={thread.id === activeId}
                data-testid={`chat-thread-${thread.id}-open`}
              >
                {thread.pinned && <Icon name="pin" />} {thread.title}
              </button>

            </li>
          ))}
        </ul>
      </aside>

      <section className="chat-main">
        <div className="chat-mobilebar">
          <Button
            type="button"
            ref={drawerToggleRef}
            variant="quiet" className="chat-drawer-toggle"
            onClick={() => setDrawerOpen(true)}
            aria-label="Show conversations"
            aria-expanded={drawerOpen}
            aria-controls="conversation-sidebar"
            data-testid="chat-drawer-toggle"
          >
            <Icon name="menu" /> Chats
          </Button>
          <span className="chat-mobilebar-title muted small">
            {active ? active.title : "New chat"}
          </span>
        </div>

        {active && <header className="session-toolbar">
          <label className="session-title"><span className="visually-hidden">Conversation title</span><input aria-label="Conversation title" value={active.title} maxLength={120} onChange={e => updateThread(active.id, { title: e.target.value })} /></label>
          <div className="session-actions">
            <Button variant="quiet" iconOnly aria-label={active.pinned ? "Unpin conversation" : "Pin conversation"} title={active.pinned ? "Unpin conversation" : "Pin conversation"} aria-pressed={Boolean(active.pinned)} onClick={() => updateThread(active.id, { pinned: !active.pinned })}><Icon name="pin" /></Button>
            <Button variant="quiet" iconOnly disabled={sending} aria-label={active.archived ? "Restore conversation" : "Archive conversation"} title={active.archived ? "Restore conversation" : "Archive conversation"} onClick={() => { updateThread(active.id, { archived: !active.archived }); setActiveId(null); }}><Icon name="archive" /></Button>
            <Button variant="quiet" iconOnly disabled={sending} aria-label="Delete conversation" title="Delete conversation" onClick={() => deleteThread(active.id)}><Icon name="close" /></Button>
          </div>
        </header>}
        <div
          className="message-list"
          data-testid="chat-messages"
          data-state={sending ? "loading" : "ready"}
          aria-live="polite"
        >
          {(!active || active.messages.length === 0) && (
            <div className="placeholder" data-testid="chat-placeholder">
              <span className="empty-emblem"><Icon name="book" width="30" height="30" /></span>
              <p className="workspace-eyebrow">Your project, in focus</p>
              <h1>Good questions.<br /><em>Grounded answers.</em></h1>
              <p className="muted">Explore what your team knows, with sources you can open and verify.</p>
              <p className="muted small">
                Conversations stay on this device. Each question is sent independently.
              </p>
              <div className="suggestions">{["What are the key decisions in this project?", "Summarize the available documentation.", "Which questions still need an answer?"].map(question => <Button key={question} disabled={!loaded || sending} onClick={() => { setDraft(question); composerRef.current?.focus(); }}>{question}<Icon name="arrow" /></Button>)}</div>
            </div>
          )}

          {active?.messages.map((message, index) => (
            <article
              key={index}
              className={`bubble ${message.role}`}
              data-testid={`chat-message-${message.role}`}
            >
              {message.role === "assistant" ? (
                <Markdown
                  content={message.content}
                  testId="chat-message-markdown"
                />
              ) : (
                <div className="bubble-body">{message.content}</div>
              )}

              {message.role === "assistant" && <Button variant="quiet" size="sm" leadingIcon={<Icon name="copy" />} onClick={() => void copyAnswer(message.content, index)}>{copied === index ? "Copied" : "Copy answer"}</Button>}
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
                    {message.citations.map((citation, ci) => {
                      const docId =
                        typeof citation.document_id === "number"
                          ? citation.document_id
                          : null;
                      if (docId === null) {
                        return (
                          <li key={ci} className="citation">
                            {citationLabel(citation)}
                          </li>
                        );
                      }
                      return (
                        <li key={ci} className="citation">
                          <button
                            type="button"
                            className="citation-open"
                            onClick={() =>
                              setOpenDoc({
                                documentId: docId,
                                title: citation.title,
                                sourcePath: citation.source_path,
                              })
                            }
                            data-testid={`chat-citation-${docId}-open`}
                            title="Open source document"
                          >
                            {citationLabel(citation)}
                          </button>
                        </li>
                      );
                    })}
                  </ul>
                )}
            </article>
          ))}

          {sending && activeId === sendingId && (
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
            ref={composerRef}
            className="composer-input"
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter" && !e.shiftKey && !e.nativeEvent.isComposing) {
                e.preventDefault();
                void send(e as unknown as FormEvent);
              }
            }}
            placeholder="Ask a question…  (Enter to send, Shift+Enter for newline)"
            rows={2}
            aria-label="Message"
            data-testid="chat-input"
          />
          <Button
            type="submit"
            variant="primary"
            disabled={!loaded || sending || draft.trim() === ""}
            data-testid="chat-send"
          >
            <Icon name="arrow" /> Send
          </Button>
        </form>
      </section>

      {openDoc && (
        <DocumentModal
          token={token}
          tenantId={tenantId}
          projectKey={projectKey}
          target={openDoc}
          onClose={() => setOpenDoc(null)}
        />
      )}
    </div>
  );
}
