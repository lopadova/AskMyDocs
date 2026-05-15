# PLAN — `padosoft/askmydocs-mcp-pack-admin` — UX / UI Design Brief

**Document type**: UX / UI design plan (designer + senior FE engineer brief)
**Audience**: senior product designer + senior React engineer who have each shipped 2–3 admin SaaS dashboards
**Status**: design proposal — pending Lorenzo's palette + motion-intensity confirmations (see §14)
**Backend contract**: `padosoft/askmydocs-mcp-pack` **v1.4**, `/api/admin/mcp-pack/*`
**Sibling pattern references** (grep these repos for established conventions, do not re-invent):
  - `padosoft/laravel-flow-admin`
  - `padosoft/laravel-pii-redactor-admin`
  - `padosoft/eval-harness-ui`
  - `padosoft/askmydocs-pro` (admin shell baseline)

---

## 1. Package metadata

### 1.1 `composer.json`

Mirror the laravel-flow-admin shape — no new conventions. Concrete starting point:

```json
{
    "name": "padosoft/askmydocs-mcp-pack-admin",
    "description": "React SPA admin panel for padosoft/askmydocs-mcp-pack — monitor MCP servers, tools, resources, prompts, audit log and circuit-breaker state.",
    "type": "library",
    "license": "MIT",
    "keywords": ["laravel", "mcp", "model-context-protocol", "admin", "react", "tailwind", "tanstack", "askmydocs"],
    "require": {
        "php": "^8.3|^8.4|^8.5",
        "illuminate/support": "^11.0|^12.0|^13.0",
        "padosoft/askmydocs-mcp-pack": "^1.4"
    },
    "require-dev": {
        "orchestra/testbench": "^9.0|^10.0|^11.0",
        "phpunit/phpunit": "^11.0|^12.0",
        "larastan/larastan": "^3.0"
    },
    "autoload": {
        "psr-4": {
            "Padosoft\\AskMyDocsMcpPackAdmin\\": "src/"
        }
    },
    "extra": {
        "laravel": {
            "providers": [
                "Padosoft\\AskMyDocsMcpPackAdmin\\McpPackAdminServiceProvider"
            ]
        },
        "askmydocs-admin": {
            "mount": {
                "path-prefix": "admin/mcp",
                "manifest": "dist/manifest.json",
                "entry": "frontend/src/main.tsx",
                "blade-view": "askmydocs-mcp-pack-admin::shell"
            }
        }
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

### 1.2 Version pinning posture (matches flow-admin)

| Phase | Tag | Backend pin | Public stability |
|-------|-----|-------------|------------------|
| Skeleton | `v0.1.0-alpha` | `^1.4@dev` | Pre-release, breaking changes allowed |
| MVP | `v0.5.0-beta` | `^1.4` | Pre-release, API surface stabilising |
| GA | `v1.0.0` | `^1.4` | Semver from this point |
| Patch | `v1.0.x` | `^1.4` | Bugfix only |
| Minor | `v1.x.0` | `^1.4` or `^1.5` if backend ships new endpoints | Backwards compatible |

**R37 mirror**: each major (`v1.0`, `v2.0`) integrates on `feature/vX.0` then merges to `main` ONCE. No direct-to-main feature commits.

### 1.3 NPM-side `package.json` (inside `frontend/`)

```json
{
    "name": "@padosoft/askmydocs-mcp-pack-admin-ui",
    "private": true,
    "type": "module",
    "scripts": {
        "dev": "vite",
        "build": "tsc -b && vite build",
        "test": "vitest run",
        "test:watch": "vitest",
        "test:e2e": "playwright test",
        "lint": "eslint src",
        "typecheck": "tsc --noEmit"
    },
    "dependencies": {
        "@tanstack/react-router": "^1.50.0",
        "@tanstack/react-query": "^5.55.0",
        "@tanstack/react-virtual": "^3.10.0",
        "react": "^19.0.0",
        "react-dom": "^19.0.0",
        "react-router-dom": "^6.26.0",
        "zustand": "^5.0.0",
        "lucide-react": "^0.460.0",
        "framer-motion": "^11.11.0",
        "clsx": "^2.1.0",
        "tailwind-merge": "^2.5.0",
        "class-variance-authority": "^0.7.0",
        "@radix-ui/react-dialog": "^1.1.0",
        "@radix-ui/react-dropdown-menu": "^2.1.0",
        "@radix-ui/react-popover": "^1.1.0",
        "@radix-ui/react-tooltip": "^1.1.0",
        "@radix-ui/react-tabs": "^1.1.0",
        "@radix-ui/react-toast": "^1.2.0",
        "@radix-ui/react-select": "^2.1.0",
        "@radix-ui/react-checkbox": "^1.1.0",
        "@radix-ui/react-switch": "^1.1.0",
        "cmdk": "^1.0.0",
        "react-hook-form": "^7.53.0",
        "zod": "^3.23.0",
        "@hookform/resolvers": "^3.9.0",
        "react-syntax-highlighter": "^15.5.0",
        "react-markdown": "^9.0.0",
        "date-fns": "^3.6.0",
        "recharts": "^2.13.0"
    },
    "devDependencies": {
        "@playwright/test": "^1.48.0",
        "@tailwindcss/vite": "^4.0.0",
        "@testing-library/react": "^16.0.0",
        "@testing-library/jest-dom": "^6.6.0",
        "@testing-library/user-event": "^14.5.0",
        "@types/react": "^19.0.0",
        "@types/react-dom": "^19.0.0",
        "@vitejs/plugin-react": "^4.3.0",
        "eslint": "^9.13.0",
        "eslint-plugin-react-hooks": "^5.0.0",
        "jsdom": "^25.0.0",
        "tailwindcss": "^4.0.0",
        "typescript": "^5.6.0",
        "vite": "^5.4.0",
        "vitest": "^2.1.0"
    }
}
```

---

## 2. Visual identity

### 2.1 Inspiration & tone

- **Linear** — density, keyboard-first ergonomics, restrained motion.
- **Vercel Dashboard** — neutrals + bold accent for the action axis.
- **Stripe Dashboard** — clarity of tabular density + status semantics + dot-indicators.

Restraint over flair. The product is observed for hours by SREs; chrome must disappear.

### 2.2 Recommended palette — **Option A: "Indigo Sentinel"** (default proposal)

Cool indigo accent against warm-tinted neutrals. Reads enterprise. Works in long sessions.

#### Light theme

| Token | Hex | Use |
|-------|-----|-----|
| `--bg-base` | `#FAFAF9` | Page background |
| `--bg-surface` | `#FFFFFF` | Cards / panels |
| `--bg-surface-hover` | `#F5F5F4` | Row hover |
| `--bg-surface-pressed` | `#E7E5E4` | Row active / pressed |
| `--bg-inset` | `#F5F5F4` | Inset code blocks, inputs |
| `--border-subtle` | `#E7E5E4` | Default dividers |
| `--border-default` | `#D6D3D1` | Input borders |
| `--border-strong` | `#A8A29E` | Focus rings (secondary) |
| `--fg-primary` | `#1C1917` | Body text |
| `--fg-secondary` | `#57534E` | Labels |
| `--fg-tertiary` | `#78716C` | Muted, timestamps |
| `--fg-disabled` | `#A8A29E` | Disabled |
| `--accent-default` | `#4F46E5` | Indigo-600 — primary action |
| `--accent-hover` | `#4338CA` | Indigo-700 |
| `--accent-pressed` | `#3730A3` | Indigo-800 |
| `--accent-fg` | `#FFFFFF` | Text on accent |
| `--accent-subtle` | `#EEF2FF` | Accent tinted bg |
| `--accent-ring` | `#A5B4FC` | Focus ring |
| `--success-default` | `#15803D` | Healthy / closed CB |
| `--success-subtle` | `#DCFCE7` | Bg |
| `--warning-default` | `#B45309` | Half-open CB / degraded |
| `--warning-subtle` | `#FEF3C7` | Bg |
| `--danger-default` | `#B91C1C` | Open CB / failure |
| `--danger-subtle` | `#FEE2E2` | Bg |
| `--info-default` | `#0369A1` | Info banners |
| `--info-subtle` | `#E0F2FE` | Bg |

#### Dark theme

| Token | Hex |
|-------|-----|
| `--bg-base` | `#0C0A09` |
| `--bg-surface` | `#1C1917` |
| `--bg-surface-hover` | `#292524` |
| `--bg-surface-pressed` | `#44403C` |
| `--bg-inset` | `#0C0A09` |
| `--border-subtle` | `#292524` |
| `--border-default` | `#44403C` |
| `--border-strong` | `#78716C` |
| `--fg-primary` | `#F5F5F4` |
| `--fg-secondary` | `#D6D3D1` |
| `--fg-tertiary` | `#A8A29E` |
| `--fg-disabled` | `#57534E` |
| `--accent-default` | `#818CF8` |
| `--accent-hover` | `#A5B4FC` |
| `--accent-pressed` | `#C7D2FE` |
| `--accent-fg` | `#0C0A09` |
| `--accent-subtle` | `#312E81` |
| `--accent-ring` | `#4F46E5` |
| `--success-default` | `#4ADE80` |
| `--warning-default` | `#FBBF24` |
| `--danger-default` | `#F87171` |
| `--info-default` | `#38BDF8` |

### 2.3 Alternate palettes (Lorenzo picks)

#### Option B: "Stripe Plum" — purple/pink accent, warmer neutrals

| Token | Light | Dark |
|-------|-------|------|
| `--accent-default` | `#7C3AED` (violet-600) | `#A78BFA` |
| `--accent-hover` | `#6D28D9` | `#C4B5FD` |
| `--accent-subtle` | `#F5F3FF` | `#4C1D95` |
| Neutrals | `stone-*` (same as Option A) | `stone-*` (same as Option A) |
| Personality | More marketing-warm, slight feminine read | — |

#### Option C: "Vercel Sentinel" — neutral + red accent

| Token | Light | Dark |
|-------|-------|------|
| `--accent-default` | `#171717` (near-black) | `#FAFAFA` (near-white) |
| `--accent-hover` | `#404040` | `#E5E5E5` |
| `--accent-secondary-default` | `#DC2626` (red-600) | `#F87171` | (CTA + status)
| Neutrals | `neutral-*` (pure grays) | `neutral-*` |
| Personality | Most monochrome / brutalist | — |

**Recommendation: Option A** — distinct enough from AskMyDocs main shell (which uses sky-500 family) to read as a sibling-not-clone, sober enough for SREs, supports clear status semantics without colliding with accent.

### 2.4 Typography stack

```css
--font-sans: "Inter", "InterVariable", ui-sans-serif, system-ui, -apple-system,
             BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
--font-mono: "JetBrains Mono", "JetBrainsMono Nerd Font", ui-monospace,
             SFMono-Regular, Menlo, Consolas, monospace;
--font-display: "Inter Display", "Inter", sans-serif;  /* optional, fall back fine */
```

Self-host Inter via `@fontsource/inter` so the package boots without CDN dependency (consumers in air-gapped envs).

#### Type scale

| Token | Size / Line | Weight | Use |
|-------|-------------|--------|-----|
| `text-2xs` | 11px / 14px | 500 | Pill labels, table meta |
| `text-xs` | 12px / 16px | 500 | Table cells, captions |
| `text-sm` | 13px / 18px | 400 | Body default (compact density) |
| `text-base` | 14px / 20px | 400 | Body default (comfortable density) |
| `text-md` | 15px / 22px | 500 | Form labels, light headings |
| `text-lg` | 17px / 24px | 600 | Section headings |
| `text-xl` | 20px / 28px | 600 | Page titles |
| `text-2xl` | 24px / 32px | 600 | Hero KPI numbers |
| `text-3xl` | 32px / 40px | 700 | Dashboard hero |

