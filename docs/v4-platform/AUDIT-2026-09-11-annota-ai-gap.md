# AUDIT — Annota AI gap analysis (2026-09-11)

**Scope**: a definitive `AskMyDocs has X / Annota AI has X / where the gap is`
matrix for a single competitor that is moving *into* our category from the
data-preparation side, to decide what the v8.36+ cycle should build.

**Method**:
- AskMyDocs column grounded by `git grep` on `origin/main` at **v8.35.0**
  (`51dd1b3`). Every ✅ cell carries a file-path citation; every ❌ carries
  the grep that came back empty and what would be needed.
- Annota AI column grounded by `WebFetch` against the official product,
  pricing and trust pages, plus the **full transcript of the launch video**
  (Simone Rizzo, YouTube `S4kd1IRSccY`, supplied verbatim by the product
  owner). Where the page and the video disagree, the page wins and the video
  is marked *(video)*.
- **Fetched 2026-09-11** (all `200` unless noted):
  - `https://annota.ai/` ✅ · `/pricing/` ✅ · `/documents/` ✅ · `/annotate/` ✅
  - `/models/` ✅ · `/brain/` ✅ · `/integrations/` ✅ · `/examples/` ✅
  - `/about/` ✅ · `/privacy-security/` ✅
  - `/docs/` ❌ (404) · `/mcp/` ❌ (404) · `/blog/` ❌ (404) — the MCP guide
    lives inside the app (`app.annota.ai`), behind sign-in.
- **Disambiguation**: `annota.online` is an unrelated local-first note-taking
  app with flashcards and "Local Only / Cloud Pro" tiers. Search engines mix
  the two. Nothing below refers to it.

