# STATUS — v4.7 W3 RC acceptance (2026-05-12)

## Milestone

W3 closure of the v4.7 cycle — Admin SPA + SSE streaming + GA prep.
Closes the three-week v4.7 cycle (W1 backend + W2 workflows + W3
admin SPA & GA).

## Branch / tag

- Sub-branch: `enh/v4.7/W3-admin-spa-and-ga`
- Integration branch: `feature/v4.7`
- W3 PR: against `feature/v4.7`
- W3 RC tag: `v4.7.0-rc3` (pinned at the W3 sub-PR merge SHA)
- GA tag: `v4.7.0` (pinned at the GA-merge SHA on `main`)

## Acceptance checklist

- [x] SSE streaming controller wired
      (`POST /api/admin/tabular-reviews/{id}/generate-stream`)
- [x] Wire format documented (5 SSE events: start / document / cell /
      done / error)
- [x] Admin SPA — Tabular Reviews: list + create dialog + show page
      with grid view
- [x] Admin SPA — Workflows: list + scope tabs + create dialog +
      AI-suggest gallery + per-card hide
- [x] Admin rail entries wired (Tabular Reviews + Workflows)
- [x] TanStack routes wired (RequireRole guard inside component)
- [x] Vitest tests for the two list components (13 tests covering
      loading / empty / ready / error / create happy + failure /
      scope switch / suggest gallery)
- [x] PHPUnit feature tests for the SSE controller (6 tests covering
      happy stream / 404 / 401 / 403 viewer / error event /
      max_documents cap)
- [x] Playwright specs against real backend (8 tests across
      tabular-reviews + workflows: list shell + create dialog ARIA +
      full CRUD round-trip + FE submit-disabled guard on empty
      required fields). Real BE 422 E2E surfacing is deferred to
      v4.7.x alongside the `/api/admin/projects/keys` dropdown
      (R18 work)
- [x] R13 verification script passes (no internal route stubs)
- [x] README roadmap row v4.7 flipped from "⏳ planned" → "✅ shipped
      2026-05-12"
- [x] README Key Features rows updated (Tabular Review row reflects
      GA, new Workflows row added)
- [x] CHANGELOG entry under `v4.7.0` GA heading
- [x] ADR 0010 records 5 architectural decisions
- [x] R36 Copilot review loop on the W3 sub-PR (driven by the
      `gh pr create --reviewer copilot-pull-request-reviewer` flag)
- [x] R36 Copilot review loop on the GA-merge PR
- [x] R37 — `feature/v4.7` merges to `main` once per major
- [x] R39 — `v4.7.0-rc3` at the W3 closure SHA + `v4.7.0` at the GA
      SHA

## Test count delta

- **PHPUnit**: 1885 (v4.6 GA baseline) → 1891 (+6 W3 SSE controller).
  Combined cycle: 1885 → ~1965 across W1 (+41) + W2 (+39) + W3 (+6).
- **Vitest react**: 384 → 397 (+13 W3 admin SPA).
- **Playwright**: +8 (4 tabular + 4 workflows).
- **Cycle total**: ≈ +115 new tests.

## Pre-existing baseline failure

- `Tests\Feature\Kb\Chunking\JiraIssueChunkerTest::comments_section_aggregates_into_separate_chunk`
  fails on the `feature/v4.7` baseline (reproduces against the
  pre-W3 HEAD). Reported in the GA-merge PR body. Parked for v4.7.1.

## Honesty pass

The v4.7 design doc references Glide Data Grid for the tabular grid.
The W3 GA ships a plain HTML table — same UX surface, same testid
contract, ~250 KB less FE bundle. See ADR 0010 D1 for the rationale
and the v4.7.x migration plan.

The Workflow edit + share modal + use-as-template paths are
scaffolded in the W2 BE but not surfaced in the W3 SPA shell. They
are reachable through the JSON API and ship in v4.7.x.

Per the standing rule `feedback_admin_ui_panel_alignment_per_release.md`,
both new admin areas DO have menu entries + landing routes (R36
alignment honoured); the partial surface coverage above is a
v4.7.x polish backlog, not a missing-area gap.
