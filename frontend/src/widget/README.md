# KITT Widget — Embeddable AI Chat for Third-Party Sites

The AskMyDocs KITT widget is a lightweight, vanilla-TS chatbot that any
website can embed with two `<script>` tags. It connects to the AskMyDocs
RAG engine and can both **read** and **act on** the host page's DOM
(ReAct loop: type, click, select, navigate, submit…).

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
php artisan widget:issue-secret <public_key>
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

## Session Tokens (M5.2) — Avoiding Repeated `pk_` in the Browser

For additional security in Mode A, the widget can **mint a session token**
(`wt_…`) from the backend and use it as a Bearer token for subsequent
requests instead of sending the public key on every call.  Session tokens
are:

- **Origin-bound** — only valid from the `Origin` that minted them.
- **Single-shot** — consumed after one request (R21: atomic consumption
  via `lockForUpdate`).
- **Short-lived** — TTL configurable via `WIDGET_SESSION_TOKEN_TTL`
  (default 30 minutes).

### Frontend usage

```typescript
// The Transport class handles this automatically:
const transport = new Transport({ key: 'pk_live_abc123', apiBase: 'https://kb.example.com' });

// Mint a session token (uses X-Widget-Key once)
const { token, expires_at } = await transport.mintSessionToken();

// All subsequent requests use Authorization: Bearer *** instead of X-Widget-Key
// The token is consumed after the first request and the Transport falls back to pk mode.
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
| A + session token | `Authorization: Bearer *** | Session token (`wt_…`) | Origin-bound | Enhanced |
| B (proxy) | `Authorization: Bearer ***` | Secret hash (`sk_…`) | None | High (server-to-server) |

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
5. **Session token rotation** (M5.2): single-shot `wt_…` tokens prevent
   credential reuse in browser mode.
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
php artisan widget:issue-secret <public_key>
```