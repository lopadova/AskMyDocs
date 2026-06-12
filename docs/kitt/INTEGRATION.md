# KITT (Knowledge Interface Tour Toolkit) — developer integration guide

> **KITT** — **K**nowledge **I**nterface **T**our **T**oolkit — is the
> embeddable, page-aware, agentic chat widget that ships with
> AskMyDocs. Any external website can drop it in with a `<script>` tag: it
> answers questions grounded on a tenant's knowledge base **with citations**,
> reads the host page's DOM, and — when allowed — drives the page (click, type,
> select, navigate, submit) and calls backend tools through a ReAct loop.
>
> This guide is the **junior-proof** integration reference: every snippet,
> header, env var, route and manifest key is copied from the source of truth
> (`config/widget.php`, `routes/api.php`, `app/Http/Middleware/ResolveWidgetKey.php`,
> `resources/widget/skills/*`, `frontend/src/widget/*`). Quick-start first, then
> the full contract.

---

## Table of contents

1. [What you get](#1-what-you-get)
2. [Quick start (5 minutes)](#2-quick-start-5-minutes)
3. [Create & manage a widget key (admin)](#3-create--manage-a-widget-key-admin)
4. [Embed snippet & configuration](#4-embed-snippet--configuration)
5. [Authentication modes](#5-authentication-modes)
6. [HTTP API reference](#6-http-api-reference)
7. [Skills (manifests, tools, policies)](#7-skills-manifests-tools-policies)
8. [Annotating the host page (`data-kitt-*`)](#8-annotating-the-host-page-data-kitt-)
9. [Host-Tools Protocol (HTP)](#9-host-tools-protocol-htp)
10. [Admin SPA surface](#10-admin-spa-surface)
11. [Database tables](#11-database-tables)
12. [Configuration & env vars](#12-configuration--env-vars)
13. [Local demo page](#13-local-demo-page)
14. [Security model](#14-security-model)
15. [Troubleshooting](#15-troubleshooting)

---

## 1. What you get

- **Grounded RAG chat** over a tenant's KB, with citations — the same retrieval
  pipeline (`KbSearchService` + reranker + refusal gate) the first-party chat uses.
- **Page awareness**: the widget captures a structured *snapshot* of the current
  page (regions, fields, actions, messages, page outline) and sends it with each
  turn so the agent reasons about what's actually on screen.
- **Agentic actions**: the LLM can emit tool calls that the widget executes in the
  page DOM (click/type/select/navigate/submit) or that AskMyDocs executes
  server-side (e.g. `search_knowledge_base`), in a bounded ReAct loop.
- **Host-Tools Protocol**: the host app can expose its *own* tools to the agent,
  double-gated (per key **and** per skill) so it is off unless you explicitly enable it.
- **Tenant-safe by construction**: tenant + project are resolved **server-side from
  the key** — the browser never names a tenant. Origin allowlisting, single-use
  origin-bound session tokens, per-key + per-session rate limits, PII masking on
  every persisted step.
- **Admin SPA**: create/rotate/revoke keys, manage allowed origins + theme, toggle
  host-tools, and replay every session step (PII-masked).

---

## 2. Quick start (5 minutes)

1. **Create a key** in the admin SPA (super-admin): `/app/admin/widget` → *Create
   key* → set a `label`, a `project_key`, and the allowed origin(s) of the site
   that will embed it. Copy the `pk_…` public key (the `sk_…` secret is shown
   **once** — only needed for server-to-server mode).

2. **Paste the snippet** into the host page, just before `</body>`:

   ```html
   <script>
     window.AskMyDocsWidget = {
       key: 'pk_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
       apiBase: 'https://your-askmydocs.example.com'
     };
   </script>
   <script src="https://your-askmydocs.example.com/widget/askmydocs-widget.js" async></script>
   ```

3. **Add the embedding origin** to the key's *Allowed origins* (exact scheme + host
   + port, e.g. `https://app.example.com`). Without a matching `Origin` the API
   answers `403`.

4. Reload the host page — the floating launcher appears. Open it and ask a question;
   answers come back grounded with citations.

> The exact ready-to-paste snippet for a given key is also available in the admin
> SPA via the **Embed code** dialog on the key row.

---

## 3. Create & manage a widget key (admin)

All key management is **super-admin** only, tenant-scoped (`manageWidgetKeys` gate).

| Action | Endpoint |
|---|---|
| List keys | `GET /api/admin/widget-keys` |
| Create | `POST /api/admin/widget-keys` |
| Update (origins / rate limit / theme / skill / host-tools) | `PATCH /api/admin/widget-keys/{id}` |
| Delete | `DELETE /api/admin/widget-keys/{id}` |
| Rotate `pk_`+`sk_` (returns secret once) | `POST /api/admin/widget-keys/{id}/rotate` |
| Revoke (set `is_active=false`) | `POST /api/admin/widget-keys/{id}/revoke` |

`POST` body fields:

| Field | Required | Notes |
|---|:---:|---|
| `label` | ✅ | Unique per `(tenant, project_key)` → duplicate is a `422`, not a crash. |
| `project_key` | ✅ | Scopes retrieval to this KB project. |
| `allowed_origins` | – | Array of exact origins (`https://host[:port]`). Empty = browser mode unusable (no origin will match). |
| `rate_limit` | – | Requests/min per key+IP. Default `60`. |
| `skill` | – | `^[a-z0-9][a-z0-9-]*@[0-9]+$`, e.g. `askmydocs-assistant@1`. Default `askmydocs-assistant@1`. |
| `host_tools_enabled` | – | Boolean, default `false`. Operator switch for HTP (see §9). |
| `theme` | – | Theme object; validated + sanitised server-side (colours `#rgb`/`#rrggbb`/`#rrggbbaa`, allowlisted font stacks, `https://` logo URLs). |

---

## 4. Embed snippet & configuration

The loader (`frontend/src/widget/loader.ts`) reads config from **`window.AskMyDocsWidget`**
or from `data-*` attributes on the `<script>` tag (data-attrs win).

**Global config object** (`window.AskMyDocsWidget`):

| Key | Type | Default | Purpose |
|---|---|---|---|
| `key` | string | — (**required**) | Public key `pk_…`. |
| `apiBase` | string | `''` (same origin) | AskMyDocs instance base URL. |
| `skill` | string | key's skill | Override the skill id. |
| `mode` | `'helper'` \| `'inline'` | `'helper'` | Floating launcher vs mounted block. |
| `mount` | string | — | CSS selector of the container for `inline` mode. |
| `title` | string | — | Panel title. |
| `launcherLabel` | string | — | Launcher button label. |
| `autoOpen` | boolean | `false` | Open the panel on load (helper mode). |
| `theme` | object | key's theme | Inline theme overrides. |
| `hostManifestUrl` | string | — | HTP manifest URL (see §9). |
| `hostExecUrl` | string | — | HTP execution URL (see §9). |
| `csrfToken` | string | `<meta name="csrf-token">` | CSRF token for same-origin host calls. |

**Equivalent `data-*` attributes** on the script tag: `data-public-key`,
`data-api-base`, `data-skill`, `data-host-manifest-url`, `data-host-exec-url`,
`data-csrf-token`.

**Modes**
- `helper` — a fixed floating launcher on `<body>` that slides out a chat panel.
- `inline` — the chat renders as a full block inside `mount` (fills its width/height).

---

## 5. Authentication modes

The `widget.key` middleware (`ResolveWidgetKey`) accepts three credential shapes.
In every case **tenant + project are taken from the key server-side** and written
to `TenantContext` — the client cannot choose a tenant (R30).

### Mode A — Browser (public key)
For JS running in a customer's browser. The `pk_` is public (visible in page source).

```
X-Widget-Key: pk_live_…
Origin: https://app.example.com      # must exactly match an allowed origin
```
- Origin is validated against the key's `allowed_origins` allowlist.
- Rate-limited per key + IP (a `429` is returned **before** any token is consumed).

### Mode B — Proxy (server-to-server)
For your backend calling AskMyDocs. High-trust; origin check is skipped.

```
X-Widget-Key: pk_live_…
Authorization: Bearer sk_live_…      # secret, verified via Hash::check
```

### Session token (optional, browser hardening)
Mint a **single-use, origin-bound** token and use it as a bearer for the session.
Consumption is atomic (R21): two concurrent requests can never both consume it.

```
POST /api/widget/session-token       # with Mode A or B credentials → { token: "wt_…", expires_at }
Authorization: Bearer wt_…           # on subsequent calls (origin must match the mint origin)
```
- TTL `WIDGET_SESSION_TOKEN_TTL` minutes (default 30).
- Stored hashed at rest (never plaintext).
- An expired/consumed token is rejected with `401`.

**Errors:** `401` (key missing/invalid), `403` (inactive key / origin not allowed),
`429` (rate limit).

---

## 6. HTTP API reference

Base path `/api/widget/*`. No Sanctum — gated by `widget.key` + `throttle:120,1`,
with CORS handled by `HandleWidgetCors` (reflects an allowed `Origin`, answers
`OPTIONS` preflight).

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/widget/setup` | Fetch the resolved skill manifest (tools, policies, theme) for the key. |
| `POST` | `/api/widget/session-token` | Mint a single-use, origin-bound session token. |
| `POST` | `/api/widget/sessions/start` | Open a session and run the first turn. |
| `POST` | `/api/widget/sessions/{session}/step` | Run the next turn (snapshot + optional `tool_result` + optional `message`). |
| `POST` | `/api/widget/sessions/{session}/exec-tool` | Execute a backend (BE) tool for the session. |
| `POST` | `/api/widget/sessions/{session}/cancel` | Abort the session. |
| `GET` | `/api/widget/sessions/{session}/replay` | Fetch the session's steps (PII-masked). |

- `{session}` is the opaque `public_session_id` (UUID); a session belonging to a
  different key/tenant resolves to `404` (anti-IDOR).
- **`start` / `step` request body**: `{ snapshot: {…}, message?: string,
  tool_result?: {…} }`. `snapshot` is required and capped (see §12).
- **Turn response** (`type` discriminates): `message` (grounded answer + `citations`
  + `confidence`), `tool_call` (a tool for the FE/host/BE to run, with an
  `execution` of `fe` | `host` | `be`), or `blocked`.
- **Session statuses**: `active`, `waiting_user`, `waiting_tool`, `completed`,
  `blocked`, `aborted`, `error`. Only `active` / `waiting_user` / `waiting_tool`
  accept a new `step` (a closed/blocked/errored session returns `409`).

---

## 7. Skills (manifests, tools, policies)

A **skill** is a JSON manifest that declares which tools the agent may use, how to
auto-annotate the page, and the run policies. Manifests live under
`config('widget.skills_path')` (default `resources/widget/skills/{id}/manifest.json`).
A skill id is `^[a-z0-9][a-z0-9-]*@[0-9]+$` (e.g. `askmydocs-assistant@1`).

**Manifest keys**

| Key | Purpose |
|---|---|
| `name` / `version` / `description` | Metadata. |
| `tools_enabled` | Allowlist of FE/BE tool names the agent may call. |
| `ai_tools` | Backend AI tools available via `/exec-tool` (e.g. `search_knowledge_base`). |
| `auto_annotation_rules` | `{selector, attrs}` rules that stamp `data-kitt-*` onto the page automatically (so a host page works even without manual annotation). |
| `default_policies` | `max_steps`, `max_consecutive_errors`, confirmation requirements, `navigate_scope` (e.g. `same-origin`). |
| `host_tools_enabled` | Skill-side gate for HTP (see §9). |
| `host_tools_allowlist` | Optional name-prefix allowlist for host tools. |
| `default_locale` / `default_mode` | Defaults for the session. |

**Built-in skills**

| Skill id | What it enables |
|---|---|
| `askmydocs-assistant@1` | RAG-only assistant: the DOM tool set + `search_knowledge_base`. `host_tools_enabled: false`. |
| `gescat-assistant@1` | Same DOM tools **plus** host tools (`host_tools_enabled: true`, with a `host_tools_allowlist`). Example of a host-integrated deployment. |

**Tool sides**
- **FE tools** (run in the page by the widget executor): `click`, `type`, `select`,
  `combobox_search`, `combobox_set`, `toggle`, `radio`, `set_locale`, `goto_step`,
  `scroll_to`, `submit_form`, `wait_for`, `read_page`, `navigate_to`, `move_cursor`,
  `tour_step`, `show_recap`, plus the control verbs `ask_user`, `report_done`,
  `report_blocked`.
- **BE tools** (run server-side via `/exec-tool`): `search_knowledge_base`
  (`WidgetAiToolRegistry`; you can register more).
- **Host tools** (run by the host app): declared at runtime via HTP (see §9).

Whether the agent is given any tools at all also depends on the active AI provider:
only providers in `config('widget.tool_calling_providers')` (default
`openai,openrouter,fake`, env `WIDGET_TOOL_CALLING_PROVIDERS`) receive the tool
schemas. With a non-tool-calling provider the widget degrades to plain grounded chat.

---

## 8. Annotating the host page (`data-kitt-*`)

The widget captures a structured snapshot of the page. Annotations make that
snapshot precise and stable (verb-based, not coordinate-based). A skill's
`auto_annotation_rules` can apply these automatically; you can also add them by
hand. See [`example-annotated-page.html`](./example-annotated-page.html) and the
[`agent-annotation-prompt.md`](./agent-annotation-prompt.md) for a full worked page.

| Attribute | Put it on | Purpose |
|---|---|---|
| `data-kitt-region="id"` | a `<section>` | A page region / wizard step. Mark the active one with `data-kitt-active="true"`. |
| `data-kitt-field="name"` | the field wrapper or the input | A form field. Add `data-kitt-required`, `data-kitt-sensitive`, `data-kitt-help`. |
| `data-kitt-input` | the `<input>`/`<select>`/`<textarea>` | Marks the actual input when the wrapper isn't the input. |
| `data-kitt-action="verb"` | a `<button>`/`<a>` | A stable action verb (`submit`, `next`, `delete`, …). Add `data-kitt-reason-disabled`, `data-kitt-help`. |
| `data-kitt-message="level"` | a banner `<div>` | An error/warning/info message (`level ∈ error\|warning\|info`). |
| `data-kitt-locale="lang"` | a language `<button>` | A locale switch; mark current with `data-kitt-active="true"`. |
| `data-kitt-skip` | any element | Hide this subtree from the snapshot (promos, third-party widgets, debug). |

**Sensitive fields never leave the page.** A field marked `data-kitt-sensitive`
(or any `type=password`/`type=hidden`) is sent with `value: null`; the server
re-enforces this (defence in depth) so secrets never reach the LLM or the step log.

---

## 9. Host-Tools Protocol (HTP)

HTP lets the **host app** expose its own tools to the agent (e.g. "create order",
"set consumption rate"). It is **double-gated** and **off by default**:

1. **Key gate** — `widget_keys.host_tools_enabled = true` (admin toggle).
2. **Skill gate** — the skill manifest's `host_tools_enabled: true` (+ optional
   `host_tools_allowlist` of name prefixes).

Both must be true, or the host tools are dropped before reaching the LLM.

**Wiring**
1. In the embed config, set `hostManifestUrl` and `hostExecUrl` (+ `csrfToken` for
   same-origin POSTs).
2. The widget fetches the manifest (`credentials: 'same-origin'`) and merges its
   `tools[]` into the snapshot as `host_tools[]`.
3. Each host tool is `{ name, description, parameters, execution: "host", returns? }`.
   `name` must match `^[a-zA-Z0-9_-]+$`; malformed entries are discarded server-side.
4. When the LLM calls a host tool, the widget POSTs to `hostExecUrl`:
   `{ tool, args, session_ref }` with the CSRF token + same-origin cookies.
5. The host responds `{ ok: true, artifact: {…} }` or
   `{ ok: false, error, message }`. The artifact is rendered in the panel.

The snapshot caps `host_tools` at 64 entries; tool definitions are validated +
text-sanitised but the `parameters` schema is passed through untouched.

---

## 10. Admin SPA surface

Under `/app/admin/widget` (React, `frontend/src/features/admin/widget/*`):

| Screen | Role | What it does |
|---|---|---|
| Keys list + create/edit/delete | super-admin (`manageWidgetKeys`) | CRUD, rotate, revoke. |
| Allowed origins | super-admin | Manage `allowed_origins`. |
| Appearance / theme | super-admin | Theme designer (validated + sanitised). |
| Host-tools toggle | super-admin | Per-key `host_tools_enabled`. |
| Embed code | super-admin | Copy-to-clipboard ready snippet. |
| Sessions browser + detail | admin + super-admin (`viewWidgetSessions`) | Read-only session list + step replay (PII-masked). |

---

## 11. Database tables

| Table | Key columns |
|---|---|
| `widget_keys` | `tenant_id`, `project_key`, `public_key` (unique), `secret_hash` (nullable, hashed), `allowed_origins` (json), `rate_limit`, `skill`, `host_tools_enabled`, `theme_config` (json), `is_active`, `label`, `last_used_at`. Unique `(tenant_id, project_key, label)`. |
| `widget_sessions` | `tenant_id`, `widget_key_id` (FK cascade), `project_key`, `public_session_id` (uuid, unique), `status`, `skill`, `page_url`, `origin`, `summary`, `blocked_reason`, `meta` (json). Indexed `(tenant_id, created_at)`. |
| `widget_session_steps` | `tenant_id`, `widget_session_id` (FK cascade), `step_index`, `kind` (`snapshot`/`tool_call`/`tool_result`/`user_message`/`bot_message`), `tool`, `args_json` / `diagnostic_json` / `snapshot_in_json` (PII-masked), `tokens_in` / `tokens_out` / `latency_ms`. |
| `widget_session_tokens` | `tenant_id`, `token` (unique, **sha-256 hash** of `wt_…`), `widget_key_id` (FK cascade), `widget_session_id` (FK cascade, nullable), `origin`, `expires_at`, `consumed_at`. |

Sessions + steps are pruned by `widget:prune-sessions` (daily) after
`WIDGET_SESSION_RETENTION_DAYS`; expired tokens are pruned in the same command.

---

## 12. Configuration & env vars

`config/widget.php`, with `.env` knobs:

| Env var | Default | Purpose |
|---|---|---|
| `WIDGET_SESSION_TOKEN_TTL` | `30` | Session-token TTL (minutes). |
| `WIDGET_SESSION_RATE_LIMIT` | `30` | Per-session rate limit (requests/min). |
| `WIDGET_MAX_MESSAGE_LENGTH` | `10000` | Max user message length (else `422`). |
| `WIDGET_MAX_STEPS_PER_SESSION` | `100` | Max ReAct steps per session (else the session is blocked). |
| `WIDGET_SNAPSHOT_MAX_BYTES` | `262144` | Max serialized snapshot size (else `422`). `0` disables. |
| `WIDGET_SESSION_RETENTION_DAYS` | `90` | Hard-delete sessions older than this. `0` disables. |
| `WIDGET_DEMO_ENABLED` | `false` | Enable `/widget-demo` in local. |
| `WIDGET_TOOL_CALLING_PROVIDERS` | `openai,openrouter,fake` | CSV of AI providers that receive tool schemas. |

`skills_path` defaults to `resource_path('widget/skills')`.

---

## 13. Local demo page

`GET /widget-demo` serves a self-contained page (a small annotated profile form +
the widget) for local development. It is gated to **testing**, or **local** with
`WIDGET_DEMO_ENABLED=true` — so a stray `APP_ENV=local` box never exposes a working
credential to anonymous visitors. It auto-creates/reuses a permissive demo key
(`pk_demo_local`, project `docs-v3`, localhost origins). Add `?mode=inline` for the
inline layout.

---

## 14. Security model

- **Tenant/project are server-side only** (from the key) — the browser never names
  a tenant (R30). Cross-key/cross-tenant session access is `404` (anti-IDOR).
- **Origin allowlisting** in browser mode; **secret** (`sk_`) for server-to-server.
- **Single-use, origin-bound session tokens**, consumed atomically under a lock
  (R21); stored hashed at rest; rate-limit is checked **before** the token is burned.
- **CORS** reflects only allowed origins; `Access-Control-Allow-Credentials` is not
  emitted for the widget API.
- **Snapshot hardening**: count caps + byte cap; server re-sanitises every text
  field (strips markup/fences/zero-width) and force-nulls sensitive field values.
- **Navigation guard**: `navigate_to`/host navigation rejects `javascript:`,
  `data:`, `vbscript:`, protocol-relative `//host`, and backslash/`%5c` tricks,
  mirrored on both the server validator and the FE executor.
- **PII masking** on every persisted step (`args_json` / `diagnostic_json`) and on
  replay; Italian VAT masking is checksum-validated so non-PII codes stay readable.
- **Bounded agency**: per-session step cap + consecutive-error cap → the session
  blocks instead of looping; per-key + per-session rate limits.

---

## 15. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `403` on every call | `Origin` not in the key's `allowed_origins`, or key inactive | Add the exact `https://host[:port]` origin; check `is_active`. |
| `401` `session_token_invalid` | Token expired/consumed, or origin mismatch | Mint a fresh token; ensure the request `Origin` matches the mint origin. |
| `429` | Per-key or per-session rate limit hit | Raise the key `rate_limit` / `WIDGET_SESSION_RATE_LIMIT`, or back off (honour `Retry-After`). |
| `422` `snapshot_too_large` | Snapshot over the byte/count cap | Use `data-kitt-skip` on noisy subtrees; raise `WIDGET_SNAPSHOT_MAX_BYTES`. |
| Agent never takes actions | AI provider isn't tool-calling | Use an `openai`/`openrouter` provider, or add yours to `WIDGET_TOOL_CALLING_PROVIDERS`. |
| Host tools ignored | One of the two gates is off | Set the key's `host_tools_enabled` **and** the skill's `host_tools_enabled`; check the `host_tools_allowlist` prefix. |
| Session won't continue (`409`) | Session is `completed`/`blocked`/`aborted`/`error` | Start a new session. |

---

*Source of truth for everything above: `config/widget.php`, `routes/api.php`
(`/api/widget/*` + `/api/admin/widget-*`), `app/Http/Middleware/ResolveWidgetKey.php`
+ `HandleWidgetCors.php`, `app/Services/Widget/*`, `resources/widget/skills/*`,
`frontend/src/widget/*`, and the `*widget*` migrations. Keep this doc in lock-step
with those files (R9).*
