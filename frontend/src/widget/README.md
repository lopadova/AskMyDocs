# KITT Widget — Embeddable AI Chat for Third-Party Sites

The AskMyDocs KITT widget is a lightweight, vanilla-TS chatbot that any
website can embed with two `<script>` tags. It connects to the AskMyDocs
RAG engine and can both **read** and **act on** the host page's DOM
(ReAct loop: type, click, select, navigate, submit…).

---

## Layout modes — Helper, Inline, and Fullscreen

The widget renders in one of three layouts, chosen per key (admin **Widget → Keys**,
field *Widget type*, also editable under **Appearance**) and baked into the snippet
by the **Embed** dialog. This is **independent** from the authentication mode
(A browser / B proxy, below).

- **`helper`** (default) — a floating launcher button pinned to a page corner that
  opens the chat in a popover. The classic site assistant (KITT).
- **`inline`** — the chat is a full block that fills a container you place on the
  page (100% of the mount element's width and height), with **no launcher**. Use it
  for a chat bound to a page.
- **`fullscreen`** — an always-open chat surface that fills the browser viewport,
  with no launcher or mount container. Use it for a dedicated assistant page and
  authenticated multi-device history.

### Helper (default)

```html
<script>
  window.AskMyDocsWidget = { key: 'pk_live_abc123', apiBase: 'https://kb.example.com' };
</script>
<script src="https://kb.example.com/widget/askmydocs-widget.js" defer></script>
```

### Inline chat

Place a container and point `mount` at it (a CSS selector). The container controls
the size; the chat fills it:

```html
<div id="askmydocs-chat" style="height: 600px;"></div>
<script>
  window.AskMyDocsWidget = {
    key: 'pk_live_abc123',
    apiBase: 'https://kb.example.com',
    mode: 'inline',
    mount: '#askmydocs-chat',
  };
</script>
<script src="https://kb.example.com/widget/askmydocs-widget.js" defer></script>
```

### Fullscreen chat

```html
<script>
  window.AskMyDocsWidget = {
    key: 'pk_live_abc123',
    apiBase: 'https://kb.example.com',
    mode: 'fullscreen',
  };
</script>
<script src="https://kb.example.com/widget/askmydocs-widget.js" defer></script>
```

`mode` and `mount` are **top-level** config (siblings of `key`), not part of the
`theme` block. If `mount` is missing or matches no element, the widget logs an error
to the console and does **not** mount — there is no silent fallback to a floating
launcher (R14). The key's saved type is stored server-side (`widget_keys.theme_config.mode`)
and surfaced via `GET /api/widget/setup` so the admin **Embed** dialog generates the
correct snippet automatically.

---

## Mode A — Browser Embed (default)

The simplest integration. The widget authenticates with a **public key**
(`pk_…`) and the backend enforces that the browser `Origin` header matches
the key's allowlist.

```html
<script>
  window.AskMyDocsWidget = { key: 'pk_live_abc123', apiBase: 'https://kb.example.com' };
</script>
<script src="https://kb.example.com/widget/askmydocs-widget.js" defer></script>
```

Every widget API request carries the `X-Widget-Key: pk_…` header.  The
backend resolves the tenant and project **from the key** (R30: the client
cannot override them).

---

## Mode B — Server-Side Proxy

When you don't want the public key in the browser (e.g. the host site
proxies widget requests through its own backend), use **Mode B**.

### 1. Issue a secret hash (`sk_…`)

Use the Artisan command (M5.1) or the admin UI (M6) to generate a
`secret_hash` for the widget key.  The secret is shown **once** and stored
as a bcrypt hash — it can never be recovered.

```bash
php artisan widget:emit-secret <public_key>
# Output: sk_abcdefghijklmnopqrstuvwxyz  ← save this, it won't be shown again
```

### 2. Proxy requests from your server