Numbers in tables and KPIs use `font-variant-numeric: tabular-nums`.

### 2.5 Spacing tokens (Tailwind v4 native scale, no override needed)

| Token | Value | Use |
|-------|-------|-----|
| `space-0` | 0 | — |
| `space-1` | 4px | Tight padding, gap inside chip |
| `space-2` | 8px | Compact density gap |
| `space-3` | 12px | Default control padding |
| `space-4` | 16px | Comfortable density gap |
| `space-5` | 20px | Card padding inner |
| `space-6` | 24px | Section gap |
| `space-8` | 32px | Page section break |
| `space-10` | 40px | — |
| `space-12` | 48px | Major page region |
| `space-16` | 64px | Hero spacing |

### 2.6 Radius

```css
--radius-xs: 2px;   /* pill insets */
--radius-sm: 4px;   /* default for inputs / chips */
--radius-md: 6px;   /* buttons, cards-tight */
--radius-lg: 8px;   /* cards, modals */
--radius-xl: 12px;  /* hero cards */
--radius-2xl: 16px; /* sheet panels */
--radius-full: 9999px;
```

### 2.7 Elevation / shadow

Five steps. Stripe-like restraint — shadows only signal **layer**, not decoration.

```css
--shadow-0: none;
--shadow-1: 0 1px 2px 0 rgb(0 0 0 / 0.04);                                   /* card */
--shadow-2: 0 1px 3px 0 rgb(0 0 0 / 0.08), 0 1px 2px 0 rgb(0 0 0 / 0.04);    /* dropdown */
--shadow-3: 0 4px 12px 0 rgb(0 0 0 / 0.10), 0 2px 4px 0 rgb(0 0 0 / 0.06);   /* popover */
--shadow-4: 0 12px 32px 0 rgb(0 0 0 / 0.14), 0 4px 8px 0 rgb(0 0 0 / 0.08);  /* modal */
--shadow-focus: 0 0 0 3px var(--accent-ring);                                /* focus ring */
```

Dark theme uses the same alpha values; the underlying tint reads correctly.

### 2.8 Motion / easing

Restrained. Long sessions punish overuse.

| Token | Curve | Duration | Use |
|-------|-------|----------|-----|
| `--ease-out-soft` | `cubic-bezier(0.22, 1, 0.36, 1)` | 180ms | Hover, focus, micro-interaction |
| `--ease-out-default` | `cubic-bezier(0.16, 1, 0.3, 1)` | 220ms | Panel slide-in, drawer open |
| `--ease-in-out` | `cubic-bezier(0.65, 0, 0.35, 1)` | 240ms | Tab switch, route transition |
| `--ease-bounce-subtle` | `cubic-bezier(0.34, 1.56, 0.64, 1)` | 320ms | CB state-transition success **only** |
| `--duration-instant` | 80ms | — | Toggles, checkbox |
| `--duration-fast` | 180ms | — | Hover |
| `--duration-default` | 220ms | — | Panels |
| `--duration-slow` | 320ms | — | Modal enter / exit |

**Rule**: any motion >320ms must justify itself in PR review. Honour `prefers-reduced-motion: reduce` everywhere — collapse all durations to ≤40ms when set.

### 2.9 Iconography

`lucide-react` exclusively. Default size 16px in tables / inputs, 18px in nav, 20px in section headers, 24px in empty-state illustrations. Stroke width 1.75 (Lucide default — do not override).

Canonical icons:

| Concept | Icon |
|---------|------|
| Server | `Server` |
| Tool | `Wrench` |
| Resource | `FileBox` |
| Prompt | `Sparkles` (alternative: `MessageSquareQuote`) |
| Audit | `ScrollText` |
| Circuit breaker open | `ZapOff` |
| Circuit breaker half-open | `Zap` (warning tint) |
| Circuit breaker closed | `Zap` (success tint) |
| Handshake | `Handshake` |
| Health OK | `CircleCheck` |
| Health warn | `TriangleAlert` |
| Health error | `CircleX` |
| Search | `Search` |
| Filter | `ListFilter` |
| Settings | `Settings` |
| Command palette | `Command` |
| Copy | `Copy` |
| External link | `ExternalLink` |
| Refresh / replay | `RefreshCw` |
| Dark mode | `Moon` / `Sun` |

---

## 3. Information architecture

### 3.1 Sitemap

```
/admin/mcp/
├── /                            → redirect → /dashboard
├── /dashboard                   → Overview Dashboard
├── /servers
│   ├── /                        → Servers list
│   ├── /new                     → Create wizard (multi-step)
│   ├── /:serverId               → Server detail (tabs: overview, tools, handshakes, audit, config)
│   └── /:serverId/edit          → Edit server (transport + flags + auth)
├── /tools
│   ├── /                        → Tools explorer (all servers, grouped)
│   └── /:serverId/:toolName     → Tool detail (schema + try-it playground)
├── /resources
│   ├── /                        → Resources browser (URI tree)
│   └── /:serverId/*             → Resource preview pane
├── /prompts
│   ├── /                        → Prompts library
│   └── /:serverId/:promptName   → Prompt detail + render preview
├── /audit
│   ├── /                        → Audit log (virtualised, filterable)
│   └── /:auditId                → Audit row drill-down (timeline + JSON-RPC envelopes)
├── /circuit-breakers            → Circuit-breaker dashboard grid
├── /playground                  → OpenAPI / Swagger explorer
├── /settings
│   ├── /                        → Settings index
│   ├── /tenants                 → Per-tenant config overrides (RBAC: tenant-admin)
│   ├── /preferences             → Theme + density + shortcuts (per-user)
│   └── /api-keys                → Sanctum tokens / scopes (RBAC: admin)
└── /help                        → Keyboard shortcuts + glossary + getting started
```

### 3.2 Navigation chrome

**Top bar (60px tall, sticky)**:
- `[McpPack logo] + product name "MCP Pack"` (links to `/dashboard`)
- Breadcrumbs (collapse to ellipsis after 3 levels — `Servers / openai-mcp / Tools / get_weather`)
- Centre: command palette trigger (`Search... ⌘K`) — clickable + shortcut hint
- Right: env badge (`production` / `staging`), tenant switcher (if multi-tenant), user menu, theme toggle

**Side nav (collapsible, default 240px → 56px collapsed)**:

Recommend **vertical primary nav** (Linear-style) because the IA has 9 top-level screens and a horizontal bar gets crowded. Reserve horizontal tabs for **secondary nav INSIDE** screens (e.g. server detail).

```
[Logo]               McpPack v1.4

[≡]
  Dashboard          ⌘1
  Servers            ⌘2
  Tools              ⌘3
  Resources          ⌘4
  Prompts            ⌘5
  Audit log          ⌘6
  Circuit breakers   ⌘7

  ─────────

  Playground
  Settings
  Help

[Tenant: acme-corp ▾]                   ← bottom-pinned
[User: lorenzo@padosoft.com ▾]
```

Collapsed state: only icons + tooltip on hover.

### 3.3 Breadcrumbs

Always present below the top bar except on `/dashboard`. Last segment unlinked (current page), all prior segments linked.

Example: `Servers / openai-mcp / Tools / get_weather`

### 3.4 Empty / 404 / 403

- `/admin/mcp/<unknown>` → 404 illustration + "Go home" CTA + "Search ⌘K" CTA
- Permission denied → 403 card explaining what permission is missing + link to the user's profile page on the host

---

## 4. Screen-by-screen brief

### 4.1 Overview Dashboard (`/dashboard`)

**Purpose**: 5-second status read for SREs. Loud signal first, drill-down a click away.

**Layout (12-col grid, default density)**:

```
┌─────────────────────────────────────────────────────────────────────────┐
│ Page header: "Overview"   [Last 1h ▾] [Refresh ↻] [⏸ Pause feed]        │
├─────────────────────────────────────────────────────────────────────────┤
│ ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐                     │
│ │ Servers  │ │ Calls/min│ │ p50 lat. │ │ CB open  │   ← KPI tiles      │
│ │   12 / 14│ │   847    │ │  142ms   │ │    2     │                     │
│ │ ▲ +2 ↘   │ │ ▲ +14%   │ │ ▲ +8ms   │ │ ⚠ +1     │                     │
│ │ sparkline│ │ sparkline│ │ sparkline│ │ sparkline│                     │
│ └──────────┘ └──────────┘ └──────────┘ └──────────┘                     │
├─────────────────────────────────────────────────────────────────────────┤
│ ┌────────────────────────────────┐ ┌────────────────────────────────┐   │
│ │ Live tool-invocation feed (SSE)│ │ Per-server health strip        │   │
│ │ ● 12:34:01 openai-mcp / search │ │ ─ openai-mcp ▓▓▓▓▓▓▓▓▓▓▓ 100%  │   │
│ │   ✓ 142ms                       │ │ ─ slack-mcp  ▓▓▓▓▓▓▓░░░░  62% │   │
│ │ ● 12:33:58 slack-mcp / post     │ │ ─ jira-mcp   ▓▓▓▓▓▓▓▓▓▓▓ 100% │   │
│ │   ✗ 5012ms (CB tripped)         │ │   ...                         │   │
│ │ ... (virtualised, last 200)     │ │                                │   │
│ └────────────────────────────────┘ └────────────────────────────────┘   │
├─────────────────────────────────────────────────────────────────────────┤
│ ┌────────────────────────────────────────────────────────────────────┐  │
│ │ Top 10 tools by invocation (last 1h) — bar chart                   │  │
│ └────────────────────────────────────────────────────────────────────┘  │
├─────────────────────────────────────────────────────────────────────────┤
│ ┌────────────────────────────────────────────────────────────────────┐  │
│ │ Recent failures (5)  → drill into audit log                        │  │
│ └────────────────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────────┘
```

**KPI tile spec**:
- Width: `col-span-3` (12-col grid → 4 across)
- Padding: `p-5`, border `border-subtle`, radius `--radius-lg`
- Top row: small label + icon (`text-xs text-secondary`)
- Big number: `text-3xl font-semibold tabular-nums`
- Delta vs previous window: arrow + `+14%` (`text-xs`, semantic color)
- 60-data-point sparkline at the bottom (`recharts` line, 32px tall, no axes)
- Whole tile is a `<button>` → routes to the relevant detail page
- `data-testid="dashboard-kpi-{servers|calls|latency|breakers}"`
- `data-state="loading|ready|error"`

**Live feed spec**:
- SSE endpoint: `GET /api/admin/mcp-pack/events` (proposed addition to backend if not present — see §14 open questions)
- Each row: 36px tall, status dot + timestamp + server/tool + duration + status icon
- Click row → open audit drill-down side-sheet
- Pause button stops the stream visually but keeps it open (resume catches up)
- Optimistic ordering: newest at top, max 200 retained in DOM (virtualised)
- Empty state: "No tool invocations in the last 1h" + animated pulse on the dot

**KPI data sources**:
- Servers: `GET /api/admin/mcp-pack/servers?summary=1` returns `{total, active, disabled, error}`
- Calls/min: derived from `/audit?windowSeconds=60&groupBy=minute` — 60-point sparkline pulls 1h
- p50 latency: derived from same audit aggregation
- CB open count: `GET /api/admin/mcp-pack/circuit-breakers?state=open` count

### 4.2 Servers — list (`/servers`)

**Layout**: full-bleed table, sticky filter bar above.

**Filter bar**:
- Search input (debounced 200ms, searches `name + transport + url`)
- Status filter pills: `All | Active | Disabled | Errored | Pending handshake`
- Transport filter: `All | stdio | http | sse`
- "+ New server" button (top-right, primary action)
- Density toggle (compact/comfortable, icon-only)
- Column-picker dropdown (5 default columns + 3 optional)

