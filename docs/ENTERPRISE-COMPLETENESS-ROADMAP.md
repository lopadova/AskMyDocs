# Enterprise Completeness Roadmap

Backlog of **incomplete / stubbed / parked** surfaces discovered during the
2026-05 completeness audit. Each item below was **verified against the
current code** (not just a comment reference). Two reported items were
overstated and are recorded as "corrected" so the record is honest.

Effort key: **S** ≈ ≤1 day · **M** ≈ 2–4 days · **L** ≈ ≥1 week.
Status: ⏳ planned · 🔧 partially mitigated · ✅ shipped.

> Audit method: two read-only agents cross-checked each claim against the
> real files (controllers, services, routes, migrations, frontend). 13
> confirmed, 2 corrected.

---

## 🔴 High priority (user-visible "dead" or misleading)

| # | Item | Current state | Scope to reach enterprise-grade | Effort |
|---|------|---------------|----------------------------------|--------|
| R1 | **Command palette wiring** | `CommandPalette.tsx` is a static `ITEMS` array (incl. sample docs); every result `onClick` only calls `setOpen(false)` — no navigation, no search | Wire real TanStack-router navigation per item + debounced live KB/doc search (`/api/kb/documents/search`) + recent-command persistence | M |
| R2 | **Admin invite resend email** | `UserController::resendInvite` no longer lies (v8.0.3: logs warning + `email_sent:false`) but still sends no mail | Queued Mailable/Notification with signed accept-invite link + rate-limit + audit row | S/M · 🔧 |
| R3 | **Compliance tenant input (now dead)** | `ComplianceReportsView.tsx` seeds `tenantId='tenant-acme'` and posts it; v8.0.3 made the BE derive tenant server-side, so this input is now **inert + misleading** | Remove the input, drop `tenant_id` from the generate payload, key the list query off the resolved session tenant | S |
| R4 | **Admin rail orphan routes** | `KbHealth`, `PiiRedactor`, `Flows`, `EvalHarness` routes exist but have **no nav-rail entry** — reachable only by typing the URL | Add the four RBAC-gated `RailEntry` rows + a `section` id per page in `AdminShell.tsx` | S |

## 🟠 Medium priority (feature works partially / silently degraded)

| # | Item | Current state | Scope | Effort |
|---|------|---------------|-------|--------|
| R5 | **Google Slides chunking** | `OfficeDocChunker::chunkSlidesDeferred()` emits ONE placeholder chunk (`skip_reason=gslide-deferred`); `drive_gslide` docs are indexed but not usefully searchable | Slide-render → text/OCR pipeline producing per-slide chunks + speaker notes | M/L |
| R6 | **Weekly notification digest** | `notification_digests` table + model + tests exist but **nothing writes or reads them**; no `notifications:digest-weekly` command/job/schedule (channels Slack/Teams/Discord/Webhook already work) | Incremental dispatcher upsert + scheduled weekly render+send job (fan-out to per-user enabled channels, stamp `sent_at`/`recipients_count`) + register in `TierOneSchedulerRegistrar` | M |
| R7 | **PII strategy switching** | `PiiStrategyController` exposes only `GET`; switching strategy requires an env/config change | DB-backed per-tenant strategy override + `PATCH` endpoint + Gate + audit, validated against the factory's available names | M |
| R8 | **Tabular Review progressive-paint** | BE SSE `/tabular-reviews/{id}/generate-stream` shipped, but FE only calls the synchronous `/generate` | POST-SSE fetch client + per-cell live status painting on the show page | M |
| R9 | **Two-Factor Authentication (TOTP)** | `TwoFactorController` enable/verify/disable all return `501`; `AUTH_2FA_ENABLED` default false | TOTP enrollment + recovery codes + challenge/verify + Sanctum step-up, secrets encrypted at rest, FE enroll/verify screens | L |
| R10 | **Chat related-graph panel** | `chat.store` has `showGraph`/`toggleGraph()` but no panel is mounted and the toggle is never invoked | Right-rail graph panel (reuse admin KB graph viewer) seeded from response-citation `kb_nodes`, with a header toggle | L |

## 🟡 Low priority (polish / bounded today)

| # | Item | Current state | Scope | Effort |
|---|------|---------------|-------|--------|
| R11 | **Activity-log write side** | `spatie/laravel-activitylog` **is installed** and the read tab works when the table exists, but **no model uses `LogsActivity`** and nothing records events — the tab is always empty | Add `LogsActivity` to key admin-mutated models (users, roles, KB docs, commands) + record domain events; wire `activitylog:clean` into the scheduler | S/M |
| R12 | **Evernote ENEX import UI** | BE OAuth connector + `.enex` bulk-import endpoint **exist and work**; only the admin SPA upload affordance is missing | Upload form in the connector admin screen wired to the import endpoint with progress/result display | S |
| R13 | **Tabular Reviews + Workflows pagination** | Both hardcode `per_page=100` with no Prev/Next; fine until 100 rows | Thread `current_page` through the api clients + a shared Prev/Next pager bound to `meta.last_page` | S |

---

## Corrections (reported but overstated)

- **"ActivityLog package not installed"** — FALSE. `spatie/laravel-activitylog`
  IS in `composer.json`; the read path is real. The genuine residual gap is
  the missing write-side (R11 above).
- **"Evernote: no OAuth / no connector"** — FALSE. A full OAuth connector is
  configured in `config/connectors.php`, the package is required, and the
  `.enex` import endpoint works. The only gap is the FE upload UI (R12).

---

*Generated 2026-05-26 from the completeness audit. Items are independent;
sequence them into future `vX.Y` cycles per business priority. R3 is the
most time-sensitive — it was made inert by the v8.0.3 security hotfix and
should ship in the same release window or immediately after.*