Your server-side proxy adds an `Authorization: Bearer sk_…` header to
every request it forwards to the AskMyDocs `/api/widget/*` endpoints.
The backend detects the Bearer token, verifies it against the stored
`secret_hash`, and grants **proxy mode** — no `Origin` check is performed
(server-to-server, high trust).

```typescript
// Example: Node.js / Express proxy endpoint
app.post('/api/widget-proxy/*', async (req, res) => {
  const upstream = await fetch(
    `https://kb.example.com/api/widget${req.path.replace('/api/widget-proxy', '')}`,
    {
      method: req.method,
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Widget-Key': 'pk_live_abc123',        // identifies the widget key
        'Authorization': 'Bearer sk_abcdefghijklmnopqrstuvwxyz', // proves proxy mode
      },
      body: JSON.stringify(req.body),
    },
  );
  const data = await upstream.json();
  res.status(upstream.status).json(data);
});
```

### 3. Frontend widget config for proxy

Point the widget's `apiBase` at your proxy instead of the AskMyDocs
instance directly:

```html
<script>
  window.AskMyDocsWidget = { key: 'pk_live_abc123', apiBase: 'https://your-site.com/api/widget-proxy' };
</script>
<script src="https://your-site.com/widget-proxy/askmydocs-widget.js" defer></script>
```

---

## Session Tokens (M5.2) — Optional One-Request Bearers

For additional security in Mode A, the widget can **mint a session token**
(`wt_…`) and use it as a Bearer token for exactly the next request. Session
tokens are:

- **Origin-bound** — only valid from the `Origin` that minted them.
- **Single-shot** — consumed after one request (R21: atomic consumption
  via `lockForUpdate`).
- **Short-lived** — TTL configurable via `WIDGET_SESSION_TOKEN_TTL`
  (default 30 minutes).

### Frontend usage

```typescript
// The Transport class handles this automatically:
const transport = new Transport({ key: 'pk_live_abc123', apiBase: 'https://kb.example.com' });

// Mint with the current credential: wu_ for an authenticated user, otherwise pk_.
const { token, expires_at } = await transport.mintSessionToken();

// The next request uses wt_. It is then consumed and Transport resumes wu_ or pk_.
await transport.start(snapshot, 'Hello');
```

### Server-side session token endpoint

```
POST /api/widget/session-token
Headers:
  X-Widget-Key: pk_…
  Origin: https://allowed-site.com
Body: { "session_id": "ses_123" }  (optional)
Response: { "token": "wt_...", "expires_at": "..." }
```

---

## Authentication Summary

| Mode | Header | Token | Origin check | Trust level |
|------|--------|-------|--------------|-------------|
| A (browser) | `X-Widget-Key: pk_…` | Public key | Required | Standard |
| A + session token | `Authorization: Bearer wt_…` | Single-use session token | Origin-bound | Enhanced |
| Authenticated host user | `Authorization: Bearer wu_…` | Short-lived user token | Origin-bound | Host-authenticated identity |
| B (proxy) | `Authorization: Bearer sk_…` | Server secret | None | High (server-to-server) |

## Authenticated host users and cross-device history

Per-key user authentication is opt-in under **Widget → Keys**. Enabling it
creates a separate `ik_…` server credential, shown once and rotatable. Keep it
on the host backend; never expose the credential or the host subject in HTML.

The host application remains responsible for authenticating the user. Its
backend exchanges a stable opaque subject (prefer a user UUID or an HMAC of an
internal id; do not use a mutable email):

```http
POST /api/widget/user-token
X-Widget-Key: pk_…
Authorization: Bearer ik_…
Content-Type: application/json

{"subject":"host-internal-stable-subject","origin":"https://portal.example"}
```

AskMyDocs stores only a keyed hash of that subject and returns a short-lived,
origin-bound `wu_…` token:

```json
{"token":"wu_…","expires_at":"2026-07-27T14:30:00+00:00"}
```

There is deliberately no AskMyDocs refresh token in the browser. The
recommended integration exposes a session-protected, same-origin endpoint on
the host application. The widget calls it with the host's normal cookie,
obtains `wu_…` in memory, renews it shortly before expiry, and retries once if
AskMyDocs reports `user_token_invalid`.

```php
// Host application: routes/web.php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

