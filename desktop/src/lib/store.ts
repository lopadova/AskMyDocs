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
  await s.save();
}

export async function clearSession(): Promise<void> {
  const s = await store();
  await s.delete("token");
  await s.delete("user");
  await s.save();
}

export async function loadThreads(): Promise<Thread[]> {
  const s = await store();
  const threads = await s.get<Thread[]>("threads");
  return Array.isArray(threads) ? threads : [];
}

export async function saveThreads(threads: Thread[]): Promise<void> {
  const s = await store();
  await s.set("threads", threads);
  await s.save();
}
