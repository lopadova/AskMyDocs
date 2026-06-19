# AskMyDocs Desktop (demo)

A small **Tauri v2 + React** desktop client for AskMyDocs. It demonstrates the
three things a non-browser client needs against the Laravel backend:

- **Login** — `POST /api/auth/token` issues a Sanctum **Bearer token** (no
  cookie/CSRF dance). The token is stored locally and survives restarts.
- **Chat** — `POST /api/kb/chat` (stateless, grounded answers with citations
  and a confidence badge). Conversation threads are kept **locally** on disk.
- **Search** — `GET /api/kb/documents/search` (document title/path
  autocomplete).

All backend calls go through the **Tauri HTTP plugin** (Rust side), so the
webview never performs a cross-origin request and the backend needs **no CORS
change**.

> This app lives inside the AskMyDocs repo but is a self-contained project with
> its own `package.json` and Rust crate. It is not wired into the Laravel CI.

---

## Prerequisites

1. **Rust toolchain** + Tauri v2 system deps — see
   <https://tauri.app/start/prerequisites/>.
2. **Node ≥ 20**.
3. A **running AskMyDocs backend** on `http://localhost:8000` with the
   `personal_access_tokens` table migrated:

   ```bash
   # from the repo root
   php artisan migrate          # creates personal_access_tokens (added for this client)
   php artisan serve            # serves http://localhost:8000
   ```

   You also need a user to log in with (any seeded user, or create one via
   tinker). The token endpoint authenticates the same credentials as the web
   login.

If your backend runs elsewhere, change **all three** in lockstep:

- `API_BASE` in [`src/lib/api.ts`](src/lib/api.ts)
- the HTTP scope in
  [`src-tauri/capabilities/default.json`](src-tauri/capabilities/default.json)
- this README

---

## Run

```bash
cd desktop
npm install
npm run tauri dev      # launches the desktop window with hot-reload
```

Build a distributable bundle:

```bash
npm run tauri build
```

---

## Project layout

```
desktop/
├── src/                     # React app (Vite)
│   ├── lib/api.ts           # API client over @tauri-apps/plugin-http
│   ├── lib/store.ts         # token + threads persistence (@tauri-apps/plugin-store)
│   ├── lib/types.ts
│   ├── screens/LoginScreen.tsx
│   ├── screens/ChatScreen.tsx
│   └── screens/SearchScreen.tsx
└── src-tauri/               # Rust shell (registers http + store plugins)
```

## Notes / out of scope

- **Conversation history is local.** The server's `/conversations` endpoints
  use the web-session guard, so a Bearer client can't reach them; each thread
  lives in the local store and every turn hits the stateless `/api/kb/chat`.
- The Bearer token is kept in the plugin store (plaintext on disk). Hardening
  it into the OS keychain is a follow-up, not part of this demo.
