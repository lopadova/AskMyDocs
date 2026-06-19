// Shared types for the AskMyDocs desktop demo. Kept loose where the backend
// payload is rich (meta, citations) — the demo only renders a subset.

export interface AuthUser {
  id: number;
  name: string;
  email: string;
}

export interface TokenResponse {
  token: string;
  token_type: string;
  user: AuthUser;
}

export interface Citation {
  source_path?: string | null;
  title?: string | null;
  [key: string]: unknown;
}

export interface ChatMeta {
  provider?: string;
  model?: string;
  chunks_used?: number;
  latency_ms?: number;
  [key: string]: unknown;
}

export interface ChatResponse {
  answer: string;
  citations: Citation[];
  confidence?: number | null;
  refusal_reason?: string | null;
  meta?: ChatMeta;
}

// Exact shape returned by GET /api/kb/documents/search (under `data`).
export interface DocSearchResult {
  id: number;
  project_key: string;
  title: string;
  source_path: string | null;
  source_type: string;
  canonical_type: string | null;
}

export type ChatRole = "user" | "assistant";

export interface LocalMessage {
  role: ChatRole;
  content: string;
  citations?: Citation[];
  confidence?: number | null;
  refusalReason?: string | null;
  meta?: ChatMeta;
}

// A conversation thread lives entirely on the client (persisted in the Tauri
// store). Each turn calls the stateless POST /api/kb/chat — the backend never
// stores per-conversation history for Bearer clients.
export interface Thread {
  id: string;
  title: string;
  createdAt: number;
  messages: LocalMessage[];
}
