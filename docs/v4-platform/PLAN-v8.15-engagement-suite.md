# v8.15 — KB Engagement & Intelligence Suite (digests + dashboards + gamification)

## Context

Lorenzo wants a knowledge-base engagement system that **surpasses Stack Overflow
for Teams, Zendesk and Notion**: proactive digests (obsolete docs, stale docs
needing review, newly created/modified/promoted docs, top unanswered questions)
delivered via Discord/Slack/email as a weekly roll-up, **plus** an admin dashboard
and a **new user dashboard** with useful + "wow"/aesthetic KB metrics, and those
metrics woven into the digest to make it compelling.

The attached Stack Overflow "Surface Digest" is deliberately thin — "low activity
this week", a list of top unanswered questions, "accept an answer", "getting
started". AskMyDocs already has **far richer raw material**; the gap is packaging,
delivery breadth, a user-facing surface, and engagement/contributor intelligence.

**What already exists (REUSE, do not rebuild):**
- **Notifications** (ADR 0012): `NotificationEvent`, `NotificationPreference`,
  `NotificationDigest`, `NotificationTenantDefault`; `ChannelRegistry` + 6 adapters
  (`InAppChannel`/`EmailChannel`/`DiscordChannel`/`SlackChannel`/`TeamsChannel`/`WebhookChannel`);
  `NotificationSubjects`/`NotificationSummaries`; bell UI + preferences grid; HMAC
  unsubscribe. 7 event types incl. `kb_doc_created`/`kb_doc_modified`/
  `kb_canonical_promoted`/`kb_doc_stale_review`.
- **Weekly digest**: `NotificationsDigestWeeklyCommand` + `WeeklyDigestMail` +
  `resources/views/emails/weekly-digest.blade.php` — but **email-only**, plain
  (group-by-event-type counts + sample titles), no metrics, no channel cards.
- **KB lifecycle intelligence** (ADR 0013): `KbHealthService` (0–100 score, 5
  factors) + `kb_canonical_health_snapshot` + `kb:health-recompute`;
  `KbStaleReviewSweepCommand`; content gaps via `kb_search_failures` +
  `SearchFailureRecorder` + `KbContentGapController`; decision-debt threshold.
- **AI insights**: `AiInsightsService` (promotions/orphans/tags/gaps/stale/quality)
  + `AdminInsightsSnapshot` + `insights:compute`.
- **Admin dashboard**: `AdminMetricsService` + `DashboardMetricsController`
  (overview/series/health), recharts charts, `KpiCard`/`KpiStrip`/`ChartCard`/
  `HealthStrip`, `nav-config.ts`, `RequireRole`. **No user-facing dashboard exists.**

## Decisions (locked with Lorenzo)
1. **Analytics + tasteful, opt-in gamification** (config-gated): contribution
   scores, badges, streaks, leaderboard, "your impact = times your docs were cited".
2. **All four digest modes**: per-user personalized email · team digest to
   Discord/Slack/Teams · in-app digest feed · monthly executive roll-up.
