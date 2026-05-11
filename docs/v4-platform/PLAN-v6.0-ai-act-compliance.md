# PLAN — v6.0 AI Act Compliance Bundle (Extracted Packages + AskMyDocs Integration)

**Cycle:** v6.0 (post v5.0 GA)
**Duration:** ~8 weeks (W1..W8)
**Integration branch:** `feature/v6.0` (R37)
**RC tags expected:** `v6.0.0-rc1` (after W2), `v6.0.0-rc2` (after W5), `v6.0.0-rc3` (after W7), GA `v6.0.0` at W8 closure
**Status:** PLAN — pending v5.0 GA

---

## 1. Cycle goal

> **Position AskMyDocs as the FIRST Laravel platform AI-Act-ready out of the box, AND release the underlying compliance toolkit as a standalone Padosoft package usable by ANY Laravel AI app.**

Lorenzo's strategic decision (memory `feedback_v45_strategic_roadmap`): instead of making AskMyDocs directly AI-Act compliant, **EXTRACT a generic open-source Laravel package** `padosoft/laravel-ai-act-compliance` + admin SPA `padosoft/laravel-ai-act-compliance-admin`. AskMyDocs v6.0 then INTEGRATES the two packages + adds the 20% RAG-specific bits (token-level explainability, RAG refusal-quality cohort, provenance chain).

### Strategic reasoning

- **Nothing similar exists in Laravel ecosystem** — Python has Lakera Guard / Fairlearn / Aequitas; Laravel has nothing → first-mover advantage
- **Padosoft brand consolidation** — adds to series `laravel-ai-regolo` + `laravel-pii-redactor` + `laravel-flow` + `eval-harness` + `laravel-ai-act-compliance` = enterprise Laravel AI tooling ecosystem
- **AskMyDocs credibility** — uses its own compliance package (eat-own-dog-food, proof of technical credibility)
- **Community magnet** — every Laravel dev building AI apps will install (AI Act enforcement enters full force 2026-2027)
- **EU regulatory tailwind** — perfect window
- **Pattern proven** — same modus operandi that worked for `laravel-pii-redactor` extraction (v4.3)

---

## 2. Scope — three artefacts

### Artefact 1 — `padosoft/laravel-ai-act-compliance` (new repo, OS, ~80% generic Laravel)

Generic Laravel AI compliance toolkit. New repo `padosoft/laravel-ai-act-compliance` under MIT. Composer name `padosoft/laravel-ai-act-compliance`.

**Module structure:**

