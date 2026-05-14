# v6.0 follow-up — gap closure (2026-05-14)

Closes the two compliance gaps surfaced after the v6.0.0 GA tag was
cut. v6.0.0 (commit `0c4426b`) remains the GA marker per R37; this
follow-up is shipped as a host-side wire-up plus a coordinated
`v1.1.3` release of both sister packages (no host re-tag — the
package pin bump is a normal v4.x.x-class hotfix path).

## Gaps closed

### Gap 1 — AI Act middleware on the chat path

Before this follow-up, the v6.0 disclosure + consent middleware
classes shipped in `padosoft/laravel-ai-act-compliance` were
available but not actually wired onto `/api/kb/chat` — the host never
exercised them, so Art. 50 disclosure headers never appeared and the
consent gate could not be opted into.

Now wired:

- `bootstrap/app.php` registers the host-facing aliases
  `ai.disclosure` → `AiDisclosureMiddleware` and `ai.consent` →
  `RequireConsentMiddleware`.
- `routes/api.php` attaches `ai.disclosure` unconditionally to
  `/api/kb/chat` (Art. 50 disclosure header is always-on; opt-out
  via `ai-act-compliance.disclosure.enabled=false`).
- `routes/api.php` conditionally attaches `ai.consent:<feature>` when
  the host opts in via the new
  `ai-act-compliance.consent.gate_chat_feature` config knob (default
  `null` — gate inactive, existing AskMyDocs users keep working
  without a granted `ConsentRecord`).
- `tests/Feature/Api/KbChatAiActMiddlewareTest.php` — 6 new feature
  tests covering: disclosure header always-on, disclosure opt-out via
  package config, consent gate inactive by default, consent gate
  denies missing grant (403), consent gate allows granted record,
  consent gate denies revoked record.

Under Orchestra Testbench the host's `bootstrap/app.php` does not
execute; `tests/TestCase.php` mirrors the alias declarations + the
package SP registration so test runs match production wiring.

### Gap 2 — FRIA module (AI Act Art. 27)

The compliance bundle covered Risk Register, DSAR, Bias Monitor,
Human Review tracker, Incident state machine, Consent + Disclosure,
Cybersecurity middleware, and Article 30 attestation — but the
Fundamental Rights Impact Assessment that Art. 27 mandates for
high-risk deployments was not yet a first-class module.

Now shipped in `padosoft/laravel-ai-act-compliance` v1.1.3:

- `FriaStatus` enum (`draft` / `active` / `review_due` / `retired`).
- `FriaAssessment` Eloquent model with JSON `risks_json` +
  `mitigations_json` casts and sign-off audit fields.
- `fria_assessments` migration (`tenant_id` + `project_key` indexed
  nullables, `signed_off_by/at` audit trail).
- `FriaService` with `open` / `updateMitigations` / `scheduleReview`
  / `signOff` / `retire` / `isReviewDue` — covers the full workflow
  a compliance officer drives for a high-risk-system rollout. Default
  cadence reads from
  `ai-act-compliance.fria.default_review_cadence_days` (default
  180); explicit `days` override wins per call.

Shipped in `padosoft/laravel-ai-act-compliance-admin` v1.1.3:

- `src/features/fria/FriaScreen.tsx` — full FRIA Assessments page
  under the Risk Management sidebar section: status filter (counts
  per bucket), table with overdue-aware next-review countdown,
  drawer drill-down with risks list + mitigations grid + Schedule
  review / Sign off action buttons.
- 5 fixture FRIA entries covering every status — including one
  overdue (`review_due`) and one Art. 5(1)(f) refusal preserved
  for audit (`retired`).
- `Shell.tsx` nav entry + `App.tsx` `/fria` route registered.

## Test count delta

| Suite | Before | After | Δ |
|---|---|---|---|
| AskMyDocs PHPUnit | 1723 | 1729 | +6 (AI Act middleware) |
| `laravel-ai-act-compliance` PHPUnit | 62 | 70 | +8 (FRIA service) |
| `laravel-ai-act-compliance-admin` Vitest | 32 | 36 | +4 (FRIA screen) |
| `laravel-ai-act-compliance-admin` Playwright | 13 | 15 | +2 (FRIA E2E) |

## Release links

- `padosoft/laravel-ai-act-compliance` v1.1.3 — FRIA module + chat
  consent gate config:
  https://github.com/padosoft/laravel-ai-act-compliance/releases/tag/v1.1.3
- `padosoft/laravel-ai-act-compliance-admin` v1.1.3 — FRIA screen:
  https://github.com/padosoft/laravel-ai-act-compliance-admin/releases/tag/v1.1.3

## Host pin

`composer.json` bumped to `^1.1.3` for both sister packages. The
v6.0.0 GA tag (commit `0c4426b`) is preserved unchanged on
`origin/main` per R37; this follow-up is the regular v6.0.x-class
patch path Lorenzo authorised for closing v6.0 scope at 100%.