3. **AI narrative default-ON, config-gated** (R43 test both states) with a
   **dedicated model override** for this task (`KB_DIGEST_AI_PROVIDER` /
   `KB_DIGEST_AI_MODEL`, mirroring the `KB_AUTOWIKI_AI_*` pattern). **Default
   model = a free OpenRouter model** (`KB_DIGEST_AI_PROVIDER=openrouter` +
   `KB_DIGEST_AI_MODEL=<a `:free` Llama/Qwen variant>`) — digests are summary prose,
   not latency/quality-critical, so a free tier is sufficient and ≈$0 cost. Exact
   `:free` model confirmed at impl-time (OpenRouter's free roster shifts).
4. **Gamification badge catalog + leaderboard are per-tenant configurable**
   (tenants enable/define badges; leaderboard scoped per tenant). Default-off
   globally via `KB_GAMIFICATION_ENABLED` (R43).
5. **Release vehicle: `feature/v8.15` cycle**, weekly Wn sub-branches + RC tags
   per R37/R39 (latest GA is v8.13.0; v8.14 is in flight — see coordination).

## STATUS: in flight (W1 shipped)
Resolved open items: badges = per-tenant configurable; digest model = free
OpenRouter. Still to confirm: v8.14 scope (collision surface) + the exact `:free`
OpenRouter model id for `KB_DIGEST_AI_MODEL` (W2).

## ⚠ Coordination with v8.14 (in progress by another dev)
`origin/feature/v8.14` exists but currently has **zero divergence from `main`**
(HEAD == `36832c0f`, no Wn sub-branches yet). I could not see its scope.
- Branch `feature/v8.15` **from `main`** per R37.
- **Collision risk**: v8.14 may touch the same files (notifications, scheduler
  `config/askmydocs.php`, admin dashboard, `nav-config.ts`, `routes/api.php`).
  **Action item before W1**: confirm v8.14's scope with the dev; once v8.14 GAs to
  `main`, merge `main` into `feature/v8.15` and reconcile. Keep new code in **new
  files/services** wherever possible to minimise overlap.

---

## Architecture

Three new service clusters over the existing signals; everything tri-surface (R44).

```
EngagementMetricsService ─┐
KbHealthService (exists) ──┤
AiInsightsService (exists)─┼─► DigestComposer ─► DigestRenderer ─► ChannelRegistry (exists)
SearchFailure/gaps (exists)┤        │                 ├─ EmailDigestRenderer  (HTML, magazine-grade)
GamificationService ───────┘        │                 ├─ Discord/Slack/Teams card renderers
                                     │                 └─ InAppDigestRenderer (feed row)
                                     └─► AiDigestNarrator (opt-in, dedicated model)
EngagementMetricsService ─► Admin dashboard (extend) + User dashboard (NEW)
GamificationService ──────► Leaderboard / badges / streaks (opt-in)
```

- **`DigestComposer`** (`app/Services/Digest/`): assembles a tenant- and
  user-scoped `DigestPayload` DTO of typed **sections** (new/modified/promoted
  docs; stale/obsolete docs needing review; top unanswered = content gaps; KB
  health trend; KPIs; "your docs need attention"; gamification highlights). Pulls
  from existing services — no new signal computation duplicated.
- **`DigestRenderer`** strategy per channel (R23 registry pattern: validate FQCN
  at boot + non-overlapping `supports()`), reusing the existing channel adapters
  for transport.
- **`AiDigestNarrator`** (`app/Services/Digest/`): optional LLM "what changed &
  why it matters" summary via `AiManager`, using the dedicated digest model
  override; default-on but degrades to deterministic copy when disabled/unwired
  (R43, R14).
- **`EngagementMetricsService`** (`app/Services/Engagement/`): contributor stats,
  citation-impact, coverage %, funnels, trends — DB-aggregated (R3), tenant-scoped
  (R30). Daily snapshot `kb_engagement_snapshots` (AdminInsightsSnapshot pattern).
- **`GamificationService`** (`app/Services/Engagement/`): derives scores/badges/
  streaks/rank from contribution events; **opt-in** via `KB_GAMIFICATION_ENABLED`
  (default-off, R43).

### New data model (tenant-aware: R30/R31 — `BelongsToTenant`, `tenant_id` + composite uniques starting with it; both completeness lists per [[feedback_two_tenant_model_completeness_lists]])
- **`kb_contribution_events`** — append-only per-user contribution log
  (`event: created|modified|promoted|reviewed|answered|cited`, `document_id`,
  `actor user_id`, weight, `created_at`). Feeds impact + gamification. Populate
  from existing ingest/promotion/citation paths (hook, not a new write path).
- **`kb_engagement_snapshots`** — daily per-tenant (+ optional per-user) rollup of
  engagement metrics (contributors, new/edited counts, answer/refusal rates,
  coverage %, avg health, leaderboard top-N). Mirrors `AdminInsightsSnapshot`.
- **Digest preferences** — extend, don't fork: add `frequency`
  (`weekly|monthly|off`) + `sections` JSON toggle to a new
  `digest_preferences` table (tenant+user unique) rather than overloading
  `notification_preferences`. Team-channel digest config lives in
  `notification_tenant_defaults` + `config/askmydocs.php`.
- **Gamification** — `kb_user_badges` (awarded badges) + scores/streaks stored on
  the per-user engagement snapshot (no extra hot table). Badge catalog in config.

### Config (`config/askmydocs.php` + `.env.example`, keep docs coupled R6/R9)
- `KB_DIGEST_AI_NARRATIVE_ENABLED=true`, `KB_DIGEST_AI_PROVIDER=`,
  `KB_DIGEST_AI_MODEL=` (dedicated model for the narrative task).
- `KB_DIGEST_WEEKLY_*` / `KB_DIGEST_MONTHLY_*` schedule slots (new
  `SCHEDULE_*_ENABLED`/`_CRON` entries via `TierOneSchedulerRegistrar`).
- `KB_GAMIFICATION_ENABLED=false` (+ badge thresholds).
- `KB_ENGAGEMENT_SNAPSHOT_*` retention.

---

## Per-Wn Definition of Done (NON-NEGOTIABLE — Lorenzo standing rule)
Every Wn that adds/changes a capability MUST ship, in the same week:
- **PHP surface** (Artisan command and/or service) — R44.
- **HTTP API** endpoint(s) + an **R32 authorization-matrix row** — R44.
- **MCP tool(s)** registered on `KnowledgeBaseServer::$tools` (+ count test bumped) — R44.
- **PHPUnit** (unit + feature; architecture tests for tenant/RBAC) — both-state for every flag (R43).
- **Vitest** for new/changed React components (R11 testids).
- **Playwright** happy-path + ≥1 failure-path scenario for every UI-touching change (R12/R13).
- Local critic loop (R40) → push → R36 cloud review loop → merge → RC tag (R39).
Cycle-final tasks: **README refreshed in EVERY relevant section** (features, changelog,
roadmap flip) + **doc-site deep pages** authored via the `mintlify-doc-authoring`
skill (R45), registered in `docs.json`.

## Phased delivery (feature/v8.15, RC tag per Wn closure — R39)

**W1 — Engagement foundation + digest data model.** `kb_contribution_events` +
`kb_engagement_snapshots` migrations + models; `EngagementMetricsService` + daily
`engagement:compute` command + snapshot; contribution-event hooks on
ingest/promote/citation; architecture tests (R30/R31). Tri-surface: command +
`GET /api/admin/engagement/*` (R32 matrix row) + MCP read tool.

**W2 — Multi-channel rich digest + AI narrative.** `DigestComposer` +
`DigestPayload` DTO + `DigestRenderer` registry; rebuild
`resources/views/emails/weekly-digest.blade.php` into a magazine-grade HTML email
with metrics + sections; Discord embed / Slack Block Kit / Teams Adaptive Card
renderers (reuse adapters); `AiDigestNarrator` (default-on, dedicated model,
R43 both states); `digest:send {--frequency=} {--tenant=} {--dry-run} {--preview}`.
Extends `NotificationsDigestWeeklyCommand` rather than replacing it.

**W3 — Digest preferences + in-app feed + monthly exec roll-up.**
`digest_preferences` (frequency + per-section toggles) with API + FE grid (extend
the preferences UI); in-app digest feed (notification-bell + SPA card);
`digest:send --frequency=monthly` executive roll-up (KB ROI, coverage/decision-debt
trends, contributor activity, cost). New schedule slots.

**W4 — User dashboard (NEW) + admin engagement analytics.** New `/app/dashboard`
user surface (RequireRole any authenticated): "your contributions", "your docs
(health / citations / review-due)", "your questions answered", "your impact",
trending topics you can answer, personalized review queue. Extend admin dashboard
with engagement cards: contributor leaderboard, coverage heatmap, question→answer
funnel, refusal-trend, staleness distribution, decision-debt trend, content-gap
top-N. Reuse `KpiCard`/`ChartCard`/recharts; R11/R12/R15/R16/R17 + Playwright.

**W5 — Gamification (opt-in) + polish + RC→GA.** `GamificationService` + badge
catalog + leaderboard + streaks (default-off, R43 both states); profile/badge UI;
weave gamification highlights into digest + dashboards; doc-site pages (R45);
README roadmap flip (R36 readme rules); RC tag → `feature/v8.15` → `main` GA (R37).

---

## Metrics catalog (the "wow")
- **Admin**: contributor leaderboard, knowledge-coverage %, question→answer funnel,
  refusal-rate trend, staleness distribution, decision-debt trend, top content
  gaps, KB-health trend, citation-graph density, cost/token burn (exists).
- **User**: your contributions & streak, your docs' health + citations + review-due,
  your questions answered, your impact (times your docs were cited), your rank +
  badges, trending topics you could answer, personalized "docs to review".
- **Digest**: the above bundled + top unanswered (gaps) + new/modified/promoted +
  stale/obsolete + AI narrative + per-user "your attention needed".

## Representative files
- **Backend (new)**: `app/Services/Engagement/{EngagementMetricsService,GamificationService}.php`,
  `app/Services/Digest/{DigestComposer,DigestRenderer,AiDigestNarrator,DigestPayload}.php` +
  `Renderers/*`; `app/Console/Commands/{EngagementComputeCommand,DigestSendCommand}.php`;
  `app/Http/Controllers/Api/Admin/EngagementController.php` +
  `app/Http/Controllers/Api/Me/UserDashboardController.php` +
  `DigestPreferenceController.php`; `app/Mcp/Tools/Kb{Engagement,Digest}*Tool.php`
  (+ bump `KnowledgeBaseServer::$tools` count test); `app/Models/{KbContributionEvent,
  KbEngagementSnapshot,DigestPreference,KbUserBadge}.php`; `database/migrations/*`.
- **Backend (extend)**: `NotificationsDigestWeeklyCommand`, `config/askmydocs.php`,
  `bootstrap/app.php` (slots), `.env.example`, `resources/views/emails/weekly-digest.blade.php`,
  `tests/Architecture/TenantIdMandatoryTest.php` + `TenantReadScopeTest`.
- **Frontend (new)**: `frontend/src/features/dashboard/*` (user),
  `features/admin/engagement/*`, `features/digest/*` (prefs + in-app feed); register
  in `components/shell/nav-config.ts` + `routes/index.tsx`.
- **Frontend (reuse)**: `KpiCard`/`KpiStrip`/`ChartCard`/`LazyRecharts`/`HealthStrip`,
  notification API hooks, `RequireRole`, `tokens.css`.
- **Docs (R45)**: new `/docs-site/` pages (engagement, digests, dashboards,
  gamification) + README roadmap flip on GA.

## R-rule compliance
R3 (DB-side aggregation), R6/R9 (env/config/docs coupled), R14 (surface failures),
R21 (atomic digest-sent gate — already in place, keep), R23 (renderer registry),
R27 (additive response shapes), R30/R31 (tenant isolation + both completeness
lists), R32 (RBAC matrix rows for every new route), R36/R40 (review loops),
R39 (RC per Wn), R43 (AI-narrative + gamification flags tested OFF and ON),
R44 (tri-surface PHP+API+MCP), R45 (doc-site parity), R11/R12/R15/R16/R17 (FE).

## Verification
- `vendor/bin/phpunit` (architecture + feature: digest composition, renderer
  registry mutex, engagement metrics, AI-narrator OFF/ON, gamification OFF/ON).
- `npm test` + `npm run e2e` (user dashboard, admin engagement cards, digest prefs).
- MCP registration-count test bumped; per-tool contract tests.
- `php artisan digest:send --dry-run --preview` renders each channel without sending;
  `engagement:compute --tenant=… ` writes a snapshot.
- Manual: trigger a weekly + monthly digest to a test Discord/Slack webhook and a
  mailtrap inbox; flip every new flag OFF then ON and confirm clean degrade (R43).

## Open items for Lorenzo before W1
1. Confirm **v8.14 scope** with the dev (collision surface above).
2. Badge catalog / leaderboard scope for W5 (which badges, tenant-configurable?).
3. Which **AI model** to pin for `KB_DIGEST_AI_MODEL` (default narrative model).