Route::get('/api/askmydocs/widget-user-token', function (Request $request) {
    $response = Http::acceptJson()
        ->withHeaders([
            'X-Widget-Key' => config('services.askmydocs.widget_public_key'),
        ])
        ->withToken(config('services.askmydocs.widget_identity_secret'))
        ->post(rtrim(config('services.askmydocs.url'), '/').'/api/widget/user-token', [
            // Prefer an immutable UUID; never accept subject from request input.
            'subject' => (string) $request->user()->getAuthIdentifier(),
            'origin' => config('services.askmydocs.widget_origin'),
        ])
        ->throw();

    return response()->json($response->only(['token', 'expires_at']))
        ->header('Cache-Control', 'no-store');
})->middleware('auth');
```

Point the widget at that host endpoint:

```html
<script>
  window.AskMyDocsWidget = {
    key: 'pk_…',
    apiBase: 'https://kb.example.com',
    userTokenUrl: '/api/askmydocs/widget-user-token'
  };
</script>
```

`userTokenUrl` must resolve to the same origin as the host page and return
exactly `{ token: "wu_…", expires_at: "ISO-8601" }`. The loader also accepts
`data-user-token-url`. A static `userToken: "wu_…"` remains supported for
server-rendered, short-lived pages, but it cannot be renewed after it expires.
The runtime requests the endpoint with `cache: "no-store"`; the host endpoint
should also return `Cache-Control: no-store`.

With a valid user token the runtime calls `GET
/api/widget/sessions/current`, restores the newest open conversation, and
replays its visible messages. The current-session endpoint filters
`active|waiting_user|waiting_tool`, orders by `updated_at DESC, id DESC`, and
returns `{ data: session }` or an empty `204`; it is deliberately independent
from paginated `GET /api/widget/sessions`. Replay remains `GET
/api/widget/sessions/{uuid}/replay`. Every endpoint is scoped to tenant, widget
key, project and pseudonymous identity. If authenticated mode is configured but
the host endpoint or history restore fails, the widget exposes an error and
keeps the composer disabled instead of silently creating an anonymous
conversation.

Identity credential lifecycle is explicit:

- **rotate `ik_`:** the old secret stops minting immediately; already-issued
  `wu_` tokens remain valid until `WIDGET_USER_TOKEN_TTL`;
- **disable user auth:** existing `wu_` and identity-bound `wt_` tokens are
  rejected immediately because validators check the live key on every request;
- **logout:** stop serving `userTokenUrl`, remove/reload the widget, and let the
  in-memory `wu_` disappear. AskMyDocs stores no browser refresh token.

---

## Cited source viewer

Grounded answers render at most eight deduplicated citation chips plus a
`Fonti · N` control. A citation carrying `document_id` is a button; opening it
shows every unique source from that answer in a native `<dialog>` inside the
widget Shadow DOM. The viewer uses a desktop sidebar, a compact selector and
fullscreen layout below 640px, and restores focus to the originating chip when
closed.

The selected source is fetched from:

```text
GET /api/widget/sessions/{session}/documents/{documentId}/preview
```

The response contains document metadata and ordered indexed sections. It is
available only when the exact tenant, widget key, project, optional user
identity and session match and the document really appears in a persisted
citation for that session. Missing, deleted, uncited or foreign documents all
return the same `404`; responses use `Cache-Control: no-store`. The browser
keeps only an in-memory cache keyed by session and document, aborts obsolete
requests and exposes loading, empty, retryable-error and success states.

```json
{
  "document_id": 42,
  "title": "Product guide",
  "source_path": "docs/product.md",
  "source_type": "markdown",
  "language": "en",
  "source_updated_at": "2026-08-07T10:00:00Z",
  "sections": [{ "heading_path": "Setup", "content": "..." }]
}
```

Section content is rendered as CommonMark/GFM using direct `micromark` and
`micromark-extension-gfm` dependencies. Raw HTML and dangerous protocols are
disabled, remote images are replaced with their alt text, and links are limited
to safe HTTP(S)/email destinations. Persisted evidence headings and snippets go
through the existing PII masker before replay.

---

## Appearance / Theming

Each widget key carries an optional **theme** (launcher button + chat panel
graphics, typography and source viewer). It is delivered in three layers, with
this precedence:

```
host CSS vars  >  inline (host snippet)  >  server (GET /api/widget/setup)  >  built-in default
```

- **Server-side (recommended):** edit the theme in the admin UI
  (**Widget → Keys → Appearance**, super-admin / `manageWidgetKeys`). It is
  stored per key (`widget_keys.theme_config`) and the widget loads it from
  `/api/widget/setup` at boot — change the look without re-pasting the snippet.
- **Inline:** bake the theme into the embed snippet (the **Embed** dialog has a
  *"Bake the saved appearance inline"* toggle). Useful for a frozen snapshot or
  to override the server theme on a specific host.

```html
<script>
  window.AskMyDocsWidget = {
    key: 'pk_live_abc123',
    apiBase: 'https://kb.example.com',
    theme: {
      accent: '#10b981',
      launcherShape: 'circle',      // pill | rounded | circle
      launcherSide: 'left',         // right | left
      launcherIcon: 'sparkles',     // chat | sparkles | help | none
      fontFamily: 'inter',          // system | inter | roboto | georgia | mono
      panelWidth: 640,
      panelShadow: 'soft',          // none | soft | medium | strong
      sourceViewerWidth: 960,
    },
  };