**Table columns (default)**:

| Col | Header | Width | Content |
|-----|--------|-------|---------|
| 1 | Status dot | 32px | `●` colored by status (green/amber/red/gray) |
| 2 | Name | flex | `<a>` → server detail |
| 3 | Transport | 80px | pill (`stdio` `http` `sse`) |
| 4 | Tools | 64px | count + tooltip listing first 5 |
| 5 | Calls (1h) | 96px | sparkline + total |
| 6 | p95 latency | 96px | numeric + sparkline mini |
| 7 | Last handshake | 120px | relative time + tooltip absolute |
| 8 | Health | 96px | semantic pill (`OK`, `Degraded`, `Down`) |
| 9 | Actions | 40px | `⋮` overflow menu (enable/disable/handshake/edit/delete) |

**Virtualisation**: enabled when row count > 100, using `@tanstack/react-virtual`. Row height 40px compact / 52px comfortable.

**Row hover state**: bg `--bg-surface-hover`, action icons fade in (`opacity-0 → opacity-100`, 120ms).

**Empty state**:
- Centre-aligned card
- Lucide `Server` icon (48px, `--fg-tertiary`)
- "No servers yet"
- "Register your first MCP server to start brokering tool calls."
- Primary button "+ New server"
- Secondary link "Read the setup guide" → opens `/help`

**Bulk-select pattern**:
- Checkbox column auto-appears on first selection
- Sticky bottom action bar (`fixed bottom-4 left-1/2 -translate-x-1/2`) with:
  - "{n} selected"
  - `Enable` / `Disable` / `Run handshake` / `Delete`
  - `Clear selection`
- Translate-y motion on appear (`--duration-fast`)

**Testids**:
- `data-testid="servers-list"` on table
- `data-testid="servers-row-{serverId}"` per row
- `data-testid="servers-row-{serverId}-action-{enable|disable|handshake|edit|delete}"`
- `data-testid="servers-bulk-action-{action}"`
- `data-testid="servers-empty-state"`

### 4.3 Servers — create wizard (`/servers/new`)

**Pattern**: Linear-style centred wizard, 720px max-width card, 3 steps with progress dots at top.

**Step 1: Identity**
- Name (required, kebab-case, validated client-side + server-side)
- Description (optional, 240 char)
- Tenant (dropdown if multi-tenant + user has permission; defaults to current)
- Owner (dropdown of admins)

**Step 2: Transport**
- Transport type select: `stdio | http | sse` (radio cards, icon + description for each)
- Conditional fields per transport:
  - **stdio**: command (input), args (chip input), cwd (input, optional), env (key-value editor — keys + values, supports `${ENV_VAR}` interpolation)
  - **http**: URL (input), headers (key-value editor), auth (none / bearer / basic / oauth — each reveals appropriate sub-fields)
  - **sse**: URL (input), headers (key-value editor), reconnect strategy (backoff + max attempts numeric)

**Step 3: Policies + review**
- Enabled checkbox (default true)
- Timeout (numeric ms, default 30000)
- Retry policy (none / fixed / exponential — show resulting curve as small SVG)
- Circuit-breaker config: failure threshold (default 5), open duration (default 60s), half-open probe count (default 1)
- Review pane shows the resulting POST payload as JSON (collapsible)
- Submit → POST `/servers` → on success go to `/servers/:id` with success toast

**Validation**:
- Inline per-field (Zod + React Hook Form), error appears below field with `data-testid="server-form-{field}-error"`
- Submit blocked if any required is invalid
- On server-side 422: map errors to fields; if unknown key, surface in a banner at top of form

**Cancel**: confirmation modal if any field is dirty.

### 4.4 Servers — detail (`/servers/:serverId`)

**Top of page**:
- Status dot + name (h1) + transport pill + enabled toggle + `⋮` menu (handshake, edit, delete)
- Subline: tenant + owner + created relative time
- Right-aligned: last-handshake time + sparkline (calls 1h)

**Tabs**:
1. **Overview** — config snapshot, last handshake, KPI strip, current capabilities (tools/resources/prompts counts)
2. **Tools** — table of tools from latest handshake (name, description, schema preview, last call, "Try it" button)
3. **Resources** — URI tree (defer detail to /resources, here just a snapshot)
4. **Prompts** — list snapshot (defer detail to /prompts)
5. **Handshakes** — history table (timestamp, duration, success/fail, capabilities returned, click for raw JSON-RPC envelope)
6. **Audit** — pre-filtered audit log scoped to this server
7. **Config** — read-only YAML/JSON dump of effective config (with edit button → /edit)

Tab state persists in URL: `/servers/:id?tab=tools`.

**Testid pattern**: `server-detail-tab-{tabName}`, `server-detail-tools-row-{toolName}-try`, etc.

### 4.5 Tools explorer (`/tools`)

**Layout**: left sidebar (server list, collapsible groups) + main pane (tool catalog flat or grouped).

**Sidebar (260px)**:
- Filter "All servers / Active only / Errored only"
- Tree:
  ```
  ▾ openai-mcp (12)
    search
    summarise
    generate_image
    ...
  ▸ slack-mcp (4)
  ▸ jira-mcp (8)
  ```
- Click a tool → main pane shows detail
- Hover a tool → preview tooltip with description + schema-preview

**Main pane**:
- Header: tool name (`{server}.{tool}`) + invocation count + p50 latency
- Tabs:
  1. **Schema** — formatted JSON Schema viewer (collapsible nodes, type pills, required indicators)
  2. **Try it** — generated form from schema (using `react-hook-form` + `zod-from-jsonschema`), submit button, response pane on the right (status + duration + JSON + audit row link)
  3. **Recent calls** — last 50 invocations, click for drill-down

**Try-it playground**:
- Generated form: every JSON Schema property becomes a field; arrays get add/remove; objects get nested groups; enums become selects
- Top-right of form: "Switch to raw JSON" toggle (Monaco-like editor for power users — use CodeMirror 6 with JSON mode rather than Monaco for bundle size)
- Submit button: "Invoke tool"
- Response pane:
  - Status pill (`200 / 4xx / 5xx`)
  - Latency
  - JSON response (syntax-highlighted, copy button, expand-all)
  - Header `X-Audit-Id` → clickable link to audit drill-down
- Confirmation guard if backend reports the tool is `destructive: true` in its metadata
- `data-testid="tool-playground-invoke"`, `tool-playground-response`

### 4.6 Resources browser (`/resources`)

**Layout**: 3-column resizable

```
┌───────────────┬──────────────────────┬─────────────────────────┐
│ Server tree   │ URI tree per server  │ Content preview         │
│ (180px)       │ (320px, resizable)   │ (flex, resizable)       │
│               │                      │                         │
│ ▾ openai-mcp  │ ▾ mcp://openai/      │ # README.md             │
│   slack-mcp   │   ▾ docs/            │ Lorem ipsum dolor sit...│
│   jira-mcp    │     readme.md ★      │ ...                     │
│               │     guide.md         │                         │
│               │   ▸ schemas/         │ [Tabs: Rendered | Raw   │
│               │   config.json        │  | Hex] [Copy] [Download│
│               │                      │  ↓]                     │
└───────────────┴──────────────────────┴─────────────────────────┘
```

**Preview pane behaviour by MIME type**:
- `text/markdown` → rendered with `react-markdown` + GFM, "Raw" tab swaps to source
- `application/json` → syntax-highlighted, collapsible
- `text/*` → syntax-highlighted by extension
- `image/*` → inline image with checkered background
- `application/pdf` → embedded viewer (PDF.js)
- Binary > 1MB → byte-size + download button + "Preview unavailable for binary content over 1 MB"
- Unknown MIME → hex preview first 4 KB + byte-size

**Resizable splitters**: drag handle on column edge, persists in localStorage. Min/max widths enforced (180px–400px / 240px–500px / flex).

`data-testid="resources-tree-node-{uri-hash}"`, `resources-preview-pane`, `resources-preview-tab-{rendered|raw|hex}`.

### 4.7 Prompts library (`/prompts`)

**Layout**: two-pane.

**Left (320px)**:
- Server filter
- Search input
- List of prompts (name + description + arg-count pill)

