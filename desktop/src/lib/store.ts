// Local persistence via the Tauri store plugin: the Bearer session survives
// app restarts, and conversation threads live entirely on disk (the demo's
// stateless chat keeps no per-conversation history server-side).
import { load, type Store } from "@tauri-apps/plugin-store";
import type { AuthUser, Thread } from "./types";

const STORE_FILE = "askmydocs.json";

let storePromise: Promise<Store> | null = null;

function store(): Promise<Store> {
  if (!storePromise) {
    // autoSave defaults to a 100ms debounce; we also call save() explicitly
    // after each mutation so persistence is deterministic.
    storePromise = load(STORE_FILE);
  }
  return storePromise;
}

export interface Session {
  token: string;
  user: AuthUser;
}

export interface WorkspaceContext {
  tenantId: string;
  projectKey: string;
}

export async function loadSession(): Promise<Session | null> {
  const s = await store();
  const token = await s.get<string>("token");
  const user = await s.get<AuthUser>("user");
  if (!token || !user) {
    return null;
  }
  return { token, user };
}

export async function saveSession(session: Session): Promise<void> {
  const s = await store();
  await s.set("token", session.token);
  await s.set("user", session.user);
  // This function is called only after an interactive login/registration.
  // Force that new authentication to choose its own workspace instead of
  // inheriting a tenant/project pair from a previous token.
  await s.delete("active_context");
  await s.delete("active_tenant");
  await s.save();
}

export async function clearSession(): Promise<void> {
  const s = await store();
  await s.delete("token");
  await s.delete("user");
  await s.delete("active_context");
  // Remove the tenant-only key written by older desktop builds. A new login
  // must always establish the complete tenant + project context.
  await s.delete("active_tenant");
  await s.save();
}

export async function loadWorkspaceContext(): Promise<WorkspaceContext | null> {
  const s = await store();
  const context = await s.get<WorkspaceContext>("active_context");
  if (
    !context ||
    typeof context.tenantId !== "string" ||
    context.tenantId === "" ||
    typeof context.projectKey !== "string" ||
    context.projectKey === ""
  ) {
    return null;
  }
  return context;
}

export async function saveWorkspaceContext(
  context: WorkspaceContext,
): Promise<void> {
  const s = await store();
  await s.set("active_context", context);
  await s.delete("active_tenant");
  await s.save();
}

export async function clearWorkspaceContext(): Promise<void> {
  const s = await store();
  await s.delete("active_context");
  await s.save();
}

function threadStoreKey(tenantId: string, projectKey: string): string {
  return `threads:${encodeURIComponent(tenantId)}:${encodeURIComponent(projectKey)}`;
}

export async function loadThreads(
  tenantId: string,
  projectKey: string,
): Promise<Thread[]> {
  const s = await store();
  const threads = await s.get<Thread[]>(threadStoreKey(tenantId, projectKey));
  return Array.isArray(threads) ? threads : [];
}

export async function saveThreads(
  tenantId: string,
  projectKey: string,
  threads: Thread[],
): Promise<void> {
  const s = await store();
  await s.set(threadStoreKey(tenantId, projectKey), threads);
  await s.save();
}
