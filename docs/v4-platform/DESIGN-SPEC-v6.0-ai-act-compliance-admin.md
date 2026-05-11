# DESIGN SPEC — `padosoft/laravel-ai-act-compliance-admin` UX/UI

**Cycle:** v6.0
**Document type:** Design brief for Lorenzo to hand to Claude Design (or external UI designer)
**Audience:** UI designer producing mockups; component-implementation engineer following the mockups
**Companion engineering plan:** `docs/v4-platform/PLAN-v6.0-ai-act-compliance.md`
**Status:** SPEC — pre-design; mockups not yet produced

---

## 0. How to read this doc

This is a **UX/UI specification**, not an engineering plan. The companion `PLAN-v6.0-ai-act-compliance.md` covers what to ship, when, and how to wire it. This doc covers what the user sees, how interactions feel, and what a designer needs to produce mockups.

Each screen section follows the same structure:
- **Purpose** — what problem the screen solves for the user
- **Layout** — coarse grid + region structure
- **Components** — concrete widgets in the screen
- **Interactions** — primary user flows (the 2-5 actions a user actually performs on this screen)
- **States** — initial / loading / empty / error / success
- **Acceptance** — measurable design quality bars

---

## 1. Brand and positioning

### Tone

> **"Enterprise SaaS-grade compliance platform"** — should feel like a paid Vercel / Stripe / Linear-tier dashboard, NOT a Laravel admin.

The admin SPA is a marquee Padosoft product. It mounts inside any Laravel app, but its visual identity is **its own** — modern, minimal, polished. The host app's chrome (sidebar, top bar) should not bleed in; the admin SPA presents a self-contained branded surface inside `/admin/ai-act-compliance/*`.

### Visual references

Designer should browse before drafting:
- `https://github.com/vercel/ai-chatbot` — Vercel chatbot template (visual benchmark for component density + polish)
- `https://ui.shadcn.com/examples/dashboard` — shadcn dashboard example (KPI tile patterns + chart layouts)
- `https://stripe.com/dashboard` — Stripe Atlas / Connect dashboard (enterprise compliance pattern reference)
- `https://linear.app` — Linear (kanban board patterns + state-machine UIs)
- `https://vercel.com/dashboard` — Vercel dashboard (project switcher + KPI layout)

### Color palette

- **Light theme:** slate 50/100/200 backgrounds, slate 900/700/500 text, accent colour configurable per host (default: indigo 600)
- **Dark theme:** slate 950/900/800 backgrounds, slate 50/200/400 text, same accent
- **Severity / status colours** (consistent across screens):
  - Green 600 — success / closed / approved / low-risk / consent granted
  - Amber 500 — warning / triage / pending / limited-risk
  - Orange 600 — escalated / mitigating / high-risk
  - Red 600 — critical / unacceptable-risk / breach / consent revoked
  - Slate 500 — neutral / disabled / archived
- Brand colour is **neutral by default** (no hard-baked Padosoft brand) — host apps can theme the SPA to match their own brand via Tailwind theme tokens

### Typography