</script>
<script src="https://kb.example.com/widget/askmydocs-widget.js" defer></script>
```

**Theme fields** (all optional; omitted fields fall back as above):

| Group | Fields |
|-------|--------|
| Layout | `mode` (`helper`/`inline`/`fullscreen`; normally emitted as the top-level embed option) |
| Core colours (hex) | `accent`, `accentForeground`, `background`, `foreground`, `muted`, `border`, `headerBackground`, `headerForeground`, `launcherBackground`, `launcherForeground`, `userBubbleBackground`, `userBubbleForeground`, `assistantBubbleBackground`, `assistantBubbleForeground` |
| Composer + states (hex) | `composerBackground`, `inputBackground`, `inputForeground`, `inputPlaceholder`, `focusRing`, `systemBackground`, `systemForeground`, `errorBackground`, `errorForeground`, `confirmBackground`, `confirmForeground`, `confirmBorder` |
| Sources (hex) | `citationBackground`, `citationForeground`, `sourceSidebarBackground`, `sourceSidebarForeground`, `sourceBackdrop` |
| Typography | `fontFamily` (allowlist), `fontSize` (12–18) |
| Launcher | `launcherSide` (`right`/`left`), `launcherShape` (`pill`/`rounded`/`circle`), `launcherLabel`, `launcherIcon` (`chat`/`sparkles`/`help`/`none`), `launcherIconUrl` (https), `launcherOffsetX`/`launcherOffsetY` (0–96), `launcherSize` (40–80), `launcherShadow` (preset) |
| Panel | `panelWidth` (320–720), `panelHeight` (420–900), `panelRadius` (0–24), `panelShadow` (preset), `panelTitle`, `headerLogoUrl` (https) |
| Spacing + shape | `headerPaddingX`/`headerPaddingY` (0–40), `messagesPadding` (0–40), `messageGap` (0–32), `bubblePaddingX`/`bubblePaddingY` (0–32), `bubbleRadius` (0–32), `bubbleMaxWidth` (50–100%), `composerPadding` (0–32), `inputRadius`/`buttonRadius` (0–32), `logoHeight` (16–64) |
| Source viewer | `sourceViewerWidth` (560–1200), `sourceViewerRadius` (0–32); the viewer remains viewport-responsive and becomes fullscreen below 640px |

Every CSS-expressible camelCase token also has a kebab-case host variable.
For example, `accentForeground`, `panelWidth` and `sourceViewerRadius` map to
`--askmydocs-accent-foreground`, `--askmydocs-panel-width` and
`--askmydocs-source-viewer-radius`. Host variables include their CSS unit and
are inherited through the Shadow DOM:

```css
:root {
  --askmydocs-accent: #7c3aed;
  --askmydocs-panel-width: 680px;
  --askmydocs-source-backdrop: #111827dd;
}
```

Structural/string fields (`mode`, launcher side/shape/icon/label and image
URLs) remain typed configuration and do not have a CSS-variable equivalent.

### Agent handoff and JSON import

The **Agent handoff** action in the admin Appearance dialog copies a
self-contained prompt for a coding agent that can inspect the host site's
design system. The prompt contains a complete safe starting theme, every
supported field and its validation constraints, but never widget keys,
tenant/project identifiers, origins, tokens or API credentials. Free-form
labels and asset URLs are blanked so they cannot leak path credentials or act
as indirect prompt instructions; the agent infers public replacements from the
host interface. The agent must return only a portable profile with this
versioned envelope:

```json
{
  "_meta": {
    "format": "askmydocs.widget-theme",
    "version": 1
  },
  "theme": {
    "mode": "helper",
    "accent": "#7c3aed"
  }
}
```

The abbreviated `theme` above is illustrative only: an imported profile must
contain **every** `WidgetTheme` field. **Import JSON** accepts pasted content or
a `.json` file, validates the envelope and all values atomically, and rejects
missing or unknown fields instead of silently defaulting or clamping them. A
single clean fenced `json` code block is accepted; surrounding prose is not.

A successful import updates only the Appearance draft and live preview. The
operator must review it and press **Save appearance** before a `PATCH` is sent.
This exchange flow is admin-only and is not included in the embeddable widget
bundle.

**Security (R19):** every value is validated and sanitized on **both** sides —
the backend rejects invalid input with `422`, and the widget re-sanitizes inline
themes (colours must be hex, numbers are clamped, fonts and shadows come from
allowlists, image URLs must be `https`). Theme configuration flows into a
`<style>` inside the widget's Shadow DOM, so a malformed payload can never break
out or inject CSS. Host variables are ordinary CSS authored by the host site and
are never copied into generated style text. The single
source of truth for defaults + validation is `App\Services\Widget\WidgetThemeService`
(PHP) mirrored by `frontend/src/widget/ui/styles.ts` (`DEFAULT_THEME`,
`sanitizeTheme`, `buildThemeCss`).

---

## Architecture Overview

```
Host site ── widget (Shadow DOM) ── SnapshotBuilder reads host DOM
   │  POST /api/widget/sessions/start|step  { snapshot, message, tool_result }
   ▼  (A: browser pk_+Origin   |   B: proxy server-to-server pk_+sk_ bearer)
