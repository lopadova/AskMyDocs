# AskMyDocs Desktop

A **Tauri v2 + React** client for AskMyDocs, with the same warm editorial
palette and action components as the web application. Light/dark appearance
follows the system initially and can be changed in the workspace toolbar.

The current desktop workspace includes:

- **Sessions on this device** — search conversation titles, rename sessions,
  pin important ones, archive/restore them, and copy an answer. Existing local
  histories remain compatible. These controls do not sync with web sessions.
- **Knowledge** — browse project folders and canonical documents using
  `GET /api/kb/tree?project=…&mode=all`, then open their source text. An older
  server without this endpoint shows a compatibility message; Search remains
  available. Tenant, project and Bearer scope accompany every request.
- **Accessible reading** — a native HTML dialog contains keyboard focus and
  closes with Escape, alongside mobile safe areas and reduced-motion support.

Authentication and retrieval use the Laravel backend:

- **Login** — `POST /api/auth/token` issues a Sanctum **Bearer token** (no
  cookie/CSRF dance). The token is stored locally and survives restarts.
- **Sign up** — `POST /api/auth/register-token` creates an **invite-only**
  account (a valid invite code is required), redeems the code server-side, and
  returns a Bearer token in the same shape as login. It's the stateless
  counterpart of the web `POST /api/auth/register`; both flows mint the desktop
  token through `App\Support\DesktopToken` so the scope/TTL never drift.
- **Workspace selection** — after authentication, `GET /api/auth/me` supplies
  the user's tenants and project memberships. The app requires an explicit
  **tenant → project** selection before opening the workspace, persists that
  pair across restarts, and lets the user switch either value inside the app.
- **Chat** — `POST /api/kb/chat` (stateless, grounded answers with citations
  and a confidence badge). Answers render as **markdown**, and each citation
  opens its source document in the viewer. The selected tenant is sent as
  `X-Tenant-Id`; the selected project is sent as `X-Project-Key` and
  `project_key`. Conversation threads are kept **locally** on disk and isolated
  per tenant/project pair.
- **Search** — `GET /api/kb/documents/search` (document title/path
  autocomplete), scoped with the selected tenant and `project_keys[]`.
- **Document viewer** — `GET /api/kb/documents/{id}/preview` returns a
  document's full source text (reconstructed from its chunks, tenant +
  AccessScope scoped). Clicking a search result or a chat citation opens a
  **fullpage modal** rendering the markdown.

All backend calls go through the **Tauri HTTP plugin** (Rust side), so the
webview never performs a cross-origin request and the backend needs **no CORS
change**.

> The client has its own `package.json` and Rust crate. Its canonical Button
> and button CSS are shared from `../frontend/src`, so build from this checkout.
> Desktop tests use the repository's existing Vitest dependencies.

---

## Prerequisites

1. **Rust toolchain** + Tauri v2 system deps — see
   <https://tauri.app/start/prerequisites/>.