- shadcn defaults: Geist Sans (Vercel's open font) as default; system sans-serif stack fallback
- Font sizes follow Tailwind type scale: text-sm (body) / text-base (panel title) / text-lg (section heading) / text-2xl (page heading)
- No display fonts; no decorative typography

### Iconography

- Lucide icons (shadcn default) — consistent stroke weight, single visual language
- No emoji; no flag emojis for language indicators (use ISO 639 codes in small caps instead)

### Theme switcher

- Top bar toggle: light / dark / system (3-state)
- Persists per-user via `localStorage` + sync to BE `user_preferences.theme` on change
- Respects `prefers-color-scheme` media query for "system" mode

---

## 2. Information architecture

### Mount point

- Single SPA mounted at `/admin/ai-act-compliance` of host Laravel app
- 8 top-level routes (one per screen):
  - `/` — Compliance Overview
  - `/dsar` — DSAR Queue
  - `/consent` — Consent Overview
  - `/risks` — Risk Register Browser
  - `/incidents` — Incident Manager
  - `/bias` — Bias Monitor
  - `/dpo` — DPO Console
  - `/settings` — Settings

### Global chrome

**Sidebar (left, 240px collapsed to 64px icon-only):**
- AskMyDocs / host logo at top (configurable; defaults to host app's logo)
- Section: **Operations** (Overview / DSAR / Consent)
- Section: **Risk Management** (Risks / Incidents / Bias)
- Section: **Governance** (DPO / Settings)
- User block at bottom (avatar + name + role + logout)
- Collapsible via shadcn collapsible sidebar pattern
- Icon-only mode keeps tooltips on hover

**Top bar (full width, ~56px):**
- Hamburger toggle for sidebar
- Workspace switcher dropdown (multi-tenant — defaults to current tenant; super-admin can switch)
- Breadcrumb (Section / Screen / [optional sub-page])
- Global search (⌘K trigger; opens command palette)
- Theme toggle (sun / moon / monitor icon)
- Notification bell with badge count (unread DSAR / incidents / bias alerts)
- User avatar dropdown (Profile / Help / Logout)

**Page content area (right of sidebar, below top bar):**
- Each screen renders inside; responsive padding (px-6 desktop, px-4 tablet)

### Empty states (global pattern)

- Every list / grid / chart has a designed empty state with:
  - Illustration (custom SVG, single-colour line-art aesthetic — matches Linear's empty-state pattern)
  - Headline ("No DSAR requests yet")
  - Sub-text (1-2 sentences explaining the empty state)
  - CTA button (where applicable: "Configure DSAR settings" / "View documentation")

### Loading states (global pattern)

- Page-level: shadcn `Skeleton` placeholder matching final layout (NOT spinner)
- Inline: shadcn `Skeleton` for individual cards / chart panels
- Mutation pending: button shows spinner + disabled state; row highlights amber pulse
- Stream / long-running: progress bar at top of page (shadcn `Progress` component)

### Error states (global pattern)

- Network error: red banner at top of page with retry CTA
- 403 / unauthorised: full-page error with "Contact admin" CTA
- 422 validation: inline field errors per `<input>` with red text + icon
- 500 server: full-page error with "Try again" + "Report" CTAs

### Keyboard shortcuts

- `⌘K` / `Ctrl+K` — Command palette (navigate to any screen + execute common actions)
- `⌘B` / `Ctrl+B` — Toggle sidebar
- `?` — Show keyboard shortcut help dialog
- `/` — Focus search input on current screen (if applicable)
- `Esc` — Close modal / dialog / popover
- `Tab` / `Shift+Tab` — Standard focus navigation
- Screen-specific shortcuts documented in each screen section

---

## 3. Screen 1 — Compliance Overview (dashboard)

**Route:** `/admin/ai-act-compliance/`
**Default landing screen.**

### 3.1 Purpose

> Give the DPO / CISO / Compliance Officer a single-glance answer to: "is our AI compliance posture green right now?"

### 3.2 Layout

```
┌───────────────────────────────────────────────────────────────────┐
│  [Page header: "Compliance Overview" + last-refreshed timestamp]  │
├───────────────────────────────────────────────────────────────────┤
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐              │
│  │ KPI 1    │ │ KPI 2    │ │ KPI 3    │ │ KPI 4    │              │
│  │ DSAR     │ │ Incidents│ │ Consent  │ │ Bias     │              │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘              │
├───────────────────────────────────────────────────────────────────┤
│  ┌─────────────────────────┐  ┌──────────────────────────────┐    │
│  │  Recent activity        │  │  DSAR queue depth (30d)     │    │
│  │  (scrollable list)      │  │  (line chart)                │    │
│  │                         │  │                              │    │
│  └─────────────────────────┘  └──────────────────────────────┘    │
├───────────────────────────────────────────────────────────────────┤
│  ┌─────────────────────────────────────────────────────────────┐  │
│  │  Compliance attestation card                                │  │
│  │  Last DPO review: 23 days ago — [Generate next attestation] │  │
│  └─────────────────────────────────────────────────────────────┘  │
└───────────────────────────────────────────────────────────────────┘
```

### 3.3 Components

**KPI tile (4 tiles in row, equal width):**

| Tile | Primary metric | Secondary | Status colour |
|---|---|---|---|
| DSAR | Queue depth (number) | SLA breach % (last 30d) | green if depth = 0 + breach = 0; amber if depth > 0 + breach = 0; red if breach > 0 |
| Open Incidents | Total open count | Severity breakdown (mini-bars: low / med / high / critical) | green if 0; amber if > 0 (no critical); red if any critical |
| Consent Rate | % acceptance across features (30d avg) | Per-feature heatmap mini-spark (8 squares, last 8 features) | green > 90%; amber 70-90%; red < 70% |
| Bias Monitor | Worst cohort name + accuracy delta | Status traffic-light (green / amber / red) | mirrors traffic-light directly |

Each tile is a clickable card linking to the matching screen.

**Recent Activity panel (left, 50% width):**
- shadcn `ScrollArea` (vertical scroll; max-h-96)
- Each row: icon (event type) + headline (e.g. "DSAR opened by alice@example.com") + timestamp (relative: "3 minutes ago") + severity dot
- 30 most recent compliance events, oldest event timestamp at bottom
- "View all activity →" link at bottom routes to a filtered audit log view

**DSAR Queue Depth chart (right, 50% width):**
- recharts `LineChart` with x = day (last 30d), y = open DSAR count
- shaded threshold band at SLA target (e.g. 30 days)
- hover tooltip shows exact count + date
- empty state: "No DSAR activity in the last 30 days" with neutral illustration

**Compliance Attestation Card (full width):**
- shadcn `Card` with two regions:
  - Left: "Last DPO review:" + date + relative timestamp + status badge (green if < 90 days, amber 90-180d, red > 180d)
  - Right: CTA `<Button>` "Generate next attestation" — opens DPO Console attestation generator (Screen 7)

### 3.4 Interactions

- Click KPI tile → navigate to matching screen
- Click event row in Recent Activity → navigate to event detail (DSAR detail, incident detail, etc.)
- Click chart point → navigate to DSAR list filtered to that day
- Click "Generate next attestation" → navigate to Screen 7 attestation tab

### 3.5 States

- **Initial load** — show full Skeleton with same layout
- **Empty workspace** (brand new install, zero data) — show empty illustration in each region with "Configure your first DSAR / risk / consent" CTAs
- **All green** — KPI tiles all green; recent activity shows last 5 successful operations; chart flat near zero
- **Yellow alert** — one KPI amber; recent activity highlights the amber events at top
- **Red alert** — KPI red; full-width alert banner at top of page with red severity + "View details" CTA

### 3.6 Acceptance

- [ ] Loads in < 1.5s on cold cache (perceived performance)
- [ ] All 4 KPI tiles render without layout shift (reserved height)
- [ ] Chart hovering works on touch devices (tablet)
- [ ] Empty state is designed (not a default "no data" string)
- [ ] Real-time refresh: KPIs poll every 30s via TanStack Query (configurable per-tenant in Settings)

---

## 4. Screen 2 — DSAR Queue

**Route:** `/admin/ai-act-compliance/dsar`

### 4.1 Purpose

> Manage Data Subject Access Requests end-to-end: receive → triage → action → close, with GDPR Article 15+17 SLA tracking.

### 4.2 Layout

Resizable split-pane (drag-handle in middle):
- **Left (40% default):** paginated list of DSAR requests
- **Right (60% default):** detail panel of selected DSAR

### 4.3 Left — List

**Filter bar (top of list):**
- Status multi-select (Pending / In Progress / Completed / Rejected)
- Request type multi-select (Export / Delete / Rectify)
- User search (typeahead by email or name)
- Date range picker (request date)
- "Clear filters" button when any filter active

**List table:**
- Columns: User (avatar + email) / Type (badge) / Status (badge) / SLA Deadline (red if breached, amber within 5d, green) / Age (relative) / Actions (... menu)
- Sortable by SLA deadline (default), age, status
- Row click → load into right detail panel
- Bulk-action support: checkbox in each row + "Bulk actions" dropdown when any selected (Mark in progress / Mark completed / Export selected metadata)
- Pagination at bottom (shadcn `Pagination`); 20 rows/page default

**Empty state:**
- Illustration: stylized inbox
- Headline: "No DSAR requests"
- Sub-text: "Data Subject Access Requests will appear here when users invoke their GDPR rights."
- CTA: "Configure DSAR settings" → Settings screen

### 4.4 Right — Detail

**Header:**
- Avatar + user name + email + tenant
- Request type badge (Export / Delete / Rectify)
- SLA progress bar (visual: bar fills as days elapse against 30-day target; red when breached)
- Status badge (large)

**Timeline tab (default):**
- Vertical event timeline (shadcn `Timeline`)
- Events: created → triage assigned → in progress started → export-job dispatched → export-job completed → user-notified → completed
- Each event: icon + label + timestamp + actor (system / user)

**Requested Data Scope tab:**
- Tree view of data domains the request covers (e.g. Conversations / Chat Logs / KB Activity / Connector Installations)
- For each: row count + last accessed + retention policy
- "Preview data export" button → opens dialog with JSON tree of what export would contain

**Export tab (for type=Export):**
- "Generate export" button when status = pending → enqueues `ExportUserDataJob`
- Progress bar during job execution
- Download ZIP CTA when complete (file name: `dsar-export-{user-id}-{date}.zip`)
- Audit trail: "Exported by [admin] at [timestamp]"

**Delete Preview tab (for type=Delete):**
- Cascade preview: shadcn `Tree` showing what tables / rows would be affected
- "Confirm delete" requires typing the user's email to confirm (R21 security invariant — atomic destructive action)
- Audit trail: "Deleted by [admin] at [timestamp]" with row-count summary

**Comments tab:**
- Thread of comments by admins (internal — not visible to data subject)
- "Add comment" textarea + post button

**Actions row (bottom):**
- Approve / Reject / Mark in progress / Mark completed (shadcn `Button` variants — primary / destructive / secondary / success)
- Each action requires confirmation dialog

### 4.5 Interactions

1. **Receive DSAR** — request appears at top of list (newest first by SLA urgency)
2. **Triage** — admin clicks row → sees scope + assigns to themselves → status moves to In Progress
3. **Execute Export** — admin clicks "Generate export" → job runs → ZIP available
4. **Execute Delete** — admin previews cascade → types email to confirm → cascade fires
5. **Close** — admin marks Completed → user notified via email (configurable in Settings) → row moves to Completed status

### 4.6 States

- **Empty (no DSAR)** — full empty state in list panel
- **Pending DSAR exists** — list shows pending row(s); detail panel empty until row clicked
- **DSAR breached SLA** — list row pulses red; detail SLA bar full red; banner at top of detail "SLA breached — escalate to DPO"
- **Export job running** — progress bar in detail; status "In Progress"
- **Delete confirmation** — dialog blocks rest of UI; cannot dismiss without explicit cancel

### 4.7 Acceptance

- [ ] List paginates smoothly (no jank on page change)
- [ ] Detail panel resizable + state persists per user (split position)
- [ ] Bulk actions confirm-dialog before firing
- [ ] Delete cascade preview shows EXACT row counts (R14 surface failures loudly — no silent zero)
- [ ] Export ZIP download triggers proper Content-Disposition header
- [ ] SLA breach triggers email to DPO (configurable threshold in Settings)
- [ ] Keyboard nav: J/K to move list selection; Enter to focus detail; Esc to close detail

---

## 5. Screen 3 — Consent Overview

**Route:** `/admin/ai-act-compliance/consent`

### 5.1 Purpose

> Track per-user / per-feature consent state; demonstrate consent provenance to auditors; identify consent gaps.

### 5.2 Layout

Top tabs (shadcn `Tabs`):
- **Per User** (default)
- **Per Feature**

### 5.3 Per User tab

**Left — Searchable user list:**
- Search input at top (typeahead by email / name)
- Filter chips: "All consents" / "Any revocation" / "Missing required consents"
- List rows: avatar + name + email + "X / Y consents" badge (shows current state)
- Click row → load into right detail

**Right — User consent detail:**
- Header: user info + last consent activity timestamp
- Consent matrix table:
  - Rows: features (configurable list per tenant — Chat Use / KB Ingest / Eval Inclusion / etc.)
  - Columns: Current State (Granted / Revoked / Never) + Last Updated + Source (signup form / settings panel / API call)
- Per-row "View history" expandable shows full audit log of state changes
- "Revoke all" destructive button (with confirmation)
- "Export consent record" CTA (PDF) — for handing to user if requested

### 5.4 Per Feature tab

**Top — Feature selector:**
- Dropdown of features
- Stats card row when feature selected:
  - Total users with consent state
  - Acceptance rate (gauge — recharts `RadialBarChart`)
  - Revocation rate (last 30d)

**Charts:**
- Acceptance rate trend (line chart, 90d)
- Revocation timeline (bar chart, daily counts of revocations)
- Opt-out users list (right panel) — paginated list of users who revoked, with revocation reason if captured

**Empty state:**
- "No features configured yet" + CTA "Add a consentable feature" → Settings

### 5.5 Interactions

1. Admin searches a user → views their consent matrix → audits state changes
2. DPO inspects feature acceptance rate trend → identifies dropping consent (regulatory signal)
3. Auditor exports consent record for specific user → receives PDF

### 5.6 Acceptance

- [ ] Per-user matrix shows EVERY configured feature for that tenant (no silent omissions — R14)
- [ ] Audit log for each consent state change is immutable + timestamped + actor-attributed
- [ ] PDF export contains everything in matrix + full audit history
- [ ] Bulk revocation requires multi-step confirmation
- [ ] Feature acceptance rate chart handles zero-data day gracefully (no NaN / -Infinity — R14)

---

## 6. Screen 4 — Risk Register Browser

**Route:** `/admin/ai-act-compliance/risks`

### 6.1 Purpose

> Inventory all identified AI risks per AI Act risk categorisation; track mitigation status; assign ownership; demonstrate regulatory due-diligence.

### 6.2 Layout

```
┌───────────────────────────────────────────────────────────────────┐
│  [Page header: "Risk Register" + total count + "+ New risk"]      │
├──────────────┬────────────────────────────────────────────────────┤
│ Filter side  │  Card grid (3 cols desktop / 2 tablet / 1 mobile)  │
│ ─────────────│  ┌───────┐ ┌───────┐ ┌───────┐                     │
│ Category     │  │ Risk  │ │ Risk  │ │ Risk  │                     │
│ ☐ Low        │  │ card  │ │ card  │ │ card  │                     │
│ ☐ Limited    │  └───────┘ └───────┘ └───────┘                     │
│ ☐ High       │  ┌───────┐ ┌───────┐ ┌───────┐                     │
│ ☐ Unacceptable│ │ Risk  │ │ Risk  │ │ Risk  │                     │
│              │  │ card  │ │ card  │ │ card  │                     │
│ Status       │  └───────┘ └───────┘ └───────┘                     │
│ ☐ Open       │                                                    │
│ ☐ In Progress│                                                    │
│ ☐ Closed     │                                                    │
│              │                                                    │
│ Owner        │                                                    │
│ [Avatar list]│                                                    │
└──────────────┴────────────────────────────────────────────────────┘
```

### 6.3 Components

**Filter sidebar (left, 240px collapsible):**
- Category checkboxes (4 — low / limited / high / unacceptable per AI Act)
- Status checkboxes (3 — open / in-progress / closed)
- Owner picker (avatar grid; multi-select)
- AI Act article reference text input (searches by article number, e.g. "Article 9" for high-risk requirements)
- "Clear filters" button

**Risk card (shadcn `Card`):**
- Header row: category badge (color-coded per severity palette) + status badge
- Risk name (text-lg)
- 2-line description preview (text-sm, truncated)
- Footer row: owner avatar + last-reviewed relative timestamp + "View →" link
- Hover state: subtle shadow elevation
- Click card → navigate to detail page `/risks/:id`

**Detail page (full screen replace):**
- Breadcrumb: Risks / [risk name]
- 2-column layout:
  - **Left (66%):** Full description (rich-text editor in edit mode, markdown rendering in view mode) + mitigations history (timeline) + linked incidents (list)
  - **Right (33%):** Metadata card (category / status / owner / AI Act articles / created / last-reviewed) + Ownership transfer CTA + Reviewer signatures (chronological list)
- Action row: Edit / Transfer ownership / Mark mitigated / Mark closed / Delete (destructive, super-admin only)

### 6.4 Interactions

1. **Inventory risks** — DPO browses cards, filters by category, identifies gaps
2. **Add new risk** — "+ New risk" → opens dialog with form (name, category, description, owner, AI Act articles)
3. **Review risk** — Admin opens card → reviews mitigations → adds reviewer signature
4. **Mitigate** — Owner adds mitigation entry (text + date) → status moves to In Progress → eventually Mitigated → Closed
5. **Transfer** — Current owner clicks Transfer → picks new owner → audit trail entry

### 6.5 Acceptance

- [ ] Card grid responsive (3/2/1 cols at breakpoints)
- [ ] Filter sidebar collapsible; state persists per user
- [ ] Detail view markdown editor accessible via keyboard
- [ ] Reviewer signatures are immutable (append-only)
- [ ] AI Act article references link to the official AI Act text (external link with proper noopener)

---

## 7. Screen 5 — Incident Manager

**Route:** `/admin/ai-act-compliance/incidents`

### 7.1 Purpose

> Manage AI incidents from open → triage → mitigation → close with state-machine enforcement, escalation routing, and post-mortem capture.

### 7.2 Layout

**Kanban board** (4 columns, full width):

```
┌──────────────┬──────────────┬──────────────┬──────────────┐
│   OPEN       │   TRIAGE     │  MITIGATING  │   CLOSED     │
│   (3)        │   (2)        │   (1)        │   (47)       │
├──────────────┼──────────────┼──────────────┼──────────────┤
│ [Card]       │ [Card]       │ [Card]       │ [Card]       │
│ [Card]       │ [Card]       │              │ [Card]       │
│ [Card]       │              │              │   ...        │
└──────────────┴──────────────┴──────────────┴──────────────┘
```

### 7.3 Components

**Column header:**
- State name + count badge
- Sort dropdown (Severity desc / Age desc / Last update)

**Incident card:**
- Severity icon + colour border (left edge): red critical / orange high / amber medium / slate low
- Title (text-base, bold, 2-line truncate)
- Age (relative timestamp)
- Assignee avatar
- Affected-users count badge ("👥 124 users" — but use icon not emoji)
- Linked-DSAR badge if applicable
- Click → opens detail in side sheet (shadcn `Sheet`)
- Drag handle: dragging card moves it to another lane (with state-machine validation — invalid transitions reject with toast)

**Side sheet (slides in from right, 50% width on desktop, full on mobile):**

Detail tabs:
- **Overview** — title + description + severity + status + assignee + reporter + created/updated + tags
- **Timeline** — full state transition history with actor + timestamp + reason for each transition
- **Escalation Routing** — visual tree showing who gets notified at each severity level (configurable per tenant in Settings)
- **Affected Users** — paginated list of users impacted; click user → DSAR for that user (deep link)
- **Mitigation Log** — append-only log of mitigation actions; admin adds entry with text + outcome
- **Post-Mortem** — template generator (visible when status = Closed); generates markdown skeleton with sections (What happened / Impact / Root cause / Mitigation / Lessons learned); admin fills in; can export as PDF
- **Related** — risks linked to this incident (from Risk Register); manual or auto-suggested

**Actions:**
- Move to next state (validated by state machine)
- Assign / reassign
- Escalate to DPO (overrides routing)
- Link related incident / risk / DSAR
- Delete (super-admin + confirmation)

### 7.4 Interactions

1. **Triage** — Incident opens → routes to on-call via escalation tree → on-call drags card from Open to Triage → assigns themselves
2. **Mitigate** — On-call adds mitigation entry → drags to Mitigating → continues adding entries
3. **Close** — Mitigation effective → drags to Closed → generates post-mortem from template → saves
4. **Reopen** — Closed incident can be reopened with reason (state machine allows reverse transition with audit trail)

### 7.5 States

- **No incidents** — all columns show empty state ("No open incidents — your AI system is healthy!")
- **Critical incident open** — full-width red banner at top of page with "1 critical incident — view now"
- **Card being dragged** — drop zones highlight; invalid transitions show red overlay
- **State transition rejected** — toast with reason ("Cannot move from Closed to Open without reopen reason")

### 7.6 Acceptance

- [ ] Drag-and-drop works on touch devices
- [ ] State-machine transitions enforced (UI matches BE state machine; no client-only validation)
- [ ] Post-mortem template PDF export is well-formatted (auditor-ready)
- [ ] Escalation routing tree is editable per tenant in Settings (Screen 8)
- [ ] Notification firing visible in audit log

---

## 8. Screen 6 — Bias Monitor

**Route:** `/admin/ai-act-compliance/bias`

### 8.1 Purpose

> Surface AI model bias / cohort parity issues; track drift over time; alert when thresholds breached; provide sample inspector for forensic review.

### 8.2 Layout

```
┌───────────────────────────────────────────────────────────────────┐
│  [Page header: "Bias Monitor"]                                    │
├───────────────────────────────────────────────────────────────────┤
│  Cohort selector (top row, full width):                           │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐              │
│  │ Language │ │ Source   │ │ Canonical│ │ Demographic (custom)    │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘              │
├───────────────────────────────────────────────────────────────────┤
│  Chart row (3 panels):                                            │
│  ┌─────────────────┐ ┌─────────────────┐ ┌─────────────────┐      │
│  │ Accuracy parity │ │ Drift over time │ │ Sample inspector│      │
│  │ (bar chart per  │ │ (line chart per │ │ (paginated list │      │
│  │  cohort)        │ │  cohort, 90d)   │ │  of samples)    │      │
│  └─────────────────┘ └─────────────────┘ └─────────────────┘      │
├───────────────────────────────────────────────────────────────────┤
│  Alert configuration panel (collapsible):                         │
│  Set thresholds per cohort that trigger Log::alert + Incident     │
└───────────────────────────────────────────────────────────────────┘
```

### 8.3 Components

**Cohort selector:**
- Tab buttons for each cohort dimension (configurable per tenant)
- Selected cohort drives all 3 chart panels below

**Accuracy parity chart (recharts `BarChart`):**
- X axis: cohort segments (e.g. "EN" / "IT" / "DE" / "FR")
- Y axis: accuracy (or whatever the metric is — configurable per `CohortParityMetric` impl)
- Bars colored by deviation from overall mean (green within tolerance, amber moderate, red severe)
- Statistical significance markers (⚠️ next to bars where confidence interval doesn't overlap mean)
- Hover tooltip shows: cohort name + sample count + accuracy + 95% CI

**Drift over time chart (recharts `LineChart`):**
- X axis: date (last 90d)
- Y axis: metric value
- One line per cohort segment
- Brushable: drag to zoom into date range
- Alert threshold band shaded (configured in collapsible below)

**Sample inspector (right panel, scrollable):**
- Filtered to current cohort selection
- List of misclassified / flagged samples
- Each row: sample preview text (3-line truncate) + cohort label + actual vs expected + click → expanded view with full sample text + decision trace

**Alert configuration panel (collapsed by default):**
- Per-cohort threshold form: accuracy delta % (when cohort deviates more than X% from overall mean for Y days) → trigger
- Trigger options: Log::alert / Email DPO / Create incident (links into Screen 5)
- Save button + audit trail entry on change

### 8.4 Interactions

1. **Browse** — DPO selects "Language" cohort → sees IT trailing EN by 8% accuracy → red bar + significance marker
2. **Investigate** — Clicks IT bar → sample inspector filters to IT misclassifications → reviews specific samples
3. **Track drift** — Watches line chart over 90d → drift trend identified → adjusts threshold
4. **Configure alert** — Opens alert panel → sets 5% threshold over 7d → saves → alert fires automatically on next cohort run

### 8.5 States

- **No cohort data** — empty illustration "Run eval-harness to populate cohort data" + CTA link to eval-harness UI
- **All cohorts within tolerance** — green bars across; line chart flat; no alert
- **Cohort drift detected** — red bar + significance marker + line chart shows divergence; banner "Drift detected in cohort X" with "Investigate" CTA
- **Sample loading** — sample inspector shows Skeleton

### 8.6 Acceptance

- [ ] Charts handle empty cohort gracefully (no NaN / -Infinity — R14)
- [ ] Statistical significance markers correctly computed (95% CI default; configurable)
- [ ] Alert threshold changes trigger audit trail entry
- [ ] Sample inspector hides PII (uses pii-redactor output) when consent missing
- [ ] Drift chart brush state persists per user

---

## 9. Screen 7 — DPO Console

**Route:** `/admin/ai-act-compliance/dpo`

### 9.1 Purpose

> Single-pane workspace for the Data Protection Officer: retention review + deletion audit + consent forensics + data-flow map + attestation generator for regulator-ready exports.

### 9.2 Layout

Dashboard with 5 cards in 2-column grid (responsive to 1 col on mobile):

```
┌─────────────────────────────┬─────────────────────────────┐
│  Retention Policy Review    │  Deletion Log               │
│                             │                             │
├─────────────────────────────┼─────────────────────────────┤
│  Consent Revocation Audit   │  Data Flow Map              │
│                             │                             │
├─────────────────────────────┴─────────────────────────────┤
│  Compliance Attestation Generator (full width)            │
│                                                           │
└───────────────────────────────────────────────────────────┘
```

### 9.3 Components

**Retention Policy Review card:**
- Table: per-data-type retention window (Chat Logs / Conversations / KB Audit / Connector Audit / MCP Tool Audit / Insights Snapshots)
- Each row: current retention (days) + last-reviewed date + reviewer
- "Extend" / "Reduce" CTAs trigger inline edit with audit-trail comment
- "Mark all reviewed" bulk-action button

**Deletion Log card:**
- Searchable log: table of all hard deletes (linked to DSAR or auto-prune from `kb:prune-deleted`)
- Columns: when / what (model name + row id) / cause (DSAR / auto-prune / manual) / actor
- Filterable by date range + cause + actor
- "Export CSV" button for full log
- Click row → modal with full deletion context (what was deleted, before-state if available)

**Consent Revocation Audit card:**
- Stats row: revocations per period (7d / 30d / 90d / all)
- Cause analysis: pie chart of revocation reasons (when captured)
- Recent revocations list (last 10) with user + feature + reason + timestamp
- "View all" → Screen 3 filtered to revocations

**Data Flow Map card:**
- Visual diagram (D3 or recharts `Sankey`) of data sources → AskMyDocs → derived data
- Nodes: data sources (KB ingest / connectors / chat / MCP tools) → AskMyDocs core → outputs (chat responses / evals / audits / attestations)
- Edge labels: data type + transformation applied (e.g. "PII-redacted" / "Embedded" / "Logged")
- Click node → drawer with detail (volume, retention, processors)
- Useful for GDPR Article 30 records-of-processing demonstrations

**Compliance Attestation Generator (full-width card):**
- Form: attestation type (SOC 2 / ISO 27001 / ISO 42001 / GDPR Article 30 / AI Act self-assessment / Custom)
- Period selector (start date + end date)
- Sections to include checkboxes (Risk Register / Incident History / DSAR Activity / Consent Activity / Bias Monitor / Audit Log)
- "Generate PDF" button → enqueues `GenerateAttestationJob` → PDF download CTA when ready
- Recent attestations list at bottom (last 10) with download links

### 9.4 Interactions

1. **Quarterly review** — DPO opens screen → reviews retention policy → marks reviewed → generates quarterly attestation PDF
2. **Audit response** — Regulator asks for records of processing → DPO opens Data Flow Map → exports as PNG + Article 30 attestation
3. **Investigate revocation spike** — Cause analysis pie shows "vague privacy policy" → DPO triggers UX fix workflow
4. **Retention reduction** — DPO determines chat-log retention should drop 90d → 30d → updates → trail entry → effective on next prune

### 9.5 Acceptance

- [ ] Retention edits write audit trail with old + new values + reason
- [ ] Attestation PDF is auditor-ready (signed + dated + scoped) — passes external audit-firm template check
- [ ] Data Flow Map exports as high-DPI PNG suitable for printed Article 30 records
- [ ] Deletion log filterable + CSV export complete
- [ ] All historical state (retention reviews, revocations, deletions) is immutable

---

## 10. Screen 8 — Settings

**Route:** `/admin/ai-act-compliance/settings`

### 10.1 Purpose

> Admin configuration surface for the compliance package — read-only env knobs, feature flags, role/permission matrix, thresholds, and webhook routing.

### 10.2 Layout

Vertical-tab layout (left tab list + right content panel):

- Env Vars (read-only)
- Feature Flags
- Admin Roles
- Bias Thresholds
- DSAR SLA
- Webhook Config
- Notifications

### 10.3 Sections

**Env Vars (read-only):**
- Table: env var name + current value (masked for secrets) + source (env file / config / default)
- Filter by module
- Each row tooltip explains what the var controls + link to docs
- No edit affordance — make clear these are immutable from this UI

**Feature Flags:**
- Per-tenant toggleable features (Disclosure / Risk Register / DSAR / Consent / Bias Monitor / etc.)
- Toggle changes write audit trail
- "Enable all" / "Disable all" bulk actions (super-admin only)

**Admin Roles:**
- Spatie role + permission matrix
- Rows: roles (DPO / Compliance Officer / Admin / Auditor)
- Columns: permissions (view_dsar / approve_dsar / generate_attestation / configure_thresholds / etc.)
- Checkboxes per cell; super-admin only edits
- Changes write audit trail entry

**Bias Thresholds:**
- Per-cohort dimension threshold config (links to Screen 6 alert panel — same data)
- Default thresholds documented + reset-to-default button

**DSAR SLA:**
- Per-tenant DSAR SLA target (days; default 30 per GDPR Article 15+17)
- Warning threshold (days before breach to alert; default 5)
- Recipients for breach alert (admin + DPO + optional escalation)

**Webhook Config:**
- Outgoing webhooks for events (DSAR opened / DSAR breach / Incident escalated / Bias drift detected / etc.)
- URL + secret + retry policy + event subscription checkboxes
- Test webhook button (sends dummy payload + shows response)

**Notifications:**
- Email config (SMTP from address + reply-to + signature footer)
- Slack integration (workspace + channel + bot token — Pro feature, link to upgrade if OS tier)
- Teams integration (Pro feature)
- Per-event email templates (editable rich-text with variable substitution)

### 10.4 Interactions

1. Super-admin configures DSAR SLA for new tenant
2. Admin reviews env vars to verify production config matches expected
3. Compliance Officer adjusts bias thresholds based on Screen 6 observations
4. Super-admin adds webhook for incident-escalated events

### 10.5 Acceptance

- [ ] Every settings change writes audit trail entry with actor + before + after
- [ ] Read-only env vars cannot be edited via UI (no inline-edit affordance present)
- [ ] Secrets are masked in display (e.g. `*****abc` showing last 3 chars)
- [ ] Webhook test button shows full HTTP request/response for debugging
- [ ] Role matrix changes require multi-step confirmation
- [ ] Form-validation errors render inline per field (no full-page reload)

---

## 11. Cross-cutting components

### 11.1 Toast notifications

- shadcn `sonner` toast library
- Non-blocking; positioned top-right by default
- 4 variants: success (green) / info (slate) / warning (amber) / error (red)
- Action buttons in toast (e.g. "Undo" after delete) with 5-second window
- Programmatic dismiss + auto-dismiss after 5s (configurable per toast)

### 11.2 Modal dialogs

- shadcn `Dialog`
- Used for: confirmation of destructive actions / multi-step wizards / forms that need full focus
- Always include explicit Cancel + Confirm buttons
- Esc closes (unless mid-wizard with unsaved changes — warn first)
- Focus trap when open + return focus to trigger on close

### 11.3 Confirm dialogs for destructive actions

- Specialised dialog variant for delete / revoke / bulk-action
- Requires typing confirmation token (e.g. user email for DSAR delete; risk name for risk delete)
- Destructive primary button (red); "Cancel" secondary
- Cannot be dismissed without explicit choice (no clickaway close)

### 11.4 Loading skeletons

- shadcn `Skeleton` matching final-state layout precisely (avoid CLS — Cumulative Layout Shift)
- Distinct skeleton per major component (KPI tile / chart / table row / form field)
- Animated pulse (Tailwind default)

### 11.5 Error boundaries

- React error boundary at each screen root
- Fallback UI: friendly error with "Try again" CTA + "Report this" link to incident manager
- Error logged to BE via `/api/admin/ai-act-compliance/client-error` endpoint (rate-limited)

### 11.6 ⌘K command palette

- shadcn `Command` (kbar-equivalent)
- Triggered by ⌘K / Ctrl+K
- Sections:
  - Navigate (to any screen)
  - Recent (last 5 screens visited)
  - Actions (Create new risk / New DSAR / Generate attestation / etc.)
  - Settings (jump to any settings section)
- Fuzzy search across all entries
- Keyboard nav: arrow up/down + enter

---

## 12. Accessibility requirements

- **WCAG 2.1 AA compliance** — required for European AI Act + general accessibility standards
- **Keyboard navigation:** every action reachable via keyboard; no keyboard traps; visible focus rings
- **Screen reader:** semantic HTML (`<button>`, `<nav>`, `<main>`, `<aside>` etc.); `aria-label` on icon-only buttons; `aria-describedby` for form errors; `aria-live` for toast notifications
- **Focus management:** modal open → focus moves to dialog; modal close → focus returns to trigger; route change → focus moves to main heading
- **Color-blind safety:** never rely on color alone — pair with icon / text. Severity uses colour + icon (red + 🛑 wait, no — colour + lucide `AlertOctagon`, amber + lucide `AlertTriangle`, green + lucide `CheckCircle2`)
- **Text alternatives:** every icon has accessible name; every image has alt text
- **Contrast ratios:** 4.5:1 for normal text, 3:1 for large text — verified via axe-core test in CI
- **Touch targets:** minimum 44×44px (mobile accessibility)
- **Motion:** respects `prefers-reduced-motion` — disables transitions / framer-motion when set

Compliance with R15 (frontend a11y checklist) is REQUIRED per project rules.

---

## 13. Responsive design

| Viewport | Behaviour |
|---|---|
| **Desktop (≥ 1280px)** | Primary target. Full layout — sidebar + top bar + multi-column dashboards. Compliance admin work happens here. |
| **Tablet (768px – 1279px)** | Read-only and review actions supported. Layouts reflow to 2 cols where 3 used on desktop. Resizable panes lock to 50/50 default. |
| **Mobile (< 768px)** | Emergency view only — Incident Manager + DSAR Queue list view. Other screens show "Best viewed on desktop" notice but allow read access. No bulk actions on mobile. |

Sidebar collapses to icon-only at < 1024px; bottom-sheet drawer at < 768px.

---

## 14. Visual design deliverables for the designer

The designer producing mockups from this brief should deliver, per screen:

1. **Wireframe** — low-fidelity layout (Figma frame)
2. **Visual design** — high-fidelity light + dark theme variants
3. **Component states** — empty / loading / populated / error / mutation pending per major component
4. **Interaction prototypes** — Figma protos for critical flows (DSAR triage → execute → close; Incident open → triage → mitigate → close; Risk add → review → mitigate)
5. **Component spec** — Tailwind class hints + shadcn component references for handoff to implementation
6. **Asset export** — illustrations (empty states + error states + onboarding) as SVG; icons sourced from Lucide

All mockups in Figma, branded as "Padosoft / laravel-ai-act-compliance-admin / v1.0.0", named per the screen (e.g. "Screen 1 — Compliance Overview — Light"). Designer should add comments explaining non-obvious choices.

---

## 15. Open design questions for designer

Items deliberately left open for the designer to propose:

1. **Custom illustration style** — line-art vs filled vs gradient? Brief recommendation: minimal single-colour line-art (Linear-style) for empty states; saturated illustrations for major flows (DSAR triage / attestation).
2. **Data Flow Map visual** — Sankey diagram vs network graph vs custom org-chart-style? Recommendation: Sankey for volume flows; network graph for relationships. Designer picks.
3. **Compliance attestation PDF template** — auditor-ready format; needs branded cover page + sections + signature lines + appendix. Designer produces PDF template (not just web view).
4. **Mobile incident manager** — single-column scroll vs swipeable lanes? Recommendation: swipeable lanes match the desktop kanban metaphor.
5. **Theme tokens** — how aggressive should the "configurable per host" tokens be? Recommendation: keep brand colour configurable; lock everything else to shadcn defaults so the SPA always looks like a Padosoft product (not host-branded).
6. **Onboarding flow** — empty workspace first-time user — should there be a guided tour or just empty states with CTAs? Recommendation: empty states + a single "Get started" dismissable banner on Overview screen.

---

## 16. Cross-references

- `docs/v4-platform/PLAN-v6.0-ai-act-compliance.md` — companion engineering plan
- `docs/v4-platform/AUDIT-2026-05-11-competitor-comparison.md` — §3.3 PII redaction moat (compliance positioning)
- `padosoft/laravel-pii-redactor-admin` — v4.4/W2 cross-mount precedent (similar SPA architecture)
- `padosoft/eval-harness-ui` — v4.4/W3 cross-mount precedent (TanStack Router + BrowserRouter basename pattern)
- `memory:feedback_v45_strategic_roadmap` — Lorenzo directive: React + Vercel/shadcn NOT Filament/Livewire
- `memory:feedback_open_source_readme_quality` — README WOW pattern requirement
- AI Act regulatory references — full text + per-section article mapping documented in the compliance package READMEs
- `.claude/skills/frontend-a11y-checklist/` — R15
- `.claude/skills/frontend-testid-conventions/` — R11
- `.claude/skills/playwright-e2e/` — R12

---

## 17. Sign-off

This design spec was prepared on 2026-05-11 as a companion artefact to `PLAN-v6.0-ai-act-compliance.md`. Lorenzo will hand this document to Claude Design (or an external UI designer) once the v6.0 cycle kicks off (post-v5.0 GA). The designer produces the Figma mockup deliverables (§14); the engineer implementing Screens 1-8 follows those mockups + this spec.

**Status:** SPEC — pre-design.