**Right (flex)**:
- Selected prompt: name + description
- Arguments form (auto-generated from prompt's argument JSON Schema)
- **Live preview pane** below the form, sticky:
  - As user types in args, the FE calls `POST /prompts/get` (debounced 400ms) and renders the resulting message[] array
  - Each message: role badge (`system | user | assistant`) + content (markdown-rendered)
  - "Copy as JSON" / "Send to Tools playground" buttons
- Empty state if no prompt selected

`data-testid="prompts-list-row-{promptName}"`, `prompt-detail-arg-{argName}`, `prompt-detail-preview`.

### 4.8 Audit log (`/audit`)

**Most-used screen by SREs**. Optimise hard.

**Filter bar (collapsible into a chip strip)**:
- Date range picker (presets: Last 15m / 1h / 24h / 7d / Custom)
- Tenant
- Server
- Tool
- Status (`all | success | client_error | server_error | timeout | breaker_trip`)
- Actor (user or service token)
- Free-text search (matches request/response hash, error excerpt)
- "Save view" → name the filter combination, lives under user preferences

Each active filter becomes a chip with × to remove. "Clear all" link.

**Table**: virtualised, default 50 rows visible, infinite scroll.

| Col | Header | Width | Content |
|-----|--------|-------|---------|
| 1 | Status dot | 32px | colored by status family |
| 2 | Timestamp | 140px | absolute (compact) + tooltip relative |
| 3 | Tenant | 100px | optional, hidden on single-tenant |
| 4 | Server | 140px | clickable |
| 5 | Tool / RPC method | 200px | `tools/call: search` |
| 6 | Duration | 80px | numeric (right-aligned), color band for >p99 |
| 7 | Status | 80px | `2xx` `4xx` `5xx` pill |
| 8 | Actor | 140px | user email or token name |
| 9 | Audit ID | 100px | mono short hash, click → drill-down |

**Drill-down side-sheet** (right-side slide-in, 720px wide):

Timeline view at the top:
```
●────●────●────●────●
│    │    │    │    │
│    │    │    │    └─ Response sent     12:34:01.347
│    │    │    └────── Tool returned     12:34:01.345
│    │    └─────────── Server invoked    12:34:01.012
│    └──────────────── Handshake reused  12:34:01.010
└───────────────────── Request received  12:34:01.001
```

Below: collapsible JSON envelope viewer with tabs `Request | Response | Error | Headers | Audit metadata`.

- Each tab uses CodeMirror viewer (read-only, JSON mode, copy button)
- "Replay" button (admin-only, opens tools playground prefilled — destructive operations gated by type-to-confirm)
- "Permalink" copies a URL `/audit/:id` to clipboard

**Empty state**: "No audit rows match the current filters" + "Clear filters" CTA.

`data-testid="audit-list"`, `audit-row-{id}`, `audit-drilldown-tab-{name}`, `audit-drilldown-replay`.

### 4.9 Circuit-breaker dashboard (`/circuit-breakers`)

**Layout**: responsive card grid, each card = one (tenant, server, tool) triple.

**Card spec (240×140px)**:
```
┌─────────────────────────────┐
│ openai-mcp / search         │ ← server / tool
│ tenant: acme-corp           │ ← muted
│                             │
│ ┌─────────────────────────┐ │
│ │     ●  OPEN             │ │ ← state badge + animated pulse if open
│ │     Failures: 7 / 5     │ │
│ │     Reopens in: 42s     │ │ ← countdown if open
│ └─────────────────────────┘ │
│                             │
│ Last failure: 30s ago        │
│ [Reset] [Audit ▸]            │
└─────────────────────────────┘
```

**Animated transitions** (Framer Motion):
- closed → half-open: card flips on Y axis (180ms, ease-out-soft)
- half-open → open: card border pulses red 3× then settles (--ease-bounce-subtle)
- open → closed (recovered): card fills green-subtle then fades back to surface (320ms ease-out-default)
- Honour `prefers-reduced-motion`: collapse all animations to a 40ms opacity fade

**Filters**: state (open/half-open/closed/all), tenant, server.

**Reset action**:
- Confirmation: simple confirm dialog ("Reset breaker for openai-mcp / search? This allows traffic immediately.")
- POST endpoint TBD with backend (see §14)
- Optimistic UI: card flips to closed immediately, rollback on 4xx with toast

`data-testid="cb-card-{tenant}-{server}-{tool}"`, `cb-card-{...}-reset`.

### 4.10 OpenAPI playground (`/playground`)

Embed the `padosoft/askmydocs-mcp-pack` OpenAPI spec (served at `/api/admin/mcp-pack/openapi.json`) via **Scalar API Reference** (recommended over Swagger UI for visual fit + dark-mode + smaller bundle).

Configure:
- Theme: match our tokens (Scalar supports custom CSS variables)
- Auth: prefilled with the current user's Sanctum cookie
- "Try" actions hit the live backend — destructive ones gated by type-to-confirm

Alternative: **Stoplight Elements** (bigger bundle but more polished).

### 4.11 Settings (`/settings`)

Three sub-pages.

#### `/settings/preferences` (per-user, no permission gate)

- **Theme**: System / Light / Dark (radio cards)
- **Density**: Comfortable / Compact (radio cards)
- **Default landing page**: dropdown
- **Notifications**: toggle in-app toasts; toggle browser notifications (request permission button)
- **Reduced motion override**: respect OS / always reduce / never reduce
- **Keyboard shortcuts**: read-only table; "Edit" placeholder for v2 — at v1 they're fixed
- Persisted via `POST /api/admin/mcp-pack/me/preferences` (proposed addition — see §14)

#### `/settings/tenants` (admin only)

- Per-tenant config overrides
- Kill-switch matrix (toggle off any server/tool per tenant)
- Default policies (timeout / retry / CB) per tenant

#### `/settings/api-keys` (admin only)

- List of issued Sanctum tokens with scopes + last-used + creator
- "Issue new token" wizard
- "Revoke" with type-to-confirm

### 4.12 Help (`/help`)

- Keyboard shortcuts reference (auto-generated from the same map that powers `?` overlay)
- Glossary (MCP, JSON-RPC, handshake, circuit-breaker — short definitions)
- Getting started checklist (interactive — checks off as the user creates first server, runs first handshake, makes first invocation)
- Links to backend docs + GitHub

---

## 5. Wow-grade interaction patterns

### 5.1 Command palette (Cmd+K / Ctrl+K)

Use `cmdk`. Mandatory by recommendation; see §14 for fallback if Lorenzo declines.

**Sections** (in priority order):
1. **Actions** — "Create server", "Run handshake on…", "Reset breaker for…", "Switch theme", "Switch tenant"
2. **Navigation** — every route, fuzzy-matched
3. **Servers** — search-as-you-type against `/servers?q=`
4. **Tools** — flat list across servers
5. **Audit IDs** — paste a short hash, jump to drill-down
6. **Recent** — last 10 visited routes

**UX**:
- Open: 480×560px, centred, blurred backdrop
- Search input autofocuses
- Arrow keys + Enter; Esc to close
- Shortcut hint right-aligned per row (`/servers ⌘2`)
- Mouse hover highlights selection
- Empty: "Type to search… try 'create server', 'audit', or paste an ID"
- `data-testid="command-palette"`, `command-palette-item-{slug}`

**Implementation note**: lazy-load the data sources via TanStack Query so opening the palette doesn't refetch on every keystroke.

### 5.2 Real-time SSE feed (Dashboard + Audit live mode)

- Backend SSE: `GET /api/admin/mcp-pack/events?stream=tool_invocations` (proposed — confirm in §14)
- `EventSource` wrapped in a `useSse` hook with auto-reconnect (exponential backoff 1s → 30s cap), heartbeat tolerance (45s without event = stale indicator)
- Each event arrives → prepended to a Zustand store ring-buffer of 200
- DOM rendered via virtualisation (always 200 max in DOM regardless of throughput)
- Optimistic ordering: events carry server-side `seq` integer; reorder window of 2s tolerated
- Stale banner if heartbeat missed: "Live feed paused — reconnecting in 3s"
- Pause button suspends DOM updates while stream still buffers — resume catches up with a "+47 new" pill

### 5.3 Sparklines inline in tables

`recharts` `LineChart` with no axes, no tooltip on the row (tooltip only on hover an enlarged version in a popover).

Per-row sparkline data: pre-aggregated by backend at row-fetch time (`/servers?include=sparkline_1h`) — do NOT fire one request per row.

```tsx
<Sparkline data={row.calls1h} width={96} height={20} stroke="currentColor" />
```

Hover row → mini popover with axis + last value.

### 5.4 Optimistic mutations + toast + auto-rollback

Pattern (per R25):

```tsx
const { mutate } = useMutation({
    mutationFn: (id: string) => http.post(`/servers/${id}/disable`),
    onMutate: async (id) => {
        await qc.cancelQueries({ queryKey: serverKeys.detail(id) });
        const prev = qc.getQueryData(serverKeys.detail(id));
        qc.setQueryData(serverKeys.detail(id), (old) =>
            old ? { ...old, status: 'disabled' } : old
        );
        return { prev };
    },
    onError: (err, id, ctx) => {
        if (ctx?.prev) qc.setQueryData(serverKeys.detail(id), ctx.prev);
        toast.error(extractError(err), {
            action: { label: 'Retry', onClick: () => mutate(id) },
        });
    },
    onSettled: (_d, _e, id) => {
        qc.invalidateQueries({ queryKey: serverKeys.detail(id) });
    },
});
```

Toast spec (Radix Toast):
- Bottom-right by default (configurable in preferences)
- 5s auto-dismiss for success; persistent for errors until dismissed or action taken
- Action link styled like a TextLink, focusable
- Stack max 3; older slide out to make room

### 5.5 Resizable + collapsible side panels

- Drag handle: 4px wide, full height, cursor `col-resize`, hover bg `--accent-subtle`
- Min/max widths enforced
- Width persists in localStorage per panel key (`mcpAdmin.panel.audit.drilldown = 720`)
- Collapse button on the panel header: chevron → collapses to 48px stub
- Keyboard: focus the handle, arrow keys to resize 8px steps

### 5.6 Skeleton loaders + shimmer

Every async surface ships a skeleton matching its final layout dimensions (no layout shift).

Tokens:
```css
.skeleton {
    background: linear-gradient(90deg, var(--bg-inset) 0%, var(--bg-surface-hover) 50%, var(--bg-inset) 100%);
    background-size: 200% 100%;
    animation: shimmer 1.5s ease-in-out infinite;
}
@keyframes shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
@media (prefers-reduced-motion: reduce) { .skeleton { animation: none; } }
```

Skeletons appear after 100ms (Suspense `delay`) to avoid flashing for fast queries.

### 5.7 Keyboard shortcuts table

Open with `?` (anywhere not in an input). Renders as a Radix Dialog with two columns of categories.

| Category | Key | Action |
|----------|-----|--------|
| Global | `⌘K` / `Ctrl+K` | Open command palette |
| Global | `?` | Show this help |
| Global | `g d` | Go to Dashboard |
| Global | `g s` | Go to Servers |
| Global | `g t` | Go to Tools |
| Global | `g a` | Go to Audit log |
| Global | `g c` | Go to Circuit breakers |
| Global | `[` / `]` | Previous / next page (where applicable) |
| Audit | `/` | Focus filter search |
| Audit | `j` / `k` | Down / up in list |
| Audit | `Enter` | Open drill-down for current row |
| Audit | `Esc` | Close drill-down |
| Forms | `⌘Enter` | Submit |
| Modal | `Esc` | Close |

Implementation: `react-hotkeys-hook` or a small custom hook on `useEffect` + `keydown`. Scoped — don't fire `j/k` when an input is focused.

### 5.8 Dark mode + density toggle

- Dark mode: respect `prefers-color-scheme` by default; user can override; persists via API
- Density: `comfortable` (default) vs `compact` — toggles a `data-density` attribute on `<html>`, CSS uses `[data-density=compact] :where(.row) { padding-block: var(--space-1); }` etc.
- Toggle pair lives in the user menu (top bar)
- Optimistic: change UI instantly, fire-and-forget API call

### 5.9 Empty states with onboarding prompts

Every list screen has a distinct empty state — not a generic "No results". Each ships:
- Lucide icon (48px, tertiary tint)
- Heading (verb + noun: "Register your first server")
- Subhead (one sentence, why-and-how)
- Primary CTA (button)
- Secondary link (docs / video)
- Optional animated illustration — keep restrained (a single icon with `--ease-out-soft` pulse)

Onboarding overlay (first session only): subtle pulsing indicator on the primary CTA + dismiss-forever cookie.

### 5.10 Bulk-select sticky action bar

- Appears `bottom-4 left-1/2 -translate-x-1/2 fixed`
- Width auto, max-w-[640px]
- Translate-y-2 → 0 on appear, fade out on clear
- Pill style: rounded-full, `bg-surface`, `shadow-3`, padding `py-2 px-4`
- Layout: "{n} selected" + vertical divider + action buttons + "Clear"
- All actions in the bar respect RBAC — gray out if forbidden + tooltip

### 5.11 Form validation

- Inline per-field with React Hook Form + Zod
- Error appears below field, red border on the field, icon `CircleX` left-of-text
- Summary banner on submit if any failed (Radix `Alert`), focuses first invalid field
- Server-side errors mapped via 422 `errors.{field}: [string]` shape — same display
- `data-testid="{form}-{field}-error"`, `data-testid="{form}-error-banner"`

### 5.12 Type-to-confirm destructive actions

For: delete server, revoke token, force-reset all breakers.

Modal pattern:
- Title: "Delete server **openai-mcp**?"
- Body: "This will remove the server registration and all handshake history. Audit log entries are preserved."
- Input: "Type `delete-server-openai-mcp` to confirm"
- Submit button disabled until input matches exactly
- Cancel button always enabled
- `data-testid="confirm-delete-server-{id}-input"`, `confirm-delete-server-{id}-submit`

### 5.13 Toast notifications with action links

- Radix Toast or `sonner` (sonner has nicer defaults out of the box)
- Variants: success / info / warning / error
- Action link colour matches the variant border (semantic)
- Toasts compose: a successful disable shows "Server disabled. **Undo**" — clicking re-enables via the API
- Errors: "Failed to disable: …. **Retry** **View audit**"

### 5.14 Page transition micro-animations

`AnimatePresence` in the route outlet:
- Initial: `opacity: 0, y: 4`
- Animate: `opacity: 1, y: 0`
- Exit: `opacity: 0, y: -4`
- Duration: 160ms ease-out-soft
- Honour reduced-motion (collapse to 40ms opacity)

### 5.15 First-time guided tour

Skip a heavy lib (intro.js is ~50 KB gz). Instead build a tiny home-grown tour that floats a 280px callout next to highlighted elements via Radix Popover, with "Skip tour" + "Next" controls. 5 steps:

1. "This is your dashboard. Live tool invocations stream in real time."
2. "Servers — register MCP endpoints and run handshakes."
3. "Tools — explore catalogs and try them out without writing a JSON-RPC envelope."
4. "Audit — every invocation, with full request/response timeline."
5. "Press `?` anytime for keyboard shortcuts. `⌘K` to search anything."

Persisted: completed/skipped in `me/preferences`.

### 5.16 Audit drill-down with timeline + raw JSON-RPC

Already specified §4.8. Key UX detail: the timeline phases are HORIZONTAL when viewport > 1200px, VERTICAL stacked below it. The "Replay" button warns if the request body contains PII flags (if `padosoft/laravel-pii-redactor` is installed in the host, fetch `meta.pii_flags` from audit metadata and show a chip "PII detected — review before replay").

---

## 6. State + data layer

### 6.1 TanStack Query — queryKeys factory

Single source of truth for cache keys. File `src/lib/queryKeys.ts`:

```ts
export const queryKeys = {
    servers: {
        all: ['servers'] as const,
        list: (params?: ServersListParams) => ['servers', 'list', params ?? {}] as const,
        detail: (id: string) => ['servers', 'detail', id] as const,
        tools: (id: string) => ['servers', id, 'tools'] as const,
        handshakes: (id: string) => ['servers', id, 'handshakes'] as const,
    },
    audit: {
        all: ['audit'] as const,
        list: (params?: AuditListParams) => ['audit', 'list', params ?? {}] as const,
        detail: (id: string) => ['audit', 'detail', id] as const,
    },
    breakers: {
        all: ['breakers'] as const,
        list: (params?: BreakersParams) => ['breakers', 'list', params ?? {}] as const,
    },
    resources: {
        list: (serverId: string) => ['resources', serverId, 'list'] as const,
        read: (serverId: string, uri: string) => ['resources', serverId, 'read', uri] as const,
    },
    prompts: {
        list: (serverId: string) => ['prompts', serverId, 'list'] as const,
        get: (serverId: string, name: string, args: unknown) =>
            ['prompts', serverId, 'get', name, args] as const,
    },
    tools: {
        list: (serverId: string) => ['tools', serverId, 'list'] as const,
    },
    me: {
        profile: ['me', 'profile'] as const,
        preferences: ['me', 'preferences'] as const,
    },
} as const;
```

### 6.2 Default query options

```ts
new QueryClient({
    defaultOptions: {
        queries: {
            staleTime: 30_000,          // 30s default freshness
            gcTime: 5 * 60_000,         // 5min cache retention
            refetchOnWindowFocus: true,
            retry: (failureCount, err) => {
                if (isHttpError(err) && err.status >= 400 && err.status < 500) return false;
                return failureCount < 2;
            },
        },
        mutations: {
            retry: false,
        },
    },
});
```

### 6.3 Optimistic mutation rule (R25)

Every optimistic mutation dedupes by ID when merging server response. Use this helper:

```ts
function dedupeById<T extends { id: string | number }>(list: T[]): T[] {
    const seen = new Set<T['id']>();
    return list.filter((x) => (seen.has(x.id) ? false : seen.add(x.id) && true));
}
```

Always apply on `onSuccess` cache merge. Test posture: strict-mode locator (no `.first()`).

### 6.4 Pagination

- **Servers / breakers / tools**: not paginated (assumed small N, single fetch + client-side filter)
- **Audit log**: cursor-based infinite, `useInfiniteQuery`, page size 50
- **Handshakes (per-server)**: cursor-based infinite, page size 25

### 6.5 SSE integration

Store in `Zustand`, NOT `TanStack Query`. Reason: TQ semantics expect request/response, SSE is push.

```ts
// src/store/liveFeed.ts
type FeedEvent = { id: string; seq: number; ts: number; server: string; tool: string; durationMs: number; status: 'ok' | 'err'; auditId: string };

interface LiveFeedState {
    events: FeedEvent[];
    paused: boolean;
    stale: boolean;
    append: (e: FeedEvent) => void;
    setPaused: (b: boolean) => void;
    setStale: (b: boolean) => void;
    clear: () => void;
}

export const useLiveFeed = create<LiveFeedState>((set) => ({
    events: [],
    paused: false,
    stale: false,
    append: (e) =>
        set((s) => ({
            events: [e, ...s.events].slice(0, 200),
        })),
    setPaused: (paused) => set({ paused }),
    setStale: (stale) => set({ stale }),
    clear: () => set({ events: [] }),
}));
```

### 6.6 Zustand stores (the short list — only where genuinely needed)

| Store | Purpose |
|-------|---------|
| `liveFeed` | SSE event ring buffer (above) |
| `commandPalette` | Open/close state + recent commands |
| `ui` | Sidebar collapsed, theme override, density, panel widths |
| `bulkSelection` | Set of selected ids per list screen |

Everything else: TanStack Query + URL state (TanStack Router `search` params).

### 6.7 URL state vs local state

Filters + tab selection + pagination cursor → URL search params (via TanStack Router `useSearch`). Persists shareable links.

Modal open/close + side-sheet drawer state → component-local `useState` UNLESS the modal contains its own URL (e.g. audit drill-down lives at `/audit/:id` even though it visually presents as a side-sheet).

---

## 7. Auth + RBAC integration

### 7.1 Authentication

Host provides Sanctum cookie. SPA assumes the cookie is set before mount (the host blade view runs through the host's auth middleware first).

If the SPA detects a 401 from any API call:
1. Show a banner "Your session expired. Refresh to sign in again."
2. Provide "Refresh" button → `window.location.reload()`
3. Optional: auto-reload after 5s if user idle

### 7.2 RBAC

On mount, SPA calls `GET /api/admin/mcp-pack/me`:

```json
{
    "id": 42,
    "email": "lorenzo@padosoft.com",
    "name": "Lorenzo Padovani",
    "tenant_id": "acme-corp",
    "tenants_accessible": ["acme-corp", "demo-corp"],
    "permissions": [
        "mcp.servers.view",
        "mcp.servers.create",
        "mcp.servers.update",
        "mcp.servers.delete",
        "mcp.servers.handshake",
        "mcp.tools.invoke",
        "mcp.audit.view",
        "mcp.audit.replay",
        "mcp.breakers.view",
        "mcp.breakers.reset",
        "mcp.settings.tenants",
        "mcp.settings.api-keys"
    ],
    "preferences": {
        "theme": "system",
        "density": "comfortable",
        "default_landing": "dashboard",
        "reduced_motion": "respect-os",
        "tour_completed_at": null
    }
}
```

`Me` is queried once, retained, refreshed every 5 minutes + on focus.

### 7.3 Permission gates

Tiny helper:

```ts
function useCan(permission: string): boolean {
    const { data: me } = useMe();
    return me?.permissions.includes(permission) ?? false;
}
```

Usage:
```tsx
const canCreate = useCan('mcp.servers.create');
{canCreate && <Button data-testid="servers-create">+ New server</Button>}
```

**Defence-in-depth rule** (R reminder): never rely on the client gate alone. Every mutation endpoint must enforce on the backend too — the gate just keeps the UI honest. If a user hits a forbidden endpoint via console fiddling, the server returns 403 and the toast surfaces it.

### 7.4 Tenant scoping

Tenant switcher in top bar (visible only if `tenants_accessible.length > 1`). Selecting a tenant:
- Calls `POST /api/admin/mcp-pack/me/active-tenant` to update server-side session
- Invalidates all queries (`qc.invalidateQueries()`)
- Reloads `me`

Every list query implicitly scopes to active tenant on the backend; the FE never sends `tenant_id` explicitly.

---

## 8. Cross-mount manifest pattern

### 8.1 Build output contract

The package's `frontend/vite.config.ts`:

```ts
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import path from 'node:path';

export default defineConfig({
    plugins: [react(), tailwindcss()],
    build: {
        manifest: true,
        outDir: 'dist',
        rollupOptions: {
            input: path.resolve(__dirname, 'src/main.tsx'),
        },
    },
    base: '/mcp-admin-assets/',  // host rewrites this — see §8.3
});
```

Result: `dist/manifest.json` looks like:

```json
{
    "src/main.tsx": {
        "file": "assets/main-D7eXa3.js",
        "name": "main",
        "src": "src/main.tsx",
        "isEntry": true,
        "css": ["assets/main-Bc2hYx.css"]
    }
}
```

### 8.2 Service provider on the host side

`McpPackAdminServiceProvider::boot()`:
- Registers the route `/admin/mcp/{any?}` (the host CAN override the prefix via config) using a `MountController`
- Publishes the `dist/` folder to `public/mcp-admin-assets/` on `vendor:publish`
- Registers the blade view `askmydocs-mcp-pack-admin::shell`
- Exposes a config file `config/mcp-pack-admin.php`:
  ```php
  return [
      'path_prefix' => env('MCP_PACK_ADMIN_PREFIX', 'admin/mcp'),
      'assets_base' => env('MCP_PACK_ADMIN_ASSETS_BASE', '/mcp-admin-assets'),
      'middleware' => ['web', 'auth', 'verified'],
      'permission_gate' => 'mcp.servers.view',  // host-side floor
      'manifest_path' => base_path('vendor/padosoft/askmydocs-mcp-pack-admin/frontend/dist/manifest.json'),
  ];
  ```

### 8.3 Blade view (shell)

`resources/views/shell.blade.php`:

```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>MCP Pack Admin</title>
    @php
        $manifest = json_decode(file_get_contents(config('mcp-pack-admin.manifest_path')), true);
        $entry = $manifest['src/main.tsx'];
        $assetsBase = config('mcp-pack-admin.assets_base');
    @endphp
    @foreach (($entry['css'] ?? []) as $css)
        <link rel="stylesheet" href="{{ $assetsBase }}/{{ $css }}">
    @endforeach
</head>
<body>
    <div
        id="mcp-pack-admin-root"
        data-base-path="{{ url(config('mcp-pack-admin.path_prefix')) }}"
        data-api-base="{{ url('/api/admin/mcp-pack') }}"
        data-csrf-cookie="{{ url('/sanctum/csrf-cookie') }}"
        data-host-name="{{ config('app.name') }}"
    ></div>
    <script type="module" src="{{ $assetsBase }}/{{ $entry['file'] }}"></script>
</body>
</html>
```

### 8.4 SPA bootstrap reads the data-* attrs

`src/main.tsx`:

```ts
const root = document.getElementById('mcp-pack-admin-root')!;
const basePath = root.dataset.basePath!;
const apiBase = root.dataset.apiBase!;
const csrfCookieUrl = root.dataset.csrfCookie!;
const hostName = root.dataset.hostName ?? 'AskMyDocs';

// then TanStack Router is configured with basePath
// then HTTP client is configured with apiBase + csrfCookieUrl
```

### 8.5 Host integration steps (junior-proof)

The README walks the consumer through:

1. `composer require padosoft/askmydocs-mcp-pack-admin`
2. `php artisan vendor:publish --tag=mcp-pack-admin-assets`
3. `php artisan vendor:publish --tag=mcp-pack-admin-config` (optional)
4. (optional) Add an entry to the host admin shell's nav
5. Visit `/admin/mcp/`

Each step has a verification one-liner ("you should now see file X at Y").

### 8.6 Env vars

| Var | Default | Purpose |
|-----|---------|---------|
| `MCP_PACK_ADMIN_PREFIX` | `admin/mcp` | URL prefix to mount under |
| `MCP_PACK_ADMIN_ASSETS_BASE` | `/mcp-admin-assets` | Public URL prefix for assets |

---

## 9. Folder structure inside the package

```
askmydocs-mcp-pack-admin/
├── composer.json
├── README.md
├── LICENSE
├── phpunit.xml
├── config/
│   └── mcp-pack-admin.php
├── src/
│   ├── McpPackAdminServiceProvider.php
│   └── Http/
│       └── Controllers/
│           └── MountController.php
├── resources/
│   └── views/
│       └── shell.blade.php
├── tests/
│   ├── Feature/
│   │   └── MountControllerTest.php
│   └── Unit/
└── frontend/
    ├── package.json
    ├── tsconfig.json
    ├── tsconfig.node.json
    ├── vite.config.ts
    ├── tailwind.config.ts        (Tailwind v4 — config-as-CSS, file optional)
    ├── playwright.config.ts
    ├── vitest.config.ts
    ├── index.html
    ├── public/
    ├── dist/                     (generated, gitignored, but committed to package on tag)
    ├── e2e/
    │   ├── auth.setup.ts
    │   ├── fixtures.ts
    │   ├── setup-helpers.ts
    │   ├── dashboard.spec.ts
    │   ├── servers.spec.ts
    │   ├── servers-create.spec.ts
    │   ├── tools-playground.spec.ts
    │   ├── audit.spec.ts
    │   ├── circuit-breakers.spec.ts
    │   ├── resources.spec.ts
    │   ├── prompts.spec.ts
    │   └── command-palette.spec.ts
    └── src/
        ├── main.tsx
        ├── app.tsx
        ├── routes/                          (TanStack Router file-based)
        │   ├── __root.tsx
        │   ├── index.tsx                    (redirect → /dashboard)
        │   ├── dashboard/
        │   │   └── index.tsx
        │   ├── servers/
        │   │   ├── index.tsx
        │   │   ├── new.tsx
        │   │   └── $serverId/
        │   │       ├── index.tsx
        │   │       └── edit.tsx
        │   ├── tools/
        │   │   ├── index.tsx
        │   │   └── $serverId/
        │   │       └── $toolName.tsx
        │   ├── resources/
        │   │   └── index.tsx
        │   ├── prompts/
        │   │   ├── index.tsx
        │   │   └── $serverId/
        │   │       └── $promptName.tsx
        │   ├── audit/
        │   │   ├── index.tsx
        │   │   └── $auditId.tsx
        │   ├── circuit-breakers/
        │   │   └── index.tsx
        │   ├── playground/
        │   │   └── index.tsx
        │   ├── settings/
        │   │   ├── index.tsx
        │   │   ├── preferences.tsx
        │   │   ├── tenants.tsx
        │   │   └── api-keys.tsx
        │   └── help/
        │       └── index.tsx
        ├── features/                        (feature folders contain domain logic)
        │   ├── servers/
        │   │   ├── api.ts
        │   │   ├── types.ts
        │   │   ├── hooks/
        │   │   │   ├── useServers.ts
        │   │   │   ├── useServer.ts
        │   │   │   └── useServerMutations.ts
        │   │   ├── components/
        │   │   │   ├── ServersList.tsx
        │   │   │   ├── ServersListRow.tsx
        │   │   │   ├── ServerStatusDot.tsx
        │   │   │   ├── ServerCreateWizard.tsx
        │   │   │   ├── ServerEditForm.tsx
        │   │   │   ├── ServerDetailTabs.tsx
        │   │   │   ├── TransportFields.tsx
        │   │   │   ├── BulkActionBar.tsx
        │   │   │   └── ServersEmptyState.tsx
        │   │   └── __tests__/
        │   │       ├── ServersList.test.tsx
        │   │       └── ServerCreateWizard.test.tsx
        │   ├── tools/                       (mirror)
        │   ├── resources/                   (mirror)
        │   ├── prompts/                     (mirror)
        │   ├── audit/                       (mirror, drill-down here)
        │   ├── circuit-breakers/            (mirror)
        │   ├── dashboard/                   (KPI tiles + live feed)
        │   └── settings/
        ├── components/                       (cross-feature shared UI)
        │   ├── ui/                           (shadcn-style primitives)
        │   │   ├── button.tsx
        │   │   ├── input.tsx
        │   │   ├── select.tsx
        │   │   ├── checkbox.tsx
        │   │   ├── switch.tsx
        │   │   ├── dialog.tsx
        │   │   ├── popover.tsx
        │   │   ├── tooltip.tsx
        │   │   ├── tabs.tsx
        │   │   ├── toast.tsx
        │   │   ├── dropdown-menu.tsx
        │   │   ├── command.tsx               (cmdk-based palette primitive)
        │   │   ├── alert.tsx
        │   │   ├── badge.tsx
        │   │   ├── pill.tsx
        │   │   ├── card.tsx
        │   │   ├── table.tsx
        │   │   ├── skeleton.tsx
        │   │   ├── sparkline.tsx
        │   │   ├── code-block.tsx
        │   │   ├── json-viewer.tsx
        │   │   ├── empty-state.tsx
        │   │   ├── kbd.tsx
        │   │   ├── status-dot.tsx
        │   │   ├── resizable-panels.tsx
        │   │   ├── timeline.tsx
        │   │   ├── breadcrumb.tsx
        │   │   └── form-error.tsx
        │   ├── layout/
        │   │   ├── AppShell.tsx
        │   │   ├── TopBar.tsx
        │   │   ├── SideNav.tsx
        │   │   ├── TenantSwitcher.tsx
        │   │   ├── UserMenu.tsx
        │   │   ├── ThemeToggle.tsx
        │   │   └── DensityToggle.tsx
        │   ├── feedback/
        │   │   ├── ErrorBoundary.tsx
        │   │   ├── NotFound.tsx
        │   │   ├── Forbidden.tsx
        │   │   ├── ApiErrorBanner.tsx
        │   │   └── SessionExpiredBanner.tsx
        │   ├── command-palette/
        │   │   ├── CommandPalette.tsx
        │   │   └── sources/
        │   │       ├── actions.ts
        │   │       ├── navigation.ts
        │   │       ├── servers.ts
        │   │       └── audit.ts
        │   ├── tour/
        │   │   └── GuidedTour.tsx
        │   └── help/
        │       ├── ShortcutsDialog.tsx
        │       └── shortcuts.ts
        ├── hooks/
        │   ├── useMe.ts
        │   ├── useCan.ts
        │   ├── useSse.ts
        │   ├── useHotkey.ts
        │   ├── useDebouncedValue.ts
        │   ├── useResizablePanel.ts
        │   └── useDocumentTitle.ts
        ├── lib/
        │   ├── http.ts                       (fetch wrapper, csrf, errors)
        │   ├── queryKeys.ts
        │   ├── queryClient.ts
        │   ├── router.ts
        │   ├── env.ts                        (reads data-* attrs)
        │   ├── cn.ts                         (clsx + tailwind-merge)
        │   ├── format.ts                     (date, bytes, latency)
        │   ├── jsonSchemaToZod.ts
        │   └── intl.ts
        ├── store/
        │   ├── liveFeed.ts
        │   ├── ui.ts
        │   ├── commandPalette.ts
        │   └── bulkSelection.ts
        ├── styles/
        │   ├── global.css                    (Tailwind v4 entry)
        │   ├── tokens.css                    (CSS variables)
        │   └── prose.css                     (markdown-rendered content)
        ├── types/
        │   ├── api.ts                        (generated from OpenAPI; see §9.1)
        │   ├── domain.ts
        │   └── env.d.ts
        └── test/
            ├── setup.ts
            └── test-utils.tsx                (RTL wrapper with providers)
```

### 9.1 OpenAPI → TypeScript generation

Use `openapi-typescript` against `padosoft/askmydocs-mcp-pack`'s `openapi.json`:

```
npx openapi-typescript ./openapi.json -o src/types/api.ts
```

Wire as `npm run gen:api`. Run it in CI to assert the FE and BE stay in sync (R20 mirror).

---

## 10. Test posture

### 10.1 Vitest + RTL component tests

- All component tests under `src/features/*/__tests__/`
- One spec per component minimum
- Use a shared RTL wrapper `test-utils.tsx` that provides QueryClientProvider, RouterProvider, ToastProvider with sensible defaults
- Mock `http` calls via `msw` (Mock Service Worker) — happy + failure paths
- 80% line coverage target on `features/` and `components/ui/`

### 10.2 Playwright E2E (R12 + R13)

Per the rules: at least 1 happy + 1 fail-path scenario per user-visible feature, against real backend, external services stubbed only.

Suite plan:

| Spec | Happy path | Fail path(s) |
|------|-----------|--------------|
| `dashboard.spec.ts` | KPI tiles render, sparkline renders, live feed appears | API 500 on KPI → error state; SSE disconnect → stale banner |
| `servers.spec.ts` | List renders, filter narrows, row click navigates | 500 on list → error state; empty (seed empty) → empty state |
| `servers-create.spec.ts` | Wizard step 1→2→3, submit, redirect to detail | Field validation; backend 422 mapping; cancel-confirm-discard |
| `servers-edit.spec.ts` | Edit field, save | 422 mapping; concurrent edit → conflict banner |
| `tools-playground.spec.ts` | Schema renders, form auto-builds, invoke success | Invalid input → field errors; backend 500 → response pane error |
| `audit.spec.ts` | List virtualises, filter narrows, drill-down opens | Empty filters → empty state; replay forbidden → toast |
| `circuit-breakers.spec.ts` | Cards render, reset card with confirmation | Reset 4xx → rollback + toast |
| `resources.spec.ts` | Tree expands, preview pane shows markdown | Binary > 1MB → fallback; 500 on read → error state |
| `prompts.spec.ts` | Form generates, preview updates on type | Invalid args → preview error |
| `command-palette.spec.ts` | Cmd+K opens, search, navigate | Empty query state; "no results" state |
| `a11y.spec.ts` | axe-core scan across screens | violations fail the test |

### 10.3 Fixtures

`fixtures.ts` extends Playwright's `test` with:
- `seeded` auto-fixture that hits `POST /testing/reset` + `/testing/seed` from the host's testing controller (R13 lesson)
- `loginAs(role)` helper using stored auth state under `playwright/.auth/{role}.json`
- `expectStateReady(locator)` polls `data-state="ready"` (never `waitForTimeout`)

### 10.4 External stub allowlist

E2E may only `page.route()` to intercept calls leaving the application boundary:
- AI providers: `api.openai.com`, `api.anthropic.com`, `generativelanguage.googleapis.com`, etc.
- Email: Mailgun / SES / Mailersend
- Object storage: S3 / R2

Any internal route interception requires the `R13: failure injection` marker comment in the spec.

`scripts/verify-e2e-real-data.sh` gates this in CI.

### 10.5 Architecture tests

PHP-side:
- `MountControllerTest::test_mount_serves_shell_blade_with_csrf_token`
- `MountControllerTest::test_mount_404_when_user_lacks_permission`
- `ManifestResolutionTest::test_manifest_paths_resolve_correctly`

---

## 11. Accessibility checklist (R15)

Every PR ships green on this list.

### Keyboard

- [ ] All interactive elements are reachable with `Tab` in a logical order
- [ ] `Shift+Tab` reverses the order without surprises
- [ ] `Enter` activates focused buttons; `Space` activates buttons and toggles
- [ ] `Esc` closes modals, popovers, side-sheets
- [ ] Focus is moved into modals on open; restored to trigger on close
- [ ] No focus traps outside of explicit modals
- [ ] Skip-to-content link at top of `<body>` (visible on first Tab)
- [ ] Arrow keys navigate within radio groups, tab strips, listboxes, trees
- [ ] `j/k` and other shortcuts disabled when an input is focused

### Screen reader

- [ ] Every `<input>`, `<select>`, `<textarea>` has either `<label htmlFor>` or `aria-label`
- [ ] Placeholder is NEVER used as the only label
- [ ] Icon-only buttons have `aria-label` describing the action
- [ ] Status changes announce via `aria-live="polite"` for non-urgent (toasts), `aria-live="assertive"` for urgent (errors that interrupt)
- [ ] Async surfaces have `data-state` AND `aria-busy` while loading
- [ ] Dynamic regions use semantic landmarks (`<nav>`, `<main>`, `<aside>`, `role="region"` with `aria-label`)
- [ ] Tables use `<th scope>` for header cells
- [ ] Hierarchical widgets (tree, listbox, tabs) put `role` + `aria-expanded` / `aria-selected` on the FOCUSABLE element (the `<button>`, not the wrapper)
- [ ] Visually-hidden but interactive elements use the visually-hidden CSS pattern, NEVER `display:none`

### Focus styling

- [ ] Every focusable element has a visible focus ring (`outline: none` only if replaced with `box-shadow` or equivalent)
- [ ] Focus ring uses `--shadow-focus` (3px accent ring)
- [ ] Focus indicator has min 3:1 contrast with surrounding bg

### Colour contrast

- [ ] Body text vs background: ≥ 4.5:1 (WCAG AA)
- [ ] Large text (18px+ regular / 14px+ bold): ≥ 3:1
- [ ] UI components (input borders, focus rings, status dots) vs adjacent colour: ≥ 3:1
- [ ] Status semantic colours have a non-colour redundant signal (icon, text, pattern)

### Motion

- [ ] Every animation respects `@media (prefers-reduced-motion: reduce)` and collapses to ≤ 40ms opacity
- [ ] No content auto-plays without a pause/stop control
- [ ] SSE live feed has a pause button reachable by keyboard

### Forms

- [ ] Error messages associate with their field via `aria-describedby`
- [ ] Required fields marked with `aria-required="true"` + visible "Required" hint
- [ ] On submit failure, focus moves to the first invalid field
- [ ] Summary banner has `role="alert"` and `aria-live="assertive"`

### Tooltips / popovers

- [ ] Tooltip respond to focus AND mouseenter (not mouse-only)
- [ ] Tooltip auto-dismiss on blur + escape
- [ ] Popover trap focus while open; restore on close
- [ ] `aria-expanded` on the trigger reflects state

### Testing automation

- [ ] `@axe-core/playwright` runs across every screen in `a11y.spec.ts`
- [ ] Violations fail the build
- [ ] Manual NVDA + VoiceOver pass before v1.0 GA

---

## 12. Performance budget

### 12.1 Bundle size

| Target | Limit |
|--------|-------|
| Initial JS (route `/dashboard`) gzip | < 200 KB |
| Initial CSS gzip | < 35 KB |
| Per-route lazy chunk gzip avg | < 80 KB |
| Total app gzip | < 600 KB |
| Vendor chunk (TanStack + React + Radix + lucide) gzip | < 130 KB |

Enforce via `rollup-plugin-visualizer` + a CI step that fails if the gzip-size budget is exceeded.

Code-splitting strategy:
- TanStack Router auto-splits per route
- Heavy single-screen deps (Scalar API explorer, CodeMirror, PDF.js, react-syntax-highlighter) are dynamic-imported inside the route component
- Heaviest icons in lucide tree-shake; do not bulk-import

### 12.2 Lighthouse / Web Vitals targets

| Metric | Target |
|--------|--------|
| LCP | < 1.5s on 4G simulated |
| INP | < 200ms |
| CLS | < 0.05 |
| TTI | < 2.5s |
| Lighthouse Performance | ≥ 90 |
| Lighthouse Accessibility | ≥ 95 |
| Lighthouse Best Practices | ≥ 95 |

### 12.3 Runtime budgets

- Virtualise any table/list > 100 rows
- Debounce search inputs at 200ms
- SSE event handler must not block > 5ms per event (offload formatting to a worker if needed)
- Sparkline render < 8ms per row (precompute path strings if recharts is too slow)
- Route transition perceived latency < 250ms (suspense fallback after 100ms)

### 12.4 React 19 hygiene

- Use `use()` for promise-thennable resources where it shortens code
- `useMemo` only where measurable; do not premature-optimize
- `useTransition` around filter changes that cascade into large re-renders
- Never call `setState` in render
- Strict mode on in dev

---

## 13. Phased delivery plan

### v0.1 — Skeleton (week 1–2)

**Goal**: rendered shell + auth + dashboard reads real data + servers CRUD

- Package scaffolding (composer + Vite + Tailwind + TanStack Router/Query + shadcn primitives)
- Cross-mount manifest pattern proven against AskMyDocs host
- `me` endpoint + RBAC gate
- AppShell + TopBar + SideNav + breadcrumbs + ErrorBoundary + 404 + 403
- Dashboard with 4 KPI tiles + sparklines (no SSE feed yet)
- Servers list (no virtualisation yet — assume small N for early adopters)
- Servers create wizard (3 steps)
- Servers detail page (Overview tab only)
- Servers edit
- Dark/light theme toggle
- Density toggle
- README with junior-proof install steps
- Playwright happy-path on dashboard + servers
- a11y.spec.ts running axe baseline (zero violations target)

### v0.2 — Observability (week 3–4)

- Audit log screen (virtualised, filters, drill-down side-sheet, timeline view)
- Circuit-breaker dashboard (cards + reset)
- Servers detail tabs: Tools, Handshakes, Audit, Config
- Toast notifications + optimistic mutations
- Bulk-select sticky action bar on servers list
- Keyboard shortcuts (`?` overlay + `g d`, `g s`, etc.)
- SSE live feed on dashboard
- Playwright fail-paths for the above

### v0.3 — Catalog (week 5–6)

- Tools explorer (sidebar + main pane + try-it playground)
- Resources browser (3-col resizable + content preview by MIME)
- Prompts library (live render preview)
- Command palette (Cmd+K) with all four sources
- JSON Schema → form generator (Zod-backed)
- CodeMirror integration for raw-JSON edit modes
- Settings (preferences page) + persistence to backend

### v0.4 — Polish (week 7)

- Settings: tenants + api-keys pages
- OpenAPI playground (Scalar embed)
- Guided tour (5 steps)
- Animated CB state transitions
- Page transition micro-animations
- All empty states refined
- Visual regression snapshots locked

### v1.0 — Hardening (week 8)

- Full a11y audit (NVDA + VoiceOver pass)
- Performance budget passing all metrics
- Bundle visualizer in CI
- Playwright suite: full happy + fail per feature
- Vitest coverage > 80% on features + ui
- Documentation: README + per-screen design notes + tour script
- Designer review + Lorenzo sign-off
- Tag `v1.0.0`, publish to Packagist

---

## 14. Risks + open questions

### Palette

- **Q1**: Confirm palette — **Option A Indigo Sentinel** (recommended), Option B Stripe Plum, or Option C Vercel Sentinel?
- **Q2**: AskMyDocs main shell uses sky-500 family today. Do we deliberately differentiate (recommended — read as a sibling not a clone) or align (one accent across the family)?

### Motion

- **Q3**: Motion intensity — restrained (recommended baseline, ≤ 240ms most surfaces) or expressive (CB transitions, route transitions, sparkline animations more pronounced)?
- **Q4**: Should we ship Framer Motion in v0.1 or defer to v0.4? It's ~70 KB gz — significant for the bundle budget. Alternative: hand-rolled CSS keyframes for v0.1, introduce Framer Motion only when CB transitions land in v0.3.

### Navigation

- **Q5**: Vertical side nav (recommended for 9-screen IA) vs horizontal top tabs vs hybrid (top tabs for top-level + side nav for screen-internal)?
- **Q6**: Collapse-by-default on viewports < 1280px?

### Command palette

- **Q7**: Mandatory in v0.1 (recommended — distinguishing wow feature, low cost) or deferred to v0.3? `cmdk` is ~10 KB gz.

### OpenAPI playground vendor

- **Q8**: Scalar (recommended — smallest, prettiest, native dark mode) vs Stoplight Elements (more polished but heavier) vs Swagger UI (oldest, biggest, most-known)?

### Backend surface gaps

- **Q9**: Does v1.4 expose SSE for live tool invocations? If not, what's the timeline? Without it the dashboard live feed must poll (acceptable fallback: TanStack Query refetch every 2s on `/audit?windowSeconds=2`).
- **Q10**: Does v1.4 expose `POST /circuit-breakers/{id}/reset`? If not, we either omit the action in v0.2 or coordinate a backend addition.
- **Q11**: Does v1.4 expose `GET /me` + `POST /me/preferences`? If not, propose a v1.5 addition or store preferences in `localStorage` for v1.0 (tradeoff: not multi-device).
- **Q12**: Does the audit log surface `meta.pii_flags`? Drives the replay warning UX (§5.16).

### Multi-tenant

- **Q13**: Is the v1.4 backend single-tenant or multi-tenant? Tenant switcher behaviour depends. AskMyDocs is multi-tenant; the package's design should anticipate it.

### Connectivity

- **Q14**: Offline behaviour — fail fast with a banner, or queue mutations and retry? Recommendation: fail fast (this is an admin tool, not a field app).

### Internationalisation

- **Q15**: i18n in v1.0 or v2.0? Sister-package admin SPAs are English-only today. Recommendation: English-only in v1.0; structure copy in a single `intl.ts` to enable later.

### Visual identity collateral

- **Q16**: Logo / wordmark — reuse `padosoft/mcp-pack` logo or design a distinct admin wordmark? Recommendation: reuse with a small "admin" suffix word.

### Skip the tour

- **Q17**: Guided tour controversial — Linear and Vercel skip it; Stripe does it well. Recommendation: ship it OFF-by-default, opt-in via a "Take the tour" link in `/help`.

### Density default

- **Q18**: Default density — Comfortable (Stripe-like, recommended for first-time users) or Compact (Linear-like, expert default)? Recommendation: Comfortable default, prominent toggle.

### Naming

- **Q19**: Confirm Packagist name — `padosoft/askmydocs-mcp-pack-admin` is the working name. Mirrors `eval-harness-ui` more than `laravel-flow-admin`. Lorenzo locks in.

---

## 15. Where to start (Monday-morning cheat sheet)

Five things the designer + engineer can attack on day one without further input.

1. **Lock the palette in Figma**. Drop the Option A Indigo Sentinel tokens (§2.2) into a Figma variables collection, build the light + dark page, generate the swatches doc. Two hours.
2. **Stand up the package skeleton**. `composer init` + `frontend/` Vite + Tailwind v4 + TanStack Router/Query + Radix + lucide-react + a single `AppShell` rendering the TopBar + SideNav with hardcoded nav items. Half a day. Reference: clone `padosoft/laravel-flow-admin` and strip the flow-specific routes.
3. **Wire the manifest mount pattern against AskMyDocs**. Implement `McpPackAdminServiceProvider` + `MountController` + the blade shell view (§8). Reference: copy `padosoft/laravel-pii-redactor-admin`'s mount code verbatim — same shape. Half a day.
4. **Build the Servers list against real API**. Wire `GET /api/admin/mcp-pack/servers` via TanStack Query, render the table with the column spec (§4.2), add the filter bar, write a `data-state` aware loading + error + empty triad. Half a day. Drives every subsequent screen.
5. **Write `dashboard.spec.ts` happy + fail path against the real backend**. Forces the test harness, the `seeded` fixture, the `loginAs` helper, the `expectStateReady` helper into existence early. Half a day. Reference: copy from `padosoft/eval-harness-ui` e2e/.

Everything else cascades from these five.

---

## Appendix A — Tailwind v4 global token CSS (starter)

```css
/* src/styles/tokens.css */
@theme {
    /* Background tokens */
    --color-bg-base: #FAFAF9;
    --color-bg-surface: #FFFFFF;
    --color-bg-surface-hover: #F5F5F4;
    --color-bg-surface-pressed: #E7E5E4;
    --color-bg-inset: #F5F5F4;

    /* Border tokens */
    --color-border-subtle: #E7E5E4;
    --color-border-default: #D6D3D1;
    --color-border-strong: #A8A29E;

    /* Foreground tokens */
    --color-fg-primary: #1C1917;
    --color-fg-secondary: #57534E;
    --color-fg-tertiary: #78716C;
    --color-fg-disabled: #A8A29E;

    /* Accent tokens */
    --color-accent-default: #4F46E5;
    --color-accent-hover: #4338CA;
    --color-accent-pressed: #3730A3;
    --color-accent-fg: #FFFFFF;
    --color-accent-subtle: #EEF2FF;
    --color-accent-ring: #A5B4FC;

    /* Semantic */
    --color-success-default: #15803D;
    --color-success-subtle: #DCFCE7;
    --color-warning-default: #B45309;
    --color-warning-subtle: #FEF3C7;
    --color-danger-default: #B91C1C;
    --color-danger-subtle: #FEE2E2;
    --color-info-default: #0369A1;
    --color-info-subtle: #E0F2FE;

    /* Radius */
    --radius-xs: 2px;
    --radius-sm: 4px;
    --radius-md: 6px;
    --radius-lg: 8px;
    --radius-xl: 12px;
    --radius-2xl: 16px;

    /* Shadows */
    --shadow-1: 0 1px 2px 0 rgb(0 0 0 / 0.04);
    --shadow-2: 0 1px 3px 0 rgb(0 0 0 / 0.08), 0 1px 2px 0 rgb(0 0 0 / 0.04);
    --shadow-3: 0 4px 12px 0 rgb(0 0 0 / 0.10), 0 2px 4px 0 rgb(0 0 0 / 0.06);
    --shadow-4: 0 12px 32px 0 rgb(0 0 0 / 0.14), 0 4px 8px 0 rgb(0 0 0 / 0.08);

    /* Fonts */
    --font-sans: "Inter", "InterVariable", ui-sans-serif, system-ui, sans-serif;
    --font-mono: "JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, monospace;

    /* Animation timings */
    --duration-instant: 80ms;
    --duration-fast: 180ms;
    --duration-default: 220ms;
    --duration-slow: 320ms;

    --ease-out-soft: cubic-bezier(0.22, 1, 0.36, 1);
    --ease-out-default: cubic-bezier(0.16, 1, 0.3, 1);
    --ease-in-out: cubic-bezier(0.65, 0, 0.35, 1);
    --ease-bounce-subtle: cubic-bezier(0.34, 1.56, 0.64, 1);
}

[data-theme="dark"] {
    --color-bg-base: #0C0A09;
    --color-bg-surface: #1C1917;
    --color-bg-surface-hover: #292524;
    --color-bg-surface-pressed: #44403C;
    --color-bg-inset: #0C0A09;

    --color-border-subtle: #292524;
    --color-border-default: #44403C;
    --color-border-strong: #78716C;

    --color-fg-primary: #F5F5F4;
    --color-fg-secondary: #D6D3D1;
    --color-fg-tertiary: #A8A29E;
    --color-fg-disabled: #57534E;

    --color-accent-default: #818CF8;
    --color-accent-hover: #A5B4FC;
    --color-accent-pressed: #C7D2FE;
    --color-accent-fg: #0C0A09;
    --color-accent-subtle: #312E81;
    --color-accent-ring: #4F46E5;

    --color-success-default: #4ADE80;
    --color-success-subtle: #14532D;
    --color-warning-default: #FBBF24;
    --color-warning-subtle: #78350F;
    --color-danger-default: #F87171;
    --color-danger-subtle: #7F1D1D;
    --color-info-default: #38BDF8;
    --color-info-subtle: #082F49;
}

@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: 40ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 40ms !important;
    }
}
```

---

## Appendix B — Status dot component (sample primitive)

```tsx
// src/components/ui/status-dot.tsx
import { cn } from '@/lib/cn';
import type { ComponentProps } from 'react';

type Status = 'ok' | 'warn' | 'error' | 'idle' | 'pending';

const statusClass: Record<Status, string> = {
    ok: 'bg-success-default',
    warn: 'bg-warning-default',
    error: 'bg-danger-default animate-pulse',
    idle: 'bg-fg-tertiary',
    pending: 'bg-info-default',
};

interface StatusDotProps extends ComponentProps<'span'> {
    status: Status;
    pulse?: boolean;
    'aria-label': string;       // mandatory for screen readers
}

export function StatusDot({ status, pulse, className, ...rest }: StatusDotProps) {
    return (
        <span
            role="status"
            className={cn(
                'inline-block h-2 w-2 rounded-full',
                statusClass[status],
                pulse && 'animate-pulse motion-reduce:animate-none',
                className,
            )}
            {...rest}
        />
    );
}
```

---

## Appendix C — Sample testid map (excerpt)

```
dashboard-kpi-servers
dashboard-kpi-calls
dashboard-kpi-latency
dashboard-kpi-breakers
dashboard-live-feed
dashboard-live-feed-row-{eventId}
dashboard-live-feed-pause
dashboard-live-feed-resume

servers-list
servers-list-empty
servers-list-error
servers-row-{id}
servers-row-{id}-action-handshake
servers-row-{id}-action-enable
servers-row-{id}-action-disable
servers-row-{id}-action-edit
servers-row-{id}-action-delete
servers-bulk-action-enable
servers-bulk-action-disable
servers-bulk-action-handshake
servers-bulk-action-delete
servers-create

server-form-step-1
server-form-step-2
server-form-step-3
server-form-name
server-form-name-error
server-form-transport-stdio
server-form-transport-http
server-form-transport-sse
server-form-submit
server-form-cancel
server-form-error-banner

server-detail-tab-overview
server-detail-tab-tools
server-detail-tab-handshakes
server-detail-tab-audit
server-detail-tab-config
server-detail-enable-toggle

tool-playground-form
tool-playground-form-field-{argName}
tool-playground-form-field-{argName}-error
tool-playground-invoke
tool-playground-response
tool-playground-response-status
tool-playground-response-json
tool-playground-response-audit-link

audit-list
audit-list-empty
audit-row-{id}
audit-filter-tenant
audit-filter-server
audit-filter-tool
audit-filter-status
audit-filter-daterange
audit-filter-search
audit-filter-save-view
audit-filter-clear
audit-drilldown
audit-drilldown-tab-request
audit-drilldown-tab-response
audit-drilldown-tab-error
audit-drilldown-tab-headers
audit-drilldown-tab-metadata
audit-drilldown-replay
audit-drilldown-permalink
audit-drilldown-close

cb-card-{tenant}-{server}-{tool}
cb-card-{tenant}-{server}-{tool}-state
cb-card-{tenant}-{server}-{tool}-reset

resources-tree-node-{uriHash}
resources-preview-pane
resources-preview-tab-rendered
resources-preview-tab-raw
resources-preview-tab-hex
resources-preview-copy
resources-preview-download

prompts-list-row-{promptName}
prompt-detail-arg-{argName}
prompt-detail-arg-{argName}-error
prompt-detail-preview
prompt-detail-preview-copy
prompt-detail-preview-send-to-playground

command-palette
command-palette-input
command-palette-item-{slug}
command-palette-empty

settings-preferences-theme
settings-preferences-density
settings-preferences-landing
settings-preferences-reduced-motion
settings-preferences-save
```

---

## Appendix D — `useSse` reference hook

```ts
// src/hooks/useSse.ts
import { useEffect, useRef } from 'react';

interface UseSseOptions<T> {
    url: string;
    enabled?: boolean;
    onEvent: (event: T) => void;
    onOpen?: () => void;
    onError?: (err: unknown) => void;
    onStale?: () => void;
    heartbeatTimeoutMs?: number;
}

export function useSse<T>({
    url,
    enabled = true,
    onEvent,
    onOpen,
    onError,
    onStale,
    heartbeatTimeoutMs = 45_000,
}: UseSseOptions<T>) {
    const esRef = useRef<EventSource | null>(null);
    const heartbeatRef = useRef<number | null>(null);

    useEffect(() => {
        if (!enabled) return;

        let cancelled = false;
        let backoff = 1000;

        const connect = () => {
            if (cancelled) return;

            const es = new EventSource(url, { withCredentials: true });
            esRef.current = es;

            const resetHeartbeat = () => {
                if (heartbeatRef.current) window.clearTimeout(heartbeatRef.current);
                heartbeatRef.current = window.setTimeout(() => onStale?.(), heartbeatTimeoutMs);
            };

            es.onopen = () => {
                backoff = 1000;
                resetHeartbeat();
                onOpen?.();
            };

            es.onmessage = (msg) => {
                resetHeartbeat();
                try {
                    const parsed = JSON.parse(msg.data) as T;
                    onEvent(parsed);
                } catch (err) {
                    onError?.(err);
                }
            };

            es.onerror = (err) => {
                onError?.(err);
                es.close();
                if (cancelled) return;
                window.setTimeout(connect, backoff);
                backoff = Math.min(backoff * 2, 30_000);
            };
        };

        connect();

        return () => {
            cancelled = true;
            if (heartbeatRef.current) window.clearTimeout(heartbeatRef.current);
            esRef.current?.close();
            esRef.current = null;
        };
    }, [url, enabled, onEvent, onOpen, onError, onStale, heartbeatTimeoutMs]);
}
```

---

## Appendix E — HTTP client wrapper

```ts
// src/lib/http.ts
import { getEnv } from './env';

class HttpError extends Error {
    constructor(
        public status: number,
        public payload: unknown,
        message: string,
    ) {
        super(message);
    }
}

let csrfPromise: Promise<void> | null = null;

async function ensureCsrf(): Promise<void> {
    if (csrfPromise) return csrfPromise;
    const { csrfCookieUrl } = getEnv();
    csrfPromise = fetch(csrfCookieUrl, { credentials: 'include' }).then(() => undefined);
    return csrfPromise;
}

function getXsrfFromCookie(): string {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

interface RequestInitExt extends RequestInit {
    json?: unknown;
}

export async function request<T>(path: string, init: RequestInitExt = {}): Promise<T> {
    const { apiBase } = getEnv();
    const method = (init.method ?? 'GET').toUpperCase();
    if (method !== 'GET' && method !== 'HEAD') {
        await ensureCsrf();
    }

    const headers = new Headers(init.headers);
    headers.set('Accept', 'application/json');
    if (init.json !== undefined) {
        headers.set('Content-Type', 'application/json');
    }
    if (method !== 'GET' && method !== 'HEAD') {
        headers.set('X-XSRF-TOKEN', getXsrfFromCookie());
    }

    const res = await fetch(`${apiBase}${path}`, {
        ...init,
        method,
        headers,
        credentials: 'include',
        body: init.json !== undefined ? JSON.stringify(init.json) : init.body,
    });

    const text = await res.text();
    const payload = text ? safeJsonParse(text) : null;

    if (!res.ok) {
        throw new HttpError(res.status, payload, extractMessage(payload, res.statusText));
    }

    return payload as T;
}

function safeJsonParse(s: string): unknown {
    try {
        return JSON.parse(s);
    } catch {
        return s;
    }
}

function extractMessage(payload: unknown, fallback: string): string {
    if (payload && typeof payload === 'object' && 'message' in payload && typeof (payload as { message: unknown }).message === 'string') {
        return (payload as { message: string }).message;
    }
    return fallback;
}

export const http = {
    get: <T>(path: string) => request<T>(path),
    post: <T>(path: string, json?: unknown) => request<T>(path, { method: 'POST', json }),
    patch: <T>(path: string, json?: unknown) => request<T>(path, { method: 'PATCH', json }),
    delete: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
};

export { HttpError };
```

---

**End of plan.**
