# ADR 0003 — Human-gated promotion pipeline (raw → curated → canonical)

- **Date:** 2026-04-22
- **Status:** accepted
- **Deciders:** platform team
- **Related:** [ADR 0001](./0001-canonical-knowledge-layer.md), [ADR 0002](./0002-knowledge-graph-model.md)

## Context

OmegaWiki's slogan "every skill reads from the wiki and writes back to the wiki"
is attractive but unsafe for enterprise adoption. LLMs hallucinate. Allowing a
Claude skill to directly write canonical markdown to Git would break compliance,
auditability, and change-management expectations.

We still want to capture knowledge that emerges from conversations, incidents,
code reviews and turn it into canonical documents — just with a human gate.

## Decision

Three-stage promotion API, all Sanctum-protected:

1. **`POST /api/kb/promotion/suggest`** — LLM (via AiManager) extracts candidate
   artifacts from a transcript / chat log. Returns structured JSON. **Writes nothing.**
2. **`POST /api/kb/promotion/candidates`** — validates a proposed draft against
   the CanonicalParser schema. Returns `{valid: bool, errors: []}`. **Writes nothing.**
3. **`POST /api/kb/promotion/promote`** — writes the Markdown file to the KB
   disk (via CanonicalWriter, R4-compliant return-value checking) and dispatches
   `IngestDocumentJob`. Returns `{status: accepted, path, doc_id}` with HTTP 202.

Claude skills (`promote-decision`, `promote-module-kb`, `promote-runbook`,
`link-kb-note`, `session-close`) stop at steps 1 + 2. **They never call step 3.**
A human reviews and either commits to Git (triggering the GitHub Action → POST
/api/kb/ingest) or invokes `kb:promote` CLI (operator-side, not LLM-driven).

Every promotion writes an audit row in `kb_canonical_audit`.

## Rationale

- **Trust boundary:** LLM output never mutates source-of-truth without a human.
  This is the compliance anchor.
- **Auditability:** every step logs to `kb_canonical_audit` with actor (user_id
  or command name), before/after JSON, and event_type.
- **Back-pressure:** humans stay in the loop — quality of canonical docs does
  not degrade with scale.
- **R4 (no silent failures):** `CanonicalWriter` checks `Storage::put()` return
  value and aborts with a proper 5xx if disk write fails.

## Consequences

**Positive**
- Canonical KB cannot be silently polluted by LLM hallucinations.
- Every promotion is auditable end-to-end.
- Skills remain useful (draft generation, validation, suggestion) without
  requiring trust to grant them write privilege.

**Negative / watch-out**
- Humans must review proposed drafts — this is slow. Mitigation: skills produce
  high-quality drafts so review is quick.
- Two write surfaces exist: the Git-based flow (PR → GitHub Action → ingest)
  and the direct CLI (`kb:promote`). Document clearly which to use when.

## Alternatives considered

- **Direct skill write to Git** — rejected: no trust boundary, no audit, LLM
  hallucinations propagate.
- **Automatic promotion when LLM confidence > threshold** — rejected: confidence
  scores from LLMs are not reliable enough for this gate.
- **Human review inside the chat UI** — deferred to a later phase (UI work
  out of current scope).

## Implementation pointers

- Controller: `app/Http/Controllers/Api/KbPromotionController.php`.
- Writer: `app/Services/Kb/Canonical/CanonicalWriter.php` (R4 compliant).
- Suggest service: `app/Services/Kb/Canonical/PromotionSuggestService.php`.
- Validator: `app/Services/Kb/Canonical/CanonicalParser.php`.
- Operator CLI: `app/Console/Commands/KbPromoteCommand.php`.
- Audit trail: `kb_canonical_audit` table + `App\Models\KbCanonicalAudit`.
- Skill templates: `.claude/skills/kb-canonical/{promote-decision,promote-module-kb,promote-runbook,link-kb-note,session-close}/SKILL.md`.