```
padosoft/laravel-ai-act-compliance/
├── composer.json
├── README.md                       # WOW pattern (14 sections + 🚀 AI vibe-coding pack)
├── CHANGELOG.md
├── LICENSE                          # MIT
├── config/
│   └── ai-act-compliance.php       # all module knobs, default safe
├── database/migrations/
│   ├── *_create_risk_register_entries_table.php
│   ├── *_create_dsar_requests_table.php
│   ├── *_create_consent_records_table.php
│   ├── *_create_human_reviews_table.php
│   ├── *_create_incident_tickets_table.php
│   ├── *_create_incident_state_transitions_table.php
│   └── *_create_compliance_attestations_table.php
├── src/
│   ├── AiActComplianceServiceProvider.php
│   ├── Disclosure/
│   │   ├── AiDisclosureMiddleware.php
│   │   └── Blade/AiDisclosureDirective.php   # @aiDisclosure
│   ├── RiskRegister/
│   │   ├── Models/RiskRegisterEntry.php
│   │   ├── Enums/AiActRiskCategory.php       # low / limited / high / unacceptable
│   │   ├── Http/Controllers/RiskRegisterController.php
│   │   └── Services/RiskRegisterService.php
│   ├── DSAR/
│   │   ├── Models/DsarRequest.php
│   │   ├── Enums/DsarType.php                # export / delete / rectify
│   │   ├── Enums/DsarStatus.php
│   │   ├── Jobs/ExportUserDataJob.php
│   │   ├── Jobs/DeleteUserDataJob.php
│   │   ├── Http/Controllers/DsarController.php
│   │   ├── Services/DsarService.php
│   │   └── Contracts/UserDataExporter.php    # host implements
│   ├── BiasMonitoring/
│   │   ├── Contracts/CohortParityMetric.php  # generic interface (eval-harness plugs here)
│   │   ├── Services/BiasMonitorService.php
│   │   └── Models/BiasSnapshot.php
│   ├── HumanReviewTracker/
│   │   ├── Models/HumanReview.php
│   │   ├── Enums/HumanReviewState.php        # pending / approved / rejected / escalated
│   │   ├── StateMachine/HumanReviewStateMachine.php (Spatie state-machine)
│   │   └── Services/HumanReviewService.php
│   ├── Incident/
│   │   ├── Models/IncidentTicket.php
│   │   ├── Models/IncidentStateTransition.php
│   │   ├── Enums/IncidentSeverity.php        # low / medium / high / critical
│   │   ├── Enums/IncidentStatus.php          # open / triage / mitigating / closed
│   │   ├── Services/IncidentService.php
│   │   └── Routing/EscalationRouter.php
│   ├── Consent/
│   │   ├── Models/ConsentRecord.php          # polymorphic
│   │   ├── Middleware/RequireConsentMiddleware.php
│   │   └── Services/ConsentService.php
│   ├── Cybersecurity/
│   │   ├── Middleware/PerUserRateLimitMiddleware.php
│   │   ├── Middleware/SessionAnomalyDetectionMiddleware.php
│   │   └── Helpers/TwoFactorHelper.php
│   ├── ComplianceAttestation/
│   │   ├── Models/ComplianceAttestation.php
│   │   ├── Services/ComplianceAttestationService.php
│   │   └── Pdf/AttestationPdfGenerator.php   # exports for auditors
│   └── Support/
│       ├── ComplianceEvents.php              # Laravel events for module integrations
│       └── ComplianceConfig.php
└── tests/
    ├── Unit/
    ├── Feature/
    └── Live/                                 # opt-in
```

**Key contracts (host implements):**

- `Padosoft\AiActCompliance\DSAR\Contracts\UserDataExporter` — host implements `export(User $user): array` returning all user-related data scopes. The package handles the ZIP packaging + delivery + SLA tracking.
- `Padosoft\AiActCompliance\DSAR\Contracts\UserDataDeleter` — host implements cascade-delete logic per-domain-entity.
- `Padosoft\AiActCompliance\BiasMonitoring\Contracts\CohortParityMetric` — host (or eval-harness plugin) implements cohort-parity computation; bias monitor consumes the result and tracks drift.

**Service provider auto-discovery:**
- Auto-registers routes (config-gated per module)
- Auto-registers Blade directives (`@aiDisclosure`)
- Auto-registers middleware aliases (`ai-act.consent`, `ai-act.rate-limit`, `ai-act.session-anomaly`)
- Publishes config + migrations

**Tests:** ~250 tests minimum (Unit + Feature). Live testsuite opt-in for AI Act regulatory references.

### Artefact 2 — `padosoft/laravel-ai-act-compliance-admin` (new repo, OS, ~80% generic)

React + Vercel/shadcn admin SPA. **Cross-mountable in any Laravel app**. Same architecture pattern as `pii-redactor-admin` (v4.4/W2) and `eval-harness-ui` (v4.4/W3).

**Stack:**
- React 19 + TypeScript
- shadcn/ui + Tailwind v4 + TanStack Router (BrowserRouter basename pattern for cross-mount)
- recharts for compliance metrics charts
- framer-motion for transitions (optional)
- axios shared instance + Sanctum cookie share for host auth
- Vite for build → ships as a single bundle that any Laravel app mounts under their admin shell

**Repo layout:**

```
padosoft/laravel-ai-act-compliance-admin/
├── composer.json                 # PHP service provider (publishes Vite manifest)
├── package.json                  # Vite build
├── README.md                     # WOW pattern
├── src/                          # React source
│   ├── main.tsx                  # cross-mount entry
│   ├── AppContextProvider.tsx
│   ├── App.tsx                   # router setup
│   ├── api/                      # axios shared instance + per-resource clients
│   ├── features/
│   │   ├── overview/             # Screen 1
│   │   ├── dsar/                 # Screen 2
│   │   ├── consent/              # Screen 3
│   │   ├── risk-register/        # Screen 4
│   │   ├── incidents/            # Screen 5
│   │   ├── bias-monitor/         # Screen 6
│   │   ├── dpo-console/          # Screen 7
│   │   └── settings/             # Screen 8
│   ├── components/               # cross-screen shared components
│   ├── hooks/                    # shared TanStack Query hooks
│   └── lib/                      # config, locale, theme
├── tests/                        # Vitest + Playwright
└── publishable/                  # Vite-built bundle for host consumption
```