2. **Node ≥ 20**.
3. An **AskMyDocs backend** reachable. By default the client targets the
   **production deployment** at **`https://askmydocs.surfacesrl.com`**, so it
   works out of the box — you just need a user to log in with. To develop
   against a local backend instead (served by Valet/Herd at
   `https://askmydocsdev.test`, the repo's `APP_URL`), override `VITE_API_BASE`
   (see below). The backend must run a branch that includes the desktop auth
   endpoints (`feature/desktop-demo` or merged) and have the
   `personal_access_tokens` table migrated:

   ```bash
   # from the repo root, on the branch with this demo
   php artisan migrate          # creates personal_access_tokens (added for this client)
   ```

   The token endpoint authenticates the same credentials as the web login.

To target a different backend, override the base URL at build time with
**`VITE_API_BASE`** (no code edit needed) and keep the HTTP scope in step:

- `VITE_API_BASE=https://my-host npm run dev` — overrides `API_BASE` (default
  `https://askmydocs.surfacesrl.com`; see [`src/lib/api.ts`](src/lib/api.ts))
- the HTTP scope in
  [`src-tauri/capabilities/default.json`](src-tauri/capabilities/default.json)
  (allows `https://askmydocs.surfacesrl.com`, `https://askmydocs.test`,
  `localhost:8000`, and the `192.168.*.*` / `10.*.*` LAN ranges)
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

### App icons

The source artwork and its reproducible Tauri manifest live in
`src-tauri/icons/icon-master.png` and `src-tauri/icons/icon-manifest.json`.
Regenerate the macOS, iOS, Windows, and Android icon sets after changing the
master with:

```bash
npm run icons
```

The master is an opaque full-square App Store icon; Android launcher assets
include adaptive and round variants with the navy background declared by the
manifest.

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

> Code signing uses the Apple Developer **Team ID** preset in
> [`src-tauri/tauri.conf.json`](src-tauri/tauri.conf.json)
> (`bundle.iOS.developmentTeam`). Build under a different team by overriding
> the `APPLE_DEVELOPMENT_TEAM` env var.

**Reach the backend from the device.** Use the configured production backend
on a physical iPhone, or an existing authorized Herd share. For local simulator
work, inspect the Herd site for this checkout and use its scheme with
`askmydocsdev.test`. Keep the HTTP capability allowlist in step with the
selected backend. Do not replace Herd with `php artisan serve`.

Before any iOS/Android distribution build, complete the native mobile view
check (small and large devices, keyboard, safe areas, gestures and text size).
The no-zoom viewport contract and 16px mobile fields alone do not certify a
native mobile release. See [UI verification](UI-VERIFICATION.md).

---

## Project layout

```
desktop/
├── src/                     # React app (Vite)
│   ├── lib/api.ts           # API client over @tauri-apps/plugin-http
│   ├── lib/store.ts         # token + threads persistence (@tauri-apps/plugin-store)
│   ├── lib/types.ts
│   ├── components/Markdown.tsx       # react-markdown + remark-gfm (links → opener)
│   ├── components/DocumentModal.tsx  # fullpage MD viewer (GET …/preview)
│   ├── screens/LoginScreen.tsx
│   ├── screens/RegisterScreen.tsx    # invite-only Bearer sign-up
│   ├── screens/WorkspaceScreen.tsx   # tenant → project session picker
│   ├── screens/ChatScreen.tsx
│   └── screens/SearchScreen.tsx
└── src-tauri/               # Rust shell (registers http + store plugins)
```

## Notes / out of scope

- **Conversation history is local and workspace-scoped.** The server's
  `/conversations` endpoints use the web-session guard, so a Bearer client can't
  reach them; each thread lives under its tenant/project store key and every
  turn hits the stateless `/api/kb/chat`.
- The Bearer token is kept in the plugin store (plaintext on disk). Hardening
  it into the OS keychain is a follow-up, not part of this demo.
- **Local TLS:** the Tauri HTTP plugin (rustls) doesn't trust the local-CA cert
  Valet/Herd serve `.test` hosts with, so cert verification is relaxed **for
  local dev hosts only** (`api.ts` `LOCAL_DEV` guard + the `dangerous-settings`
  Cargo feature). A real `https://` API_BASE keeps full verification. After
  changing the Cargo feature or `api.ts`, **restart `npm run tauri dev`** so the
  Rust shell recompiles.

## Verification

From the repository root, after installing its dependencies:

```bash
./node_modules/.bin/vitest run --config desktop/vitest.config.ts
./node_modules/.bin/vitest run frontend/src/components/Button.test.tsx
npm run build --prefix desktop
npm run tauri --prefix desktop -- build --bundles app
```

A local macOS bundle is created under
`desktop/src-tauri/target/release/bundle/macos/AskMyDocs Desktop.app`.
This is a local build, without distribution signing or notarization.
