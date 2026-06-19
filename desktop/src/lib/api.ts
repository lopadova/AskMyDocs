// Thin API client over the Tauri HTTP plugin. Requests are issued from the
// Rust side, so the webview never does a cross-origin fetch and the Laravel
// backend needs no `tauri://` entry in its CORS allow-list.
//
// Change API_BASE if your backend runs elsewhere — and mirror it in
// src-tauri/capabilities/default.json (the HTTP scope) AND the README.
import { fetch } from "@tauri-apps/plugin-http";
import type {
  AuthUser,
  ChatResponse,
  DocSearchResult,
  TokenResponse,
} from "./types";

// Local backend served by Valet/Herd (matches the repo's APP_URL).
export const API_BASE = "https://askmydocs.test";

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

function authHeaders(token?: string): Record<string, string> {
  const headers: Record<string, string> = {
    Accept: "application/json",
    "Content-Type": "application/json",
  };
  if (token) {
    headers.Authorization = `Bearer ${token}`;
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
  const res = await fetch(`${API_BASE}/api/auth/token`, {
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

export async function fetchMe(token: string): Promise<AuthUser> {
  const res = await fetch(`${API_BASE}/api/auth/me`, {
    headers: authHeaders(token),
  });
  const body = await parseBody(res);
  if (!res.ok) {
    throw new ApiError(res.status, errorMessage(body, "Session expired"), body);
  }
  return (body as { user: AuthUser }).user;
}

export async function chat(token: string, question: string): Promise<ChatResponse> {
  const res = await fetch(`${API_BASE}/api/kb/chat`, {
    method: "POST",
    headers: authHeaders(token),
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
): Promise<DocSearchResult[]> {
  const url = `${API_BASE}/api/kb/documents/search?q=${encodeURIComponent(query)}`;
  const res = await fetch(url, { headers: authHeaders(token) });
  const body = await parseBody(res);
  if (!res.ok) {
    throw new ApiError(res.status, errorMessage(body, "Search failed"), body);
  }
  const data = (body as { data?: DocSearchResult[] }).data;
  return Array.isArray(data) ? data : [];
}

/** Best-effort token revocation (stateless Bearer endpoint, no CSRF); a network
 *  failure here must not block the UI from clearing local state and returning to
 *  the login screen. */
export async function logout(token: string): Promise<void> {
  try {
    await fetch(`${API_BASE}/api/auth/token/revoke`, {
      method: "POST",
      headers: authHeaders(token),
    });
  } catch {
    // ignore — local logout still proceeds
  }
}