8 screens (specified in detail in `DESIGN-SPEC-v6.0-ai-act-compliance-admin.md`):

1. Compliance overview KPI dashboard
2. DSAR queue (list + detail)
3. Consent overview (per-user / per-feature)
4. Risk register browser
5. Incident manager (state-machine UI)
6. Bias monitor (cohort parity + drift)
7. DPO console (retention + deletion log + attestation)
8. Settings (env vars + feature flags + thresholds)

### Artefact 3 — AskMyDocs v6.0 integration (20% RAG-specific)

- Wire `padosoft/laravel-ai-act-compliance` via composer require + service provider auto-discovery
- Wire admin SPA cross-mount at `/admin/ai-act-compliance` (same pattern as pii-redactor-admin + eval-harness-ui)
- Implement host contracts:
  - `UserDataExporter` — exports `chat_logs` + `conversations` + `messages` + `kb_canonical_audit` + `connector_installations` + (v5.0) `mcp_tool_call_audit` rows for the user
  - `UserDataDeleter` — cascade-deletes via Eloquent observers + soft-delete-aware queries (R2)
- Add RAG-specific compliance bits:
  - **`App\Compliance\TokenLevelExplainability`** — decorator over `KbChatController::streamReply()` that records chunk-to-answer-token mapping in `chat_log_provenance` table. Allows auditor to ask "this sentence in the answer — what chunk did it derive from?" and get the answer with token-byte ranges.
  - **`App\Compliance\RagRefusalQualityMetric`** — implements `CohortParityMetric` interface. Tracks refusal rate per cohort (language / source_type / canonical_type) and surfaces drift in bias monitor.
  - **`App\Compliance\ProvenanceChain`** — wires eval-harness traces with retrieval `source_path` lineage. Auditor can trace any eval outcome back through retrieval → chunk → document → frontmatter author.

**New migration in AskMyDocs:**

```php
// chat_log_provenance — RAG-specific provenance chain
Schema::create('chat_log_provenance', function (Blueprint $table) {
    $table->id();
    $table->foreignId('chat_log_id')->constrained('chat_logs')->cascadeOnDelete();
    $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
    $table->integer('answer_token_start');     // byte offset in answer
    $table->integer('answer_token_end');
    $table->foreignId('knowledge_chunk_id')->constrained('knowledge_chunks')->cascadeOnDelete();
    $table->string('source_path');             // denormalised for forensic survival
    $table->decimal('contribution_score', 5, 4);  // 0..1
    $table->timestamps();
    $table->index(['chat_log_id', 'answer_token_start']);
});
```

---

## 3. W1..W8 breakdown

### W1 — Cut `padosoft/laravel-ai-act-compliance` skeleton + Disclosure module

**Sub-PRs (split across 3 repos: new package + admin SPA repo skeleton + AskMyDocs):**

In `padosoft/laravel-ai-act-compliance` (new repo):
- `W1.A` — Repo creation + composer.json + service provider + WOW README skeleton + LICENSE (MIT) + CI matrix (PHP 8.3/8.4/8.5 + Laravel 13 per memory `feedback_padosoft_repo_ci_versions`)
- `W1.B` — Disclosure module — `AiDisclosureMiddleware` + Blade `@aiDisclosure` directive + locale strings (EN + IT default)
- `W1.C` — Initial test pack — Unit tests for Disclosure module
- `W1.D` — Package README pass + Live testsuite skeleton (opt-in per memory `feedback_package_live_testsuite_opt_in`)
- `W1.E` — Wn closure status doc

**Risk:** **low** — first Wn, scoped to skeleton + 1 module.

### W2 — RiskRegister + DSAR modules in package + initial admin endpoints