**Honesty marker**: Annota AI is three weeks out of stealth (video: "dopo 10
mesi di lavoro, tutto stealth"). Several cells are `coming soon` on their own
pages and are marked as such — a competitor's roadmap is not a feature, and
neither is ours. Cells reflect *public verifiability*.

---

## Section 1 — Who they are

**Annota AI** — `https://annota.ai` — *"Your data. Your AI."* An Italian
company (Annota S.r.l., Scaleway EU infrastructure, NVIDIA Inception) whose
thesis is stated in the first minute of the launch video: *models are a
commodity, your data is not; 80% of an AI project is preparing, cleaning and
labelling data.* The product is a **data-preparation platform with human
review**, in four modules:

| Module | What it does | Status |
|---|---|---|
| **Annotate** | Image datasets: boxes, polygons, *Magic Select* (click → mask) and *Magic Annotate* (open-vocabulary auto-labelling over a whole dataset with a confidence threshold; classes in English). Review, approve, immutable **dataset versions**, export **COCO**. | shipped |
| **Documents** | PDF / PNG / JPEG → **OCR** with layout understanding: text, tables, figures extracted into an `images/` folder with references in the Markdown, formulas as LaTeX *(video)*. **Side-by-side** review: original page left, raw/preview Markdown right, edit, save, mark reviewed. Export **Markdown ZIP** or **LLM Wiki**. | shipped |
| **Models** | Train a YOLO object detector on a dataset version, on their EU cloud; per-epoch metrics; download weights + JSON manifest + a `README.md` written for a coding agent. Segmentation / classification / **LLM fine-tuning**: coming soon. Access by approval form. | private beta |
| **Brain** | A hosted private knowledge base: *"ask questions in natural language over your approved content"*. | **coming soon** |

**The LLM Wiki export** is the piece that touches us. It is the Karpathy
pattern packaged: `raw/` (the reviewed Markdown sources), `wiki/` with
`index.md` + `log.md`, and `AGENTS.md` / `CLAUDE.md` / `README.md` carrying
**skills that instruct the user's own coding agent to compile the wiki**
(video 8:25–9:20). The user opens Claude Code / Codex / OpenCode on the
folder, says *"start the ingestion"*, and the agent builds the graph locally;
Obsidian is used only as a graph viewer. **Annota does not host or query the
wiki** — the "second brain" runs on the customer's agent and the customer's
tokens. Brain, when it ships, will be the hosted half.

**MCP** — a remote Streamable-HTTP server with OAuth sign-in, 13 tools:
`list_organizations`, `list_datasets`, `list_assets`, `list_labels`,
`create_annotation`, `update_annotation`, `list_document_pages`,
**`update_document_page`**, **`update_asset_review_status`**,
`start_auto_annotate_run`, `get_auto_annotate_run`, `create_export`,
`get_export`. Same permissions and credits as the app. Note the two bold
tools: an agent can **rewrite OCR text and mark an asset reviewed with no
human gate**. That is a design choice we will not copy (§3).

**Pricing** — credits. Free €0 / 500 cr·mo, 1 member, 2 datasets. Pro €18 /
3,000 cr. Scale €90 / 20,000 cr. Enterprise custom with on-prem / air-gapped.
1,000 OCR pages ≈ €4 → €1.80 by tier; 1,000 auto-annotated images ≈ €10 →
€4.50. Free-tier content may be used, anonymised, to improve their models;
paid tiers not.

**Trust** — SOC 2, ISO 27001, ISO 42001 all **in progress**; SSO and audit
logs **coming soon**; "EU core infrastructure … this does not mean every
processing activity takes place only in the EU". GDPR and AI Act are
*supported by* data-quality and traceability features — a claim, not a module.

---

## Section 2 — The matrix

Legend: ✅ shipped · ⚠️ partial · ❌ absent · 🔜 announced, not shipped.

### 2.1 Where Annota AI is ahead

| Capability | AskMyDocs v8.35.0 | Annota AI | Gap |
|---|:---:|:---:|---|
| **OCR of scans and images** | ❌ | ✅ | `git grep -liE 'tesseract\|\bocr\b' -- 'app/**/*.php'` → one hit, a comment in `OfficeDocChunker`. `PdfConverter` (`app/Services/Kb/Converters/PdfConverter.php:50-57`) is `smalot/pdfparser` with a `pdftotext` fallback — **text-layer only**. A scanned PDF yields empty pages or a `RuntimeException`. `SourceType::supportedMimes()` (`app/Support/Kb/SourceType.php`) has **no `image/*`**: a PNG/JPEG upload is a 422. DOCX embedded images: "planned for v3.1 with the vision-LLM pipeline" (`README.md` §Multi-format ingest) — never landed. `ENTERPRISE-COMPLETENESS-ROADMAP.md` R5 names "text/OCR pipeline" as the fix for Google Slides. |
| **Layout-aware conversion** (tables, figures to `images/`, LaTeX formulas) | ⚠️ | ✅ | `PdfConverter` emits `# {basename}` + `## Page N` text; no figure extraction, no table structure beyond what the text layer carries, no formulas. `DocxConverter` maps headings and pipe-tables, drops images. |
| **Side-by-side correction of extracted text** | ❌ | ✅ | No "original vs extracted" surface. The canonical inline editor (`README.md` §What it is) edits *canonical* docs, not conversions. Worse: the converted Markdown is **not stored** — `config/kb.php:329-359` `source_retention` and `knowledge_documents.markdown_path` are "SCHEMA/CONFIG FOUNDATION" (ADR 0014 §Consequences); `git grep -n markdown_path -- 'app/**/*.php'` → only `KnowledgeDocument.php:25` (`$fillable`). Document text is `reconstructContent()` from chunks — lossy. |
| **Per-asset review status** (unreviewed → reviewed → approved) | ⚠️ | ✅ | We have the *canonical* promotion pipeline (ADR 0003) and the `auto`/`human` tier (ADR 0014) — a review gate on **knowledge**, not on **conversion fidelity**. |
| **Immutable document/dataset versions** | ⚠️ | ✅ | **Corrected after the first draft — versions exist.** *Cloud Time Machine* (v8.7/W5): `DocumentIngestor::archivePreviousVersions` keeps every re-ingested `knowledge_documents` row as `archived` **with its chunks**; `app/Services/Kb/Versioning/DocumentVersionService.php` lists the `(tenant, project_key, source_path)` family, diffs two versions (`App\Support\MarkdownDiff`, in-house LCS) and restores one (status flip + canonical-identity transfer + `kb_canonical_audit` row); `KbDocumentVersionController` serves `GET …/documents/{id}/versions`, `/versions/diff?from&to`, `POST …/restore-version`; **Admin → Time Machine**; `kb:prune-archived-versions` caps the family (`KB_KEEP_ARCHIVED_VERSIONS`, default 10). What Annota has and we do not: (a) the version body is `reconstructContent()` from chunks — no frontmatter, no images, chunker-transformed text — because the converted artifact is not stored, so the diff compares two *reconstructions*; (b) a version is born only on **re-ingest**, never on a **correction** (there is no correction surface); (c) no `actor`/`reason` on a version. The first draft grepped `database/migrations/` for `version|revision` and missed it: the model is the archived row, not a versions table. |
| **Content export as a portable workspace** (Markdown ZIP · LLM Wiki with `AGENTS.md`/`CLAUDE.md` skills · Obsidian-compatible) | ❌ | ✅ | `ConnectorExportCommand` exports connector *configuration*. The Auto-Wiki (v8.11) — hub, indices, `log`, lint, BFS — lives in Postgres and is reachable only via API/MCP (`KbWikiHubTool`, `kb_wiki_indices`). "Content export/portability" is in the README `Future` row since the Affine gap audit. |
| **MCP tools on the preparation loop** (edit page, set review status, start job, create/get export) | ❌ | ✅ | 48 tools in `app/Mcp/Tools/`, all downstream of ingest (retrieval, canonical, wiki, engagement, finops, connectors). None lets an agent see or correct a conversion. |
| Image labelling · Magic Select / Annotate · COCO · YOLO training | ❌ | ✅ / 🔜 | **Out of domain** — see §3. |
| Per-operation cost shown before commit (credits on the upload screen) | ⚠️ | ✅ | FinOps meters after the fact (`app/FinOps/*`); the upload modal (`kb-staging` → commit) shows no estimate. |

### 2.2 Where AskMyDocs is ahead

| Capability | AskMyDocs v8.35.0 | Annota AI |
|---|:---:|:---:|
| Hosted grounded Q&A with citations, streaming, confidence | ✅ (`/api/kb/chat`, Vercel AI SDK UI) | 🔜 *Brain* |
| Multi-tenant + project isolation + **source ACL mirroring** | ✅ (R30/R31, R33 `ScopeAllowlistSql`, ADR 0028 v8.32–34) | ⚠️ orgs + members; SSO/audit **coming soon** |
| Connectors (Drive, Notion, OneDrive, Evernote, Fabric, Confluence, Jira, IMAP, API) | ✅ 9 | ❌ upload only |
| Canonical layer, human-gated promotion, knowledge graph, anti-repetition | ✅ (ADR 0001–0003, `GraphExpander`, `RejectedApproachInjector`) | ❌ |
| Self-compiling Auto-Wiki behind `human > auto > raw` firewall, hosted for the team | ✅ (ADR 0014) | ❌ — wiki compiled by *each user's* agent, locally, on the user's tokens; no shared compiled state |
| PII redaction before embedding + reversible per-tenant vault + crypto-shred | ✅ (ADR 0020) | ❌ |
| EU AI Act as **shipped modules** (risk register, oversight tracker, Art.50 disclosure, DSAR) | ✅ (`laravel-ai-act-compliance`) | ⚠️ "features support AI Act workflows" |
| Ingest-time **provenance** and injection boundary (external text may be quoted, never drive a tool call) | ✅ (ADR 0028) | ❌ — `update_document_page` lets an agent rewrite content with no gate |
| Eval gate in CI + nightly LLM-as-judge + retrieval metrics | ✅ (`padosoft/eval-harness`) | ❌ |
| Spend governance (budgets, policies, chargeback, forecast) | ✅ (`laravel-ai-finops`) | ⚠️ credits |
| Audit trail, tamper-evident compliance reports | ✅ | 🔜 |
| Delegated identity, mandates, scheduled agents that pause to ask | ✅ (ecosystem: `laravel-iam-agents`, `laravel-routines`) | ❌ |
| Licence / hosting | MIT, self-host anywhere | SaaS; on-prem only on Enterprise |

---

## Section 3 — Reading the two tables together

1. **The overlap is narrow and it is exactly one seam: the conversion step.**
   Annota is strong from *file* to *reviewed Markdown*; we are strong from
   *Markdown* to *governed answer*. Their Brain is "coming soon"; our OCR is
   zero. The race is symmetric, and whoever closes it first owns
   *scan → governed knowledge → agent* end to end.

2. **The asymmetry favours us on effort.** The governance half took Annota a
   "coming soon" and us eight major versions. The conversion half is now a
   commodity: docling, marker, PaddleOCR and Mistral OCR are open-source or
   API-priced frontier models — the same "open-source frontier" Annota says
   it uses. Buying that half is months; buying ours is years.

3. **Their MCP design is a liability we should not copy.** `update_document_page`
   and `update_asset_review_status` let an agent change content and mark it
   reviewed. Under ADR 0028 an OCR'd inbound letter is externally authored
   text; an agent influenced by it must be able to **propose** a correction,
   never commit one. That is also the ecosystem invariant (*the agent
   proposes, a person confirms*) and it is a selling point, not a limitation.

4. **Their export is a strength to match, then beat.** Shipping `raw/` +
   skills makes the customer's agent pay to compile. We can export the wiki
   **already compiled, tiered and graph-linked** (`tier: human|auto`,
   `evidence:`, `provenance:`, rejected-approaches included), ACL-filtered,
   hash-manifested, with a `.mcp.json` pointing back at the live server — so
   the folder is both a file workspace and a connection, and a round-trip
   import returns edits as promotion candidates.

5. **Do not chase labelling or training.** Polygons, COCO and YOLO are the
   Roboflow / CVAT / Label Studio market. The one adjacency worth a small
   move is a `vision` column in Tabular Review for product-image attribute
   extraction with human review (gescat).

The plan that follows from this audit:
[`PLAN-v8.36-document-intelligence-and-llm-wiki-export.md`](PLAN-v8.36-document-intelligence-and-llm-wiki-export.md).
