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

// POST /api/auth/register-token — invite-only Bearer sign-up. `device_name` is
// added by the client (api.ts), so the screen only collects these fields.
export interface RegisterInput {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
  invite_code: string;
}

// One project the user belongs to (within a team/tenant). `role` is the
// per-project membership role (member | admin | owner).
export interface TeamProject {
  project_key: string;
  role: string;
  scope?: unknown;
}

// A team == a tenant the user has access to. Carries the tenant id used as the
// X-Tenant-Id header and the projects the user holds inside it.
export interface Team {
  tenant_id: string;
  hash: string;
  name: string;
  projects: TeamProject[];
}

// GET /api/auth/me — identity + access. `roles` are global (Spatie) system
// roles; `teams` group the user's project memberships per tenant.
export interface MePayload {
  user: AuthUser;
  roles: string[];
  permissions: string[];
  projects: TeamProject[];
  teams: Team[];
}

export interface Citation {
  document_id?: number | null;
  source_path?: string | null;
  title?: string | null;
  [key: string]: unknown;
}

// GET /api/kb/documents/{documentId}/preview — full source text of a document,
// reconstructed from its chunks in order, plus the canonical metadata the modal
// header shows. `content` is "" when the document has no chunks (a real 200,
// rendered as an explicit empty state — not a fake 404).
export interface DocumentPreview {
  document_id: number;
  title: string;
  source_path: string | null;
  slug: string | null;
  project_key: string;
  source_type: string;
  canonical_type: string | null;
  canonical_status: string | null;
  is_canonical: boolean;
  content: string;
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