In `padosoft/laravel-ai-act-compliance`:
- `W2.A` — RiskRegister module — model + migration + service + controllers + AI Act risk categorisation enum (low / limited / high / unacceptable per AI Act articles)
- `W2.B` — DSAR module — `DsarRequest` model + `ExportUserDataJob` + `DeleteUserDataJob` + `UserDataExporter` / `UserDataDeleter` contracts + DSAR controller + status tracking
- `W2.C` — Package Live test pack — opt-in test that runs DSAR export against fake host implementation
- `W2.D` — Package v0.1.0 tag (R39 for sister packages = plain SemVer per memory `feedback_rc_tag_per_week_milestone`)
- `W2.E` — `rc1` tag on AskMyDocs `feature/v6.0` — `v6.0.0-rc1` at W2 closure (R39)
- `W2.F` — Wn closure status doc

**Risk:** **medium** — DSAR cascade-delete is RCE-class if wrong (deletes wrong user's data). Contract-based design + host implements the actual deletion logic = mitigation.

### W3 — BiasMonitoring + HumanReviewTracker + Consent modules; start admin SPA repo

In `padosoft/laravel-ai-act-compliance`:
- `W3.A` — BiasMonitoring module — `CohortParityMetric` contract + `BiasMonitorService` + `BiasSnapshot` model + integration test against fake metric
- `W3.B` — HumanReviewTracker module — model + state machine (Spatie state-machine) + service + audit log integration
- `W3.C` — Consent module — polymorphic `ConsentRecord` + `RequireConsentMiddleware` + middleware alias `ai-act.consent` + service

In `padosoft/laravel-ai-act-compliance-admin` (new repo, kicked off):
- `W3.D` — Repo creation + Vite + React 19 + shadcn + Tailwind v4 + TanStack Router scaffold + axios shared instance + Sanctum cookie share
- `W3.E` — Cross-mount integration pattern — entry point + AppContextProvider + BrowserRouter basename + host config consumption
- `W3.F` — Initial 3 screens scaffold: Overview / DSAR / Consent (empty containers + routing — no content yet)
- `W3.G` — Wn closure status doc

**Risk:** **medium** — admin SPA cross-mount has 2 precedents (pii-redactor-admin + eval-harness-ui) so pattern is proven. State machine for HumanReviewTracker needs careful testing.

### W4 — Incident + Cybersecurity modules; admin SPA shell + 3 screens (Overview / DSAR / Consent)

In `padosoft/laravel-ai-act-compliance`:
- `W4.A` — Incident module — `IncidentTicket` + `IncidentStateTransition` + `IncidentService` + `EscalationRouter` (notification stack: email + Slack + webhook)
- `W4.B` — Cybersecurity module — `PerUserRateLimitMiddleware` + `SessionAnomalyDetectionMiddleware` + `TwoFactorHelper`
- `W4.C` — Package v0.2.0 tag

In `padosoft/laravel-ai-act-compliance-admin`:
- `W4.D` — Screen 1: Compliance Overview — 4-tile KPI grid + 2 panels (recent incidents + DSAR queue chart) + recharts integration
- `W4.E` — Screen 2: DSAR Queue — list + detail panel (resizable split) + actions (approve / reject / mark-in-progress / mark-completed) + bulk-action support
- `W4.F` — Screen 3: Consent Overview — 2 tabs (Per User / Per Feature) + consent matrix component + revocation timeline
- `W4.G` — Wn closure status doc

**Risk:** **medium-high** — admin SPA 3 screens is the largest FE deliverable of any Wn so far in v6.0. Cohort with eval-harness-ui v4.4/W3 (which shipped 1 screen in 1 Wn).

### W5 — admin SPA screens 4-6 (Risk register / Incident manager / Bias monitor)

In `padosoft/laravel-ai-act-compliance-admin`:
- `W5.A` — Screen 4: Risk Register Browser — card grid + filter sidebar + detail view + mitigation timeline
- `W5.B` — Screen 5: Incident Manager — kanban board (4 lanes) + cards + detail timeline + escalation routing tree + affected-users browser + postmortem template
- `W5.C` — Screen 6: Bias Monitor — cohort selector + 3 chart panels (accuracy parity / drift over time / sample inspector) + alert config panel
- `W5.D` — Package v0.3.0 tag (admin SPA)
- `W5.E` — `rc2` tag on AskMyDocs `feature/v6.0` — `v6.0.0-rc2` at W5 closure (R39)
- `W5.F` — Wn closure status doc

**Risk:** **high** — three complex screens in one Wn. Bias Monitor chart panels need careful data fetching (TanStack Query + drift-over-time uses time-series data with potentially thousands of points).

### W6 — admin SPA screens 7-8 (DPO console / Settings) + cross-mount integration into AskMyDocs

In `padosoft/laravel-ai-act-compliance-admin`:
- `W6.A` — Screen 7: DPO Console — retention policy review + deletion log + consent revocation audit + data flow map (visual diagram via recharts/d3) + compliance attestation PDF export CTA
- `W6.B` — Screen 8: Settings — env vars read-only + feature flags + admin roles matrix + bias monitor thresholds + DSAR SLA targets + webhook config
- `W6.C` — Cross-cutting components polish — toast (sonner) + modal dialogs + loading skeletons + error boundaries + ⌘K command palette
- `W6.D` — A11y pass — WCAG 2.1 AA per memory; keyboard nav full coverage; screen reader semantics; focus management
- `W6.E` — Package v0.4.0 tag (admin SPA)
- `W6.F` — Admin SPA tagged `v1.0.0` final on Packagist (admin SPA reaches feature-complete)

In AskMyDocs:
- `W6.G` — Composer require `padosoft/laravel-ai-act-compliance` + `padosoft/laravel-ai-act-compliance-admin` + service provider auto-registration
- `W6.H` — Cross-mount admin SPA at `/admin/ai-act-compliance` (same pattern as pii-redactor-admin)
- `W6.I` — Wn closure status doc

**Risk:** **medium** — screens 7-8 are simpler than 4-6; A11y pass is mostly verification. Cross-mount integration follows 2 precedents.

### W7 — AskMyDocs RAG-specific 20% (TokenLevelExplainability + RagRefusalQualityMetric + ProvenanceChain)

In AskMyDocs:
- `W7.A` — Migration `chat_log_provenance` + `ChatLogProvenance` model + tenant-scoped scopes (R30)
- `W7.B` — `App\Compliance\TokenLevelExplainability` — decorator over `KbChatController::streamReply()` that records chunk-to-answer-token mapping during streaming
- `W7.C` — `App\Compliance\RagRefusalQualityMetric` — implements `CohortParityMetric` interface from compliance package. Tracks refusal rate per cohort (language / source_type / canonical_type)
- `W7.D` — `App\Compliance\ProvenanceChain` — wires eval-harness traces with retrieval `source_path` lineage; auditor can trace any eval outcome through retrieval → chunk → document → frontmatter author
- `W7.E` — Bias monitor extension — register `RagRefusalQualityMetric` in eval-harness cohort manifest; surface drift in admin SPA Bias Monitor screen
- `W7.F` — `rc3` tag — `v6.0.0-rc3` at W7 closure (R39)
- `W7.G` — Wn closure status doc

**Risk:** **medium** — TokenLevelExplainability touches the chat hot path (R6 R-rule for memory-safe ops; must not slow down streaming). Provenance mapping must be efficient or it doubles chat latency.

### W8 — RC acceptance + GA merge + closure across all 3 artefacts

**Sub-PRs:**

In AskMyDocs:
- `W8.A` — RC acceptance test pack — full E2E suite green + Architecture suite green + cohort regression vs v5.0 baseline + multi-tenant isolation test extended to compliance tables
- `W8.B` — Bug-fix iterations from RC acceptance (typically 2-3 iterations per memory `feedback_copilot_pr_review_loop`)
- `W8.C` — Documentation refresh — README `AI Act Compliance` section + ADR 0009 ("v6.0 AI Act compliance integration via extracted packages")
- `W8.D` — `feature/v6.0` → `main` merge per R37 (once-per-major)
- `W8.E` — `v6.0.0` GA tag at merge SHA

In `padosoft/laravel-ai-act-compliance`:
- `W8.F` — Package final v1.0.0 tag on Packagist + WOW README final pass

In `padosoft/laravel-ai-act-compliance-admin`:
- `W8.G` — Admin SPA final v1.0.0 was tagged in W6; verify Packagist accessible + WOW README final pass

- `W8.H` — v6.0 cycle closure doc

**Risk:** **medium** — three artefacts to coordinate at GA. Package versions must be locked in `composer.json` constraints (e.g. `^1.0`) before AskMyDocs v6.0.0 tag.

---

## 4. Risks + mitigations

| Risk | Probability | Impact | Mitigation |
|---|---|---|---|
| DSAR delete cascade wrong user | low | RCE-class | Contract-based design; host implements deletion; package provides ZIP + SLA tracking only; comprehensive multi-user delete test |
| AI Act regulatory interpretation drift | medium | medium | Package documents AI Act article references next to each module; community review + DPO consultation pre-v1.0.0 |
| Admin SPA cross-mount breakage | medium | UX | 2 precedents (pii-redactor-admin v4.4/W2 + eval-harness-ui v4.4/W3) + dedicated Playwright cross-mount test |
| TokenLevelExplainability chat latency overhead | medium | UX | Streaming-aware implementation (record during stream, not after); benchmark vs baseline before RC tag |
| Sister packages must reach v1.0.0 before AskMyDocs integration (per memory `feedback_packages_full_v1_before_integration`) | accepted | Schedule | Admin SPA tags v1.0.0 at W6, integration to AskMyDocs starts W6 immediately; compliance package tags v1.0.0 at W8 |
| Bias monitor cohort definitions misalign with eval-harness | medium | medium | Contract-first design (`CohortParityMetric`); reference impl in eval-harness package |
| GDPR Article 15+17 deadline violations (DSAR SLA 30 days) | medium | regulatory | SLA tracker in package + admin alert when DSAR exceeds 25 days + escalation routing |
| State machine bugs in HumanReviewTracker / Incident | medium | medium | Spatie state-machine library (proven) + 100% transition coverage in tests |

---

## 5. Acceptance criteria

Gates for `v6.0.0` GA (W8 RC acceptance pack):

- [ ] `padosoft/laravel-ai-act-compliance` v1.0.0 published on Packagist with WOW README (14 sections + 🚀 AI vibe-coding pack section + Live testsuite opt-in section)
- [ ] `padosoft/laravel-ai-act-compliance-admin` v1.0.0 published on Packagist with WOW README
- [ ] All 9 modules in compliance package present + tested (Disclosure / RiskRegister / DSAR / BiasMonitoring / HumanReviewTracker / Incident / Consent / Cybersecurity / ComplianceAttestation)
- [ ] All 8 screens in admin SPA present + functional + responsive (desktop primary; tablet read-only; mobile emergency view)
- [ ] WCAG 2.1 AA pass on all 8 screens (axe-core integration test green)
- [ ] AskMyDocs implements `UserDataExporter` + `UserDataDeleter` contracts; DSAR export test green (real export of test user's data through cascade)
- [ ] `App\Compliance\TokenLevelExplainability` records chunk-to-answer-token mapping during streaming (verified via E2E chat → audit query)
- [ ] `App\Compliance\RagRefusalQualityMetric` implements `CohortParityMetric` + registered in eval-harness cohort manifest
- [ ] `App\Compliance\ProvenanceChain` traces eval outcomes through retrieval to source_path (verified by integration test)
- [ ] Admin SPA cross-mounted at `/admin/ai-act-compliance` in AskMyDocs (E2E test green)
- [ ] Multi-tenant isolation R30 test extended to compliance tables (risk_register_entries / dsar_requests / consent_records / human_reviews / incident_tickets / chat_log_provenance / bias_snapshots / compliance_attestations)
- [ ] +300 tests cumulative across the cycle (sum across 3 repos)
- [ ] 3 RC tags on AskMyDocs `feature/v6.0`: `v6.0.0-rc1` (W2), `v6.0.0-rc2` (W5), `v6.0.0-rc3` (W7)
- [ ] GA tag `v6.0.0` at W8 closure (R37 + R39)
- [ ] ADR 0009 documents the extract-packages-then-integrate architecture decision
- [ ] CI green on `feature/v6.0` HEAD at merge SHA (R36 mandatory Copilot loop + CI green conjunctive)

---

## 6. OS vs Pro split for v6.0

Per memory `feedback_v45_strategic_roadmap` — Lorenzo decision 2026-05-11:

| Artefact | License | Repo |
|---|---|---|
| `padosoft/laravel-ai-act-compliance` package | **OS (MIT)** | new public repo |
| `padosoft/laravel-ai-act-compliance-admin` package | **OS (MIT)** | new public repo |
| AskMyDocs v6.0 integration (RAG-specific 20%) | **OS (MIT)** | AskMyDocs core |

**Premium add-ons** (FUTURE v6.x patch cycles, NOT v6.0 GA scope):
- `padosoft/laravel-ai-act-compliance-enterprise` (Pro) — SLA-backed + regulatory updates subscription + advanced dashboards:
  - Cohort drift alerting (real-time threshold breach notification)
  - Regulatory change auto-flagger (subscribes to EU AI Act amendment feeds)
  - DPO multi-org tenant management (for orgs running multiple compliance scopes)
  - Audit-letter template generator (SOC 2 / ISO 27001 / ISO 42001 ready)
- **Deferred to v6.x patch cycles** — not in v6.0 GA scope. Lorenzo decision: ship strong OS foundation first, monetise via add-on later.

---

## 7. Branching + release alignment (R37 + R39)

- Cut `feature/v6.0` off `main` after v5.0 GA tag lands on main
- Every sub-PR `feature/v6.0/W{n}.{letter}` targets `feature/v6.0` (NOT main)
- R39 rc tags: `v6.0.0-rc1` after W2 (compliance package skeleton + first modules), `v6.0.0-rc2` after W5 (admin SPA half-built), `v6.0.0-rc3` after W7 (RAG-specific 20% complete)
- R37 final merge: `feature/v6.0` → `main` ONCE at W8 closure → tag `v6.0.0` GA
- **Sister-package timing constraint** (memory `feedback_packages_full_v1_before_integration`): `padosoft/laravel-ai-act-compliance` AND `padosoft/laravel-ai-act-compliance-admin` MUST both reach v1.0.0 BEFORE AskMyDocs `app/` integration (not v0.x). Per plan: admin SPA tags v1.0.0 at W6 (after screens 1-8 complete); compliance package tags v1.0.0 at W8 (after RAG-specific 20% validates the contracts). AskMyDocs composer require pins `^1.0` constraints.

---

## 8. Cross-references

- `docs/v4-platform/DESIGN-SPEC-v6.0-ai-act-compliance-admin.md` — UX/UI design spec for the admin SPA (Lorenzo will hand to designer)
- `docs/v4-platform/PLAN-v5.0-agentic-platform-mcp-client.md` — predecessor cycle (provenance chain extends MCP tool call audit into compliance pipeline)
- `docs/v4-platform/AUDIT-2026-05-11-competitor-comparison.md` — §3.3 (PII redaction moat) + AI Act regulatory tailwind context
- `padosoft/laravel-pii-redactor` v1.2 — proven extraction pattern (v4.3 precedent)
- `memory:feedback_v45_strategic_roadmap` — Lorenzo decisions (extract packages + RAG-specific 20% + admin React/Vercel/shadcn directive)
- `memory:feedback_packages_full_v1_before_integration` — sister-package v1.0.0 must precede AskMyDocs integration
- `memory:feedback_open_source_readme_quality` — README WOW pattern (14 sections)
- `memory:feedback_package_live_testsuite_opt_in` — Live testsuite opt-in pattern
- `memory:feedback_padosoft_repo_ci_versions` — CI matrix PHP 8.3/8.4/8.5 + Laravel 13
- `.claude/skills/branching-strategy-feature-vx/` — R37
- `.claude/skills/rc-tag-per-week-milestone/` — R39
- `.claude/skills/cross-tenant-isolation/` — R30 (extends to compliance tables)
- AI Act regulatory references — full text + per-module article mapping in the compliance package documentation

---

## 9. Sign-off

This plan was prepared on 2026-05-11 as a planning artefact for the v6.0 cycle. Lorenzo authorised auto-mode kickoff through v4.5 + v5.0 + v6.0 end-to-end (memory `feedback_v45_strategic_roadmap` — Auto-mode roadmap kickoff section). Kickoff sequence after v5.0 GA:

1. Cut `feature/v6.0` off main (post v5.0 GA)
2. Start v6.0 W1 — cut `padosoft/laravel-ai-act-compliance` skeleton + Disclosure module
3. Each Wn closes per R39, then v6.0 GA per R37 with all 3 artefacts version-locked at v1.0.0

**Status:** PLAN — pending v5.0 GA.
