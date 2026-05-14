# ADR 0011 — v6.0 AI Act compliance integration via extracted packages

**Status:** Accepted (2026-05-14)
**Cycle:** v6.0
**Supersedes:** none
**Related:** ADR 0008 (v4.6 connector extraction — proven extraction pattern)

## Context

The EU AI Act enters full force in 2026–2027. AskMyDocs needs to ship
out-of-the-box AI Act readiness to retain its enterprise positioning,
AND the broader Laravel ecosystem has nothing equivalent — every Laravel
AI builder will need disclosure middleware, DSAR queues, bias monitoring,
incident state machines, and DPO attestation tooling.

Two natural designs:

1. **Embed all compliance code inside AskMyDocs** — fast to ship, but
   keeps the toolkit hidden from the wider Laravel community, doesn't
   strengthen Padosoft's brand, and forces a future extraction once
   another customer asks for the same functionality outside AskMyDocs.
2. **Extract two generic OS packages** (`padosoft/laravel-ai-act-compliance`
   + `padosoft/laravel-ai-act-compliance-admin`) and have AskMyDocs
   integrate them like a normal consumer — slower up front, but the
   toolkit becomes a brand-building artefact for Padosoft and AskMyDocs
   gets the credibility of being its own compliance package's first
   customer (eat-own-dog-food).

PLAN-v6.0 committed to option 2 (memory: `feedback_v45_strategic_roadmap`).

## Decision

The v6.0 cycle ships three artefacts:

1. **`padosoft/laravel-ai-act-compliance`** v1.0.x — 9 backend modules
   (Disclosure / RiskRegister / DSAR / BiasMonitoring / HumanReview /
   Incident / Consent / Cybersecurity / ComplianceAttestation), all
   AI Act + GDPR article-referenced. Host implements two contracts:
   `UserDataExporter` + `UserDataDeleter`. Bias module exposes
   `CohortParityMetric` interface; host plugs in concrete metric
   implementations.
2. **`padosoft/laravel-ai-act-compliance-admin`** v1.1.x — React 19 +
   TypeScript SPA with 8 screens (Overview / DSAR / Consent / Risks /
   Incidents / Bias / DPO / Settings), pixel-ported from the Claude
   Design handoff bundle the operator provided on 2026-05-14. Mounts
   into any Laravel app via the same BrowserRouter-basename cross-mount
   pattern used by pii-redactor-admin (v4.4/W2) and eval-harness-ui
   (v4.4/W3).
3. **AskMyDocs v6.0** — adds three host-side RAG-specific compliance
   classes on top of the generic packages:
   - `App\Compliance\TokenLevelExplainability` — decorator over
     `MessageStreamController::streamHappyPath()` that records
     chunk-to-answer-token mappings into `chat_log_provenance` for
     every assistant turn. Survives hard deletes of `knowledge_chunks`
     via denormalized `source_path` + SET NULL FK semantics.
   - `App\Compliance\RagRefusalQualityMetric` — implements the package
     `CohortParityMetric` interface. Tracks refusal rate per cohort
     (project / provider / model) and surfaces drift to the admin SPA
     Bias Monitor screen via the package `BiasMonitorService`.
   - `App\Compliance\ProvenanceChain` — joins `chat_log_provenance`
     rows + `knowledge_chunks` + `knowledge_documents` (withTrashed)
     so an auditor can reverse-trace any answer span back to its
     source-of-truth document + frontmatter author. Returns a stable
     JSON envelope consumable by the package admin SPA DPO Console.

## Consequences

### Positive

- **Brand consolidation.** Every Laravel dev building AI apps will
  install the package. Adds to the Padosoft series alongside
  `laravel-ai-regolo`, `laravel-pii-redactor`, `laravel-flow`,
  `eval-harness`. Sister-package roadmap stays on track.
- **AskMyDocs credibility.** It uses its own compliance toolkit. Proof
  that the package isn't a paper artefact.
- **First-mover advantage.** No equivalent exists in the Laravel
  ecosystem; Python has Lakera Guard / Fairlearn / Aequitas, Laravel
  has nothing. We claim the niche before anyone else does.
- **Sister-package timing constraint honoured.** Per memory
  `feedback_packages_full_v1_before_integration`, the admin package
  reached v1.0 in the previous cycle and v1.1 here, and the core
  compliance package is at v1.0.x — AskMyDocs `composer require`s
  both at `^1.0`, the host integration never depends on a v0.x dev
  branch.
- **Cross-mount pattern proven.** Two prior cross-mounts
  (pii-redactor-admin, eval-harness-ui) make the iframe integration
  + BrowserRouter basename setup low-risk.

### Negative

- **Three artefacts to coordinate at GA.** Each release cycle now has
  to lock package versions in composer.json before AskMyDocs tags.
  Mitigated by R39 RC tags carrying the exact composer-lockable SHAs.
- **Cross-mount surface (iframe) introduces a separate auth boundary.**
  Same Sanctum cookie share works because both endpoints live under
  the same origin; the cross-mount E2E spec covers the boundary.
- **Token-level explainability adds an insert per chunk per turn.**
  Best-effort + transaction-wrapped + ~5–10 inserts per turn typical;
  measured overhead <8ms in the development baseline. Disabled via
  `COMPLIANCE_TOKEN_EXPLAINABILITY_ENABLED=false` if the operator
  wants to opt out.

### Trade-offs accepted

- The host compliance classes are tightly coupled to the package
  interfaces (`CohortParityMetric`). If the package evolves the
  interface, the host has to follow. Mitigation: semantic
  versioning + R39 RC gates + the package documents the interface
  as part of its v1.0 stability contract.

## Implementation notes

- Three artefacts ship in lock-step. AskMyDocs v6.0 GA tags only after
  the package versions are pinned in composer.json (no `dev-main`).
- The admin cross-mount serves at `/admin/ai-act-compliance` (env
  override: `COMPLIANCE_ADMIN_MOUNT_PREFIX`). The package PHP service
  provider publishes the Vite-built bundle at that prefix.
- The RAG-specific compliance classes live under `app/Compliance/`
  intentionally — they're host glue, not domain logic that belongs
  in the package. The package interfaces stay agnostic to RAG.

## References

- `docs/v4-platform/PLAN-v6.0-ai-act-compliance.md` — full cycle plan
- `padosoft/laravel-ai-act-compliance` — backend modules
- `padosoft/laravel-ai-act-compliance-admin` v1.1 — admin SPA
- `app/Compliance/{TokenLevelExplainability,RagRefusalQualityMetric,ProvenanceChain}.php` — host wiring
- `database/migrations/2026_05_13_000003_create_chat_log_provenance_table.php`
- memory: `feedback_v45_strategic_roadmap`, `feedback_packages_full_v1_before_integration`, `project_v60_admin_panel_design_spec`
