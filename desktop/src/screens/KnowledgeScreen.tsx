import { useEffect, useState } from "react";
import { ApiError, fetchKnowledgeTree } from "../lib/api";
import type { KbTreeNode, KbTreeResponse } from "../lib/types";
import { Button } from "../components/Button";
import { Icon } from "../components/Icon";
import { DocumentModal, type DocumentRef } from "../components/DocumentModal";

export function KnowledgeScreen({ token, tenantId, projectKey }: { token: string; tenantId: string; projectKey: string }) {
  const [data, setData] = useState<KbTreeResponse | null>(null);
  const [error, setError] = useState("");
  const [attempt, setAttempt] = useState(0);
  const [openDoc, setOpenDoc] = useState<DocumentRef | null>(null);
  useEffect(() => {
    let cancelled = false;
    setData(null); setError(""); setOpenDoc(null);
    fetchKnowledgeTree(token, tenantId, projectKey).then(result => {
      if (!cancelled) setData(result);
    }).catch(err => {
      if (!cancelled) setError(err instanceof ApiError && err.status === 404
        ? "Knowledge browsing is not available on this server yet. You can still use Search."
        : err instanceof Error ? err.message : "Could not load knowledge base.");
    });
    return () => { cancelled = true; };
  }, [token, tenantId, projectKey, attempt]);

  function nodes(items: KbTreeNode[]) {
    return <ul className="knowledge-tree">{items.map(node => <li key={`${node.type}:${node.path}`}>
      {node.type === "folder" ? <details open><summary><Icon name="folder" />{node.name}</summary>{nodes(node.children)}</details> :
        <button type="button" className="knowledge-document" onClick={() => setOpenDoc({ documentId: node.meta.id, title: node.name, sourcePath: node.path })}>
          <Icon name="file" /><span>{node.name}</span>{node.meta.is_canonical && <span className="badge">Canonical</span>}
        </button>}
    </li>)}</ul>;
  }
  return <section className="knowledge" data-testid="knowledge-screen">
    <header className="page-heading"><p className="workspace-eyebrow">{projectKey}</p><h1>Your knowledge library</h1><p className="muted">Browse the sources behind your answers.</p></header>
    {error ? <div role="alert" className="empty-panel"><p>{error}</p><Button onClick={() => setAttempt(x => x + 1)}>Try again</Button></div> : !data ? <p role="status">Loading knowledge base…</p> : <>
      <p className="muted small" role="status">{data.counts.docs} documents · {data.counts.canonical} canonical</p>
      {data.tree.length ? nodes(data.tree) : <div className="empty-panel"><Icon name="book" /><h2>No documents yet</h2><p className="muted">Sources added to this project will appear here.</p></div>}
    </>}
    {openDoc && <DocumentModal token={token} tenantId={tenantId} projectKey={projectKey} target={openDoc} onClose={() => setOpenDoc(null)} />}
  </section>;
}