AskMyDocs /api/widget/*  ── ResolveWidgetKey: tenant+project FROM KEY (R30)
   WidgetOrchestratorService:
     • RAG grounding (ChatRetrievalService) on question + page context
     • LLM function-calling (AiManager::chatWithHistory, tool_choice=auto)
       → grounded answer with citations, or one DOM tool_call
     • validates tool_call against snapshot, persists step
   ↳ returns { type:'message'+citations | type:'tool_call' | type:'blocked' }
   → widget Executor runs DOM action → new snapshot → step → loop
```

---

## DOM Tools

The widget can execute the following tools on the host page's DOM,
validated against the current snapshot before execution:

| Tool | Description |
|------|-------------|
| `click` | Click an element by selector |
| `type` | Type text into an input field |
| `select` | Select an option in a `<select>` |
| `scroll_to` | Scroll to an element |
| `navigate_to` | Navigate to a URL on the same page |
| `submit_form` | Submit a form by selector |
| `read_page` | Read page content (no mutation) |
| `combobox_search` | Search in a combobox/dropdown |
| `combobox_set` | Select a combobox option |
| `toggle` | Toggle a checkbox or switch |
| `radio` | Select a radio button |
| `set_locale` | Switch locale via `data-kitt-locale` element |
| `goto_step` | Navigate to a step in a multi-step form |
| `wait_for` | Wait for an element to appear |
| `tour_step` | Show a tour/guidance step |
| `move_cursor` | Move cursor to an element |
| `show_recap` | Display a recap/summary panel |

---

## Host Tools (F1.7) — FE-proxied tools from the embedding app

Beyond DOM tools and the AskMyDocs BE tool (`search_knowledge_base`), the
widget can route **host tools** defined and executed by the embedding app
(e.g. gescat domain tools). The host tools never run through the DOM
executor or `/exec-tool`: the widget proxies them to the host app using
the **logged-in user's session cookie** (FE-proxied mode).

### Embed configuration (data-attributes)

```html
<meta name="csrf-token" content="…">  <!-- Laravel standard -->

<script
  src="https://kb.example.com/widget/askmydocs-widget.js"
  data-public-key="pk_live_…"
  data-api-base="https://kb.example.com"
  data-skill="gescat-assistant@1"
  data-host-manifest-url="/admin/ai/tools-manifest"
  data-host-exec-url="/admin/ai/tools-exec"
  defer></script>
```

`data-*` attributes take precedence over the `window.AskMyDocsWidget`
object. The CSRF token is read from `<meta name="csrf-token">` first,
falling back to `data-csrf-token` on the embed script.

### Flow

1. **Manifest** — if `data-host-manifest-url` is set, on session start the
   widget does `fetch(url, { credentials: 'same-origin' })`, expects
   `{ schema_version, tools:[{name,description,parameters,execution:"host"}] }`,
   and includes those `tools` as `snapshot.host_tools` so the orchestrator
   passes them to the LLM. The fetch is **non-blocking**: on any failure
   `host_tools` is omitted and the widget continues in RAG-only mode.
2. **Execution** — when the orchestrator returns a `tool_call` marked
   `execution: "host"` (or `is_host_tool: true`), the widget does
   `POST {data-host-exec-url}` with
   `{ tool, args, session_ref: <public_session_id> }`, headers
   `{ 'Content-Type':'application/json', 'X-CSRF-TOKEN': <token> }`,
   `credentials: 'same-origin'`. Expected response:
   `{ ok:true, artifact:{…} }` or `{ ok:false, error, message }`.
3. **Reinjection** — the artifact is rendered (reusing `UiArtifactRenderer`)
   and the next turn posts `POST …/step` with
   `tool_result: { tool, execution:"host", ok, artifact }`.
4. **Errors** — on `ok:false`, a missing `data-host-exec-url`, or a network
   error, the widget shows an error in the thread **and** still reinjects a
   `tool_result` with `ok:false` so the LLM can react and the session never
   hangs.

### Artifact mapping (gescat → widget renderer)

The widget renderer is the source of truth. Native types
(`ui-data-table`, `ui-kpi`, `ui-kpi-grid`, `ui-alert`, `ui-card`,
`ui-badge`, `ui-toast`, `ui-list`, `ui-chart`, `markdown`, `code-block`,
`citations`) render with their dedicated renderer. gescat extras
`ui-articolo-card` / `ui-categoria-card` fall back to `ui-card` with
normalized props; any other unknown type falls back to a safe `ui-card`
render (never throws). The original `componentType` is kept on the
container's `data-source-component-type` for debugging/E2E.

---

## Widget Events

The widget dispatches custom events on the host page's `window` object
so site owners can react to widget lifecycle changes:

| Event | Detail | When |
|-------|--------|------|
| `amd:ready` | `{ key: pk_… }` | Widget loaded and connected |
| `amd:session-start` | `{ sessionId: UUID }` | New conversation started |
| `amd:message` | `{ role, content }` | Message exchanged |
| `amd:tool-call` | `{ tool, args }` | DOM tool about to execute |
| `amd:tool-result` | `{ tool, result }` | DOM tool execution result |
| `amd:session-end` | `{ sessionId }` | Session completed or aborted |
| `amd:error` | `{ message }` | Unrecoverable error |

```javascript
window.addEventListener('amd:message', (e) => {
  console.log('Widget message:', e.detail);
});
```

---

## Security Model

1. **Origin allowlist** (Mode A): exact-match only, no regex/substring
   (R19). `https://evil-example.com` will not match `https://example.com`.
2. **Tenant resolution from key** (R30): the client never specifies
   tenant/project — it's always derived from the key.
3. **PII masking** (M5): all personally identifiable information is
   masked (tokenised) before storage. Re-detokenisation requires
   `detokenisePiiRedactor` gate.
4. **Rate limiting**: per (key + IP), configurable per key (default 60/min).
5. **Session-token replay protection** (M5.2): single-shot `wt_…` tokens are
   accepted for one request only.
6. **Auto-purge**: old sessions are pruned by `widget:prune-sessions`
   (configurable retention, see `config/widget.php`).
7. **RBAC**: admin management (create/rotate/revoke keys) requires
   `manageWidgetKeys` gate (super-admin only). Session inspection
   requires `viewWidgetSessions` gate (admin + super-admin).

---

## Admin Management (M6)

Widget keys and sessions are managed via the admin SPA at
`/app/admin/widget` (requires `super-admin` role for key management,
`admin`+`super-admin` for session inspection).

### API Endpoints

**Key management** (`manageWidgetKeys` gate — super-admin only):

| Method | URI | Action |
|--------|-----|--------|
| GET | `/api/admin/widget-keys` | List keys |
| POST | `/api/admin/widget-keys` | Create key (returns `plain_secret` once) |
| PATCH | `/api/admin/widget-keys/{id}` | Update label, origins, rate_limit, skill |
| DELETE | `/api/admin/widget-keys/{id}` | Hard delete (cascading) |
| POST | `/api/admin/widget-keys/{id}/rotate` | Regenerate pk_ + sk_ (returns new credentials once) |
| POST | `/api/admin/widget-keys/{id}/revoke` | Set `is_active=false` (preserves data) |
| POST | `/api/admin/widget-keys/{id}/rotate-identity-secret` | Rotate `ik_` once; requires `identity_credential_version` |

Enabling/disabling user auth through `PATCH` also requires the current
`identity_credential_version`; stale writes return `409
identity_credential_conflict`. Every identity mutation is tenant-scoped,
transactional and written to `admin_command_audit` without plaintext or hashes.
There is deliberately no MCP mutation: a one-time `ik_` must not enter an agent
transcript.

**Session inspection** (`viewWidgetSessions` gate — admin + super-admin):

| Method | URI | Action |
|--------|-----|--------|
| GET | `/api/admin/widget-sessions` | List sessions (filter by `widget_key_id`, `status`) |
| GET | `/api/admin/widget-sessions/{id}` | Detail with steps |

### CLI

```bash
# Prune sessions older than the configured retention
php artisan widget:prune-sessions

# Issue a new secret for an existing key
php artisan widget:emit-secret <public_key>

# Inspect first, then mutate with optimistic version protection
php artisan widget:identity-credential status 42 --tenant=acme
php artisan widget:identity-credential rotate 42 --tenant=acme \
  --expected-version=3 --force
```
