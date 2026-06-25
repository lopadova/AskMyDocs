# ADR 0020 — PII-safe ingestion & reversible vault (v8.23)

- **Status:** Accepted
- **Date:** 2026-06-25
- **Cycle:** v8.23 — Ciclo 4 (W1.A–W1.F)
- **Builds on:** the per-(tenant, project) layered-resolver pattern of
  `KbAnalysisSetting` + `ChangeAnalysisGate` / ADR 0019; the
  `padosoft/laravel-pii-redactor` package (tenant-aware reversible vault, v1.4.0);
  ADR 0009 (connector boundary / `HostIngestionBridge`); the
  `padosoft/laravel-ai-act-compliance` DSAR contracts.

## Context

The knowledge base ingests free-text (markdown, and — via the connectors — email
bodies and other documents) that can contain personal data: names, emails,
phone numbers, IDs. Before v8.23 every ingest path stored that text **raw** in
`knowledge_chunks.chunk_text` and in the embeddings — i.e. the vector store held
plaintext PII, and there was no built-in way to honour a GDPR access (Art.15) or
erasure (Art.17) request against it, nor the EU AI Act Art.50(1) transparency
duty (in force 2 Aug 2026) for a customer-facing chatbot.

The hard tension is **privacy vs. helpdesk utility**: a fully-anonymised index
can't answer "find Mario Rossi's ticket", but a raw index is a standing breach.
The state of the art (Microsoft Presidio, Skyflow, Google DLP, AWS Bedrock RAG,
Private AI) does not choose between them — it **detects → tokenises before
embedding**, keeps a **reversible per-tenant vault outside the AI path**, and
**re-identifies just-in-time, gated by role + scope**.

## Decision

1. **Detect → tokenise before embedding (redact-before-embed).** Redaction runs
   at ingestion so only typed, deterministic surrogates (`[tok:email:…]`) ever
   reach `chunk_text` + the embeddings. Two strategies: `mask` (one-way) and
   `tokenise` (reversible). Determinism means the same value yields the same
   surrogate, so "find Mario Rossi's ticket" still matches in search.

2. **Reversible vault, per-tenant, outside the AI path.** `tokenise` stores the
   token↔original map in `pii_token_maps`, scoped by `tenant_id` with a per-tenant
   salt (same PII in two tenants → different tokens; no cross-tenant
   correlation). The host binds the package `TenantResolver` to `TenantContext`
   (R30). For a persistent vault, `PII_REDACTOR_TOKEN_STORE=database`.

3. **One redaction core for both ingest paths.** `App\Services\Kb\Pii\ChunkRedactor`
   redacts the chunk drafts in the Flow `ChunkDocumentStep` (the real HTTP/CLI
   path) **and** in `DocumentIngestor::persistFromDrafts` (direct path), so the
   "never raw PII in the vector store" contract is path-independent. Redaction is
   scoped to the chunk text — the raw markdown stays the `document_hash`
   idempotency anchor and the canonical frontmatter stays parseable. A dry-run
   forces the side-effect-free `mask` (no vault writes / no PII in the preview).

4. **Per-(tenant, project) policy.** `kb_pii_settings` (`redact_enabled`,
   `strategy`) + `KbPiiPolicyResolver` layer `config ← tenant '*' ← exact
   project` (the ADR 0019 pattern). The config knob is authoritative/raw so a
   typo throws loudly (R14); a corrupt DB row coerces to the safe `mask`.

5. **JIT re-identification, gated by role + scope, fully audited.** Detokenise is
   exposed for chat-log rows (pre-existing) and KB documents (`DetokenizeService`)
   behind the `pii.detokenize` permission (dpo / super-admin); the MCP tool is
   stricter still (super-admin only — re-identifying PII through an LLM-facing
   tool warrants the tightest gate). Every completed unmask and every
   permission-denied attempt writes an immutable `admin_command_audit` row;
   the trail records counts, never raw PII.

6. **Right-to-erasure via crypto-shred (Art.17).** `SubjectErasureService`
   destroys a subject's `pii_token_maps` rows — the only link between a surrogate
   and the real person — so every surviving surrogate becomes permanently
   unresolvable, with no downstream rewrite. Gated by the new `pii.erase`
   permission; wired into the `laravel-ai-act-compliance` DSAR flow (Art.17
   delete shreds the vault by email per tenant inside the existing atomic
   transaction; Art.15 export adds a `pii_vault` snapshot).

7. **Anti-regression: re-embed on policy change + recall gate.** Changing the
   policy leaves prior chunks/embeddings stale; `DocumentIngestor::ingest(forceReembed:true)`
   skips the version-hash no-op and replaces the chunk set, fanned out per
   project by `ReembedProjectService`. The `rag-regression` CI gate (golden Q&A
   set through the live RAG pipeline) guards tokenisation embedding-drift.

8. **EU AI Act Art.50(1) transparency.** The `ai.disclosure` middleware appends
   the `X-AI-Disclosure` header on every chat route (JSON API + web SSE).

9. **Tri-surface (R44) over each core, default-OFF (R43).** Every capability —
   policy, detokenise, erase, re-embed — is reachable from PHP/CLI, HTTP (R32
   matrix), and MCP, over one shared service; all redaction knobs default OFF so
   existing deployments are byte-for-byte unchanged until they opt in.

## Consequences

- The KB is PII-safe by default once enabled: the vector store holds only
  surrogates; an authorised operator (or a DSAR) re-identifies on demand.
- Tokenisation is **pseudonymisation** (GDPR Art.4(5)) — the index remains
  personal data, but the vault enables Art.15 access, Art.17 crypto-shred, and
  the helpdesk's deterministic search at once.
- The four MCP tools added across the cycle (`KbPiiPolicyTool`,
  `KbDetokenizeTool`, `KbEraseSubjectTool`, `KbReembedProjectTool`) take the
  roster 40 → 44, locked by `KnowledgeBaseServerRegistrationTest`.
- Known trade-offs (documented): deterministic tokens expose frequency/equality —
  mitigated by per-tenant keying; tokenisation shifts embedding vectors —
  mitigated by re-embed + the recall gate; the inline path leaves raw markdown on
  disk as the user's source-of-truth while the vector store (the AI boundary) is
  the protected surface; connector docs whose disk content is already tokenised
  re-chunk to the same tokens on re-embed.

## Surfaces

| Capability | PHP / CLI | HTTP | MCP |
|---|---|---|---|
| Ingest policy | `kb:pii-policy` · `KbPiiPolicyResolver` | `GET`/`PUT /api/admin/pii/policy` | `KbPiiPolicyTool` |
| Detokenise (KB doc) | `kb:detokenize-document` · `DetokenizeService` | `POST /api/admin/pii/documents/{id}/detokenize` | `KbDetokenizeTool` |
| Erasure (Art.17) | `kb:erase-subject` · `SubjectErasureService` | `POST /api/admin/pii/erase-subject` | `KbEraseSubjectTool` |
| Re-embed | `kb:reembed-project` · `ReembedProjectService` | `POST /api/admin/pii/reembed` | `KbReembedProjectTool` |
| Transparency | — | `X-AI-Disclosure` on every chat route | — |
