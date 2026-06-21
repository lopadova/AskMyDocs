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
3. The **AskMyDocs backend** reachable at **`https://askmydocs.test`** (the
   repo's `APP_URL`, served by Valet/Herd). It must run a branch that includes
   the desktop auth endpoints (`feature/desktop-demo` or merged) and have the
   `personal_access_tokens` table migrated:

   ```bash
   # from the repo root, on the branch with this demo
   php artisan migrate          # creates personal_access_tokens (added for this client)
   ```

   You also need a user to log in with (any seeded user, or create one via
   tinker). The token endpoint authenticates the same credentials as the web
   login.

If your backend runs elsewhere, override the base URL at build time with
**`VITE_API_BASE`** (no code edit needed) and keep the HTTP scope in step:

- `VITE_API_BASE=https://my-host npm run dev` — overrides `API_BASE` (default
  `https://askmydocs.test`; see [`src/lib/api.ts`](src/lib/api.ts))
- the HTTP scope in
  [`src-tauri/capabilities/default.json`](src-tauri/capabilities/default.json)
  (allows `https://askmydocs.test`, `localhost:8000`, and the `192.168.*.*` /
  `10.*.*` LAN ranges)
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

## Run on iPhone (iOS)

The same codebase targets iOS via **Tauri v2 mobile**. The Rust shell already
carries the `mobile_entry_point` and all three plugins (http / store / opener)
support iOS, so there are **no native code changes** — the UI is responsive and
notch/home-indicator aware (safe-area insets, a bottom tab bar, and the chat
thread list as an off-canvas drawer).

**Extra prerequisites:** macOS with **Xcode** + command-line tools, an Apple
signing identity, and the iOS Rust targets:

```bash
xcode-select --install   # if not already installed
rustup target add aarch64-apple-ios aarch64-apple-ios-sim x86_64-apple-ios
```

**One-time** — generate the Xcode project (lands in `src-tauri/gen/apple`, which
is gitignored):

```bash
cd desktop
npm install
npm run ios:init
```

**Reach the backend from the device.** On a physical iPhone, `askmydocs.test`
and `localhost` resolve to the *phone*, not your Mac — point `API_BASE` at your
Mac's LAN IP at build time, and serve Laravel on the LAN:

```bash
# 1. serve the backend so the phone can reach it (from the repo root)
php artisan serve --host 0.0.0.0 --port 8000

# 2. find your Mac's LAN IP, e.g. 192.168.1.50
ipconfig getifaddr en0

# 3. run on a connected device / simulator
VITE_API_BASE=http://192.168.1.50:8000 npm run ios:dev
```

The HTTP capability scope already allows the `192.168.*.*` and `10.*.*` ranges
(http + https). If your LAN uses `172.16–31.*`, add a matching line to
[`src-tauri/capabilities/default.json`](src-tauri/capabilities/default.json).

> The **iOS Simulator** shares the Mac's network, so there you can use
> `VITE_API_BASE=http://localhost:8000` (or the default `.test` host if Valet/Herd
> is running) without the LAN IP.

**Build an `.ipa`:**

```bash
VITE_API_BASE=http://<mac-lan-ip>:8000 npm run ios:build
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
- **Local TLS:** the Tauri HTTP plugin (rustls) doesn't trust the local-CA cert
  Valet/Herd serve `.test` hosts with, so cert verification is relaxed **for
  local dev hosts only** (`api.ts` `LOCAL_DEV` guard + the `dangerous-settings`
  Cargo feature). A real `https://` API_BASE keeps full verification. After
  changing the Cargo feature or `api.ts`, **restart `npm run tauri dev`** so the
  Rust shell recompiles.
