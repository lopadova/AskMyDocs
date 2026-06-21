// Thin API client over the Tauri HTTP plugin. Requests are issued from the
// Rust side, so the webview never does a cross-origin fetch and the Laravel
// backend needs no `tauri://` entry in its CORS allow-list.
//
// Change API_BASE if your backend runs elsewhere — and mirror it in
// src-tauri/capabilities/default.json (the HTTP scope) AND the README.
import { fetch } from "@tauri-apps/plugin-http";
import type {
  ChatResponse,
  DocSearchResult,
  DocumentPreview,
  MePayload,
  TokenResponse,
} from "./types";

// Production backend (the live AskMyDocs deployment). Override at build time
// with VITE_API_BASE to point at a local backend (e.g. Valet/Herd
// `https://askmydocs.test`) or — REQUIRED on a physical iPhone, where `.test`
// and localhost resolve to the device itself — at the host machine's LAN
// address, e.g. `VITE_API_BASE=http://192.168.1.50:8000 npm run ios:dev`.
// Keep the HTTP scope in src-tauri/capabilities/default.json in lockstep.
export const API_BASE = (
  (import.meta.env.VITE_API_BASE ?? "https://askmydocs.surfacesrl.com")
    .trim()
    .replace(/\/+$/, "")
);

// rustls (the Tauri HTTP plugin's default TLS backend) only trusts the public
// webpki root store, so it REJECTS the local-CA certificate that Valet/Herd
// serve `.test` hosts with — the symptom is a "Network error" before any HTTP
// status. Relax cert verification for LOCAL DEV hosts only; any real (non-local)
// API_BASE keeps full TLS verification. Requires the `dangerous-settings`
// feature on tauri-plugin-http (see src-tauri/Cargo.toml). RFC1918 LAN ranges
// are included so a self-signed https backend reached from a device also works.
const LOCAL_DEV =
  /(\/\/)(localhost|127\.0\.0\.1)(:|\/|$)/.test(API_BASE) ||
  /\.test(:|\/|$)/.test(API_BASE) ||
  /\/\/(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/.test(API_BASE);

function http(url: string, init: RequestInit = {}): Promise<Response> {
  if (!LOCAL_DEV) {
    return fetch(url, init);
  }
  return fetch(url, {
    ...init,
    danger: { acceptInvalidCerts: true, acceptInvalidHostnames: true },
  });
}

/** Surfaces a backend failure with its status + parsed body (R14 mindset:
 *  the caller must be able to tell a refusal/validation error from success). */
export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly body: unknown,
  ) {
    super(message);
    this.name = "ApiError";
  }
}

function authHeaders(token?: string, tenantId?: string): Record<string, string> {
  const headers: Record<string, string> = {
    Accept: "application/json",
    "Content-Type": "application/json",
  };
  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }
  // Tenant scoping: the backend authorizes the header against the user's
  // memberships, then scopes retrieval to this tenant (R30).
  if (tenantId) {
    headers["X-Tenant-Id"] = tenantId;
  }
  return headers;
}

async function parseBody(res: Response): Promise<unknown> {
  const text = await res.text();
  if (!text) {
    return null;
  }
  try {
    return JSON.parse(text);
  } catch {
    return text;
  }
}

/** Pull a human message out of a Laravel error envelope ({message, errors}). */
function errorMessage(body: unknown, fallback: string): string {
  if (body && typeof body === "object") {
    const envelope = body as { message?: unknown; errors?: Record<string, string[]> };
    if (envelope.errors) {
      const first = Object.values(envelope.errors)[0];
      if (Array.isArray(first) && typeof first[0] === "string") {
        return first[0];
      }
    }
    if (typeof envelope.message === "string" && envelope.message !== "") {
      return envelope.message;
    }
  }
  return fallback;
}

export async function requestToken(
  email: string,
  password: string,
): Promise<TokenResponse> {
  const res = await http(`${API_BASE}/api/auth/token`, {
    method: "POST",
    headers: authHeaders(),
    body: JSON.stringify({ email, password, device_name: "AskMyDocs Desktop" }),
  });
  const body = await parseBody(res);
  if (!res.ok) {
    throw new ApiError(res.status, errorMessage(body, "Login failed"), body);
  }
  return body as TokenResponse;
}

/** Identity + access: roles, projects, and the teams (tenants) the user can act
 *  in. No X-Tenant-Id — the backend returns ALL teams regardless of context. */
export async function fetchMe(token: string): Promise<MePayload> {
  const res = await http(`${API_BASE}/api/auth/me`, {
    headers: authHeaders(token),
  });
  const body = await parseBody(res);
  if (!res.ok) {
    throw new ApiError(res.status, errorMessage(body, "Session expired"), body);
  }
  return body as MePayload;
}

export async function chat(
  token: string,
  question: string,
  tenantId?: string,
): Promise<ChatResponse> {
  const res = await http(`${API_BASE}/api/kb/chat`, {
    method: "POST",
    headers: authHeaders(token, tenantId),
    body: JSON.stringify({ question }),
  });
  const body = await parseBody(res);
  if (!res.ok) {
    throw new ApiError(res.status, errorMessage(body, "Chat request failed"), body);
  }
  return body as ChatResponse;
}

export async function searchDocs(
  token: string,
  query: string,
  tenantId?: string,
): Promise<DocSearchResult[]> {
  const url = `${API_BASE}/api/kb/documents/search?q=${encodeURIComponent(query)}`;
  const res = await http(url, { headers: authHeaders(token, tenantId) });
  const body = await parseBody(res);
  if (!res.ok) {
    throw new ApiError(res.status, errorMessage(body, "Search failed"), body);
  }
  const data = (body as { data?: DocSearchResult[] }).data;
  return Array.isArray(data) ? data : [];
}

/** Full source text + metadata of a single document, for the fullpage MD
 *  viewer. Reachable by any authenticated reader and scoped to the caller's
 *  tenant + AccessScope (R30) — a 404 means "not found OR not yours", with no
 *  existence oracle. Works for both a search-result id and a chat citation's
 *  document_id (same KnowledgeDocument primary key). */
export async function fetchDocumentPreview(
  token: string,
  documentId: number,
  tenantId?: string,
): Promise<DocumentPreview> {
  const res = await http(`${API_BASE}/api/kb/documents/${documentId}/preview`, {
    headers: authHeaders(token, tenantId),
  });
  const body = await parseBody(res);
  if (!res.ok) {
    throw new ApiError(res.status, errorMessage(body, "Could not open document"), body);
  }
  return body as DocumentPreview;
}

/** Best-effort token revocation (stateless Bearer endpoint, no CSRF); a network
 *  failure here must not block the UI from clearing local state and returning to
 *  the login screen. */
export async function logout(token: string): Promise<void> {
  try {
    await http(`${API_BASE}/api/auth/token/revoke`, {
      method: "POST",
      headers: authHeaders(token),
    });
  } catch {
    // ignore — local logout still proceeds
  }
}
