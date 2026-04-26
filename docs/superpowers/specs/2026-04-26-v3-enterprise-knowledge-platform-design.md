# AskMyDocs v3 — Enterprise Knowledge Platform Design

**Date:** 2026-04-26
**Status:** Draft for review (post-brainstorming, pre-implementation-plan)
**Author:** Claude (brainstorming session) + Lorenzo Padovani
**Scope:** Trasforma AskMyDocs da *internal markdown RAG* a **Enterprise Knowledge Platform** competitivo con Glean / Mendable / AIR / Hebbia, mantenendo single-tenant on-prem deployment per cliente.

---

## 1. Goals & Non-Goals

### Goals
1. **Anti-hallucination enterprise-grade**: refusal deterministico, confidence score, citation verifier opzionale (async).
2. **Multi-format ingest**: PDF, DOCX, XLSX, CSV, RTF, ODT, TXT, MD, immagini (OCR + opzionale vision-LLM). Pipeline pluggable per estensioni custom.
3. **Source connectors**: Google Drive, OneDrive, Asana, IMAP — con interfaccia estensibile.
4. **Transparent reasoning**: per-source relevance score, search-strategy breakdown, replay tool admin.
5. **Vector store abstraction**: `VectorStoreInterface` con pgvector adapter (ES/Qdrant on-demand futuro).
6. **Filtri enterprise**: tags, `@mention`, project, source_type, canonical_type, folder glob, date range — esposti in API e UI.
7. **Test coverage**: ogni feature ships con E2E real-data (R12/R13). Funzioni low-level testate con dati realistici, non mocks.

### Non-Goals (out of scope v3)
- Multi-tenant SaaS architecture (resta single-tenant per cliente).
- Per-user OAuth come default (resta opzione facoltativa per v3.3+).
- Elasticsearch/Qdrant adapter (interfaccia pronta, adapter solo on-demand).
- Real-time collaboration (no Slack-like presence/typing).
- AI agent loops / tool-use chained (no autonomous research agent).

---

## 2. Architettura — i 6 pillar

### 2.1 Pilastro A — Pipeline d'ingestione modulare

```
SourceConnector  →  Converter  →  Chunker  →  EnricherPipeline  →  Embedder  →  VectorStore
   ↑                  ↑             ↑              ↑                  ↑           ↑
   pluggable         pluggable     pluggable     pluggable           pluggable   pluggable
   per source        per MIME      per MIME      configurable         provider    backend
                                                 per progetto
```

**Interfacce nuove:**

```php
namespace App\Services\Kb\Contracts;

interface SourceConnectorInterface
{
    public function name(): string;           // 'google-drive', 'asana', 'imap', 'local'
    public function authenticate(ConnectorCredential $cred): void;
    public function listChanges(?string $cursor, ConnectorScope $scope): ConnectorChangeSet;
    public function fetchOne(string $externalId): SourceDocument;  // bytes + metadata + url
}

interface ConverterInterface
{
    public function supports(string $mimeType): bool;
    public function convert(SourceDocument $doc): ConvertedDocument;  // markdown + media[] + extractionMeta
}

interface ChunkerInterface
{
    public function supports(string $sourceType): bool;
    public function chunk(ConvertedDocument $doc): array;  // ChunkDraft[]
}

interface EnricherInterface
{
    public function name(): string;          // 'language', 'auto-tag', 'summary', 'entities', 'topic'
    public function appliesAt(EnrichmentLevel $level): bool;
    public function enrich(ChunkDraft $chunk, EnrichmentContext $ctx): ChunkDraft;
}

interface VectorStoreInterface
{
    public function describe(): VectorStoreCapabilities;
    public function semanticSearch(array $queryVec, RetrievalFilters $f, int $k, float $minSim): Collection;
    public function fullTextSearch(string $q, RetrievalFilters $f, int $k, ?string $lang): Collection;
    public function persist(int $docId, string $projectKey, array $chunks, array $embeddings): void;
    public function delete(int $docId): void;
}
```

**Registry config-driven** (`config/kb-pipeline.php`):

```php
return [
    'connectors' => [
        'local' => App\Connectors\LocalDiskConnector::class,
        'google-drive' => App\Connectors\GoogleDriveConnector::class,
        'asana' => App\Connectors\AsanaConnector::class,
        'onedrive' => App\Connectors\OneDriveConnector::class,
        'imap' => App\Connectors\ImapConnector::class,
    ],
    'converters' => [
        App\Converters\MarkdownPassthrough::class,    // .md, .markdown
        App\Converters\TextPassthrough::class,         // .txt
        App\Converters\PdfConverter::class,            // .pdf  (smalot/pdfparser + pdftotext fallback)
        App\Converters\DocxConverter::class,           // .docx (phpoffice/phpword)
        App\Converters\XlsxConverter::class,           // .xlsx, .csv (phpoffice/phpspreadsheet)
        App\Converters\RtfConverter::class,            // .rtf
        App\Converters\OdtConverter::class,            // .odt
        App\Converters\ImageConverter::class,          // .png, .jpg → OCR + opt vision-LLM
    ],
    'chunkers' => [
        App\Chunkers\MarkdownSectionChunker::class,    // existing MarkdownChunker, extracted to interface
        App\Chunkers\PdfPageChunker::class,            // chunks per page con header/footer detection
        App\Chunkers\TableChunker::class,              // CSV/XLSX → chunks per logical block (header + N rows)
        App\Chunkers\PlainTextChunker::class,
        App\Chunkers\ImageCaptionChunker::class,       // image caption + OCR text → 1 chunk
    ],
    'enrichers' => [
        // Sempre attivi (level >= NONE):
        App\Enrichers\LanguageDetector::class,
        // Level >= BASIC:
        App\Enrichers\AutoTagger::class,
        // Level == FULL:
        App\Enrichers\SummaryGenerator::class,
        App\Enrichers\EntityExtractor::class,
        App\Enrichers\TopicClassifier::class,
    ],
    'vector_store' => [
        'driver' => env('KB_VECTOR_DRIVER', 'pgvector'),
        'drivers' => [
            'pgvector' => App\VectorStores\PgvectorStore::class,
            // 'elasticsearch' => App\VectorStores\ElasticsearchStore::class, // future
        ],
    ],
];
```

**Estensione utente-side:** README documenta (sezione "Extending"):
- Implementare `ConverterInterface` per nuovo MIME (esempio: SVG, HEIC, EPUB).
- Implementare `ChunkerInterface` per strategia custom (esempio: code-aware splitter per repository).
- Implementare `EnricherInterface` per arricchimenti custom (esempio: PII redaction, glossary tagging).
- Implementare `SourceConnectorInterface` per connettore custom (esempio: Confluence, Jira, ServiceNow, Salesforce, custom HTTP).

---

### 2.2 Pilastro B — Anti-hallucination a strati (decisione: composite + opt-in async verifier)

**Strato 0 — Refusal deterministico (always-on):**
```php
// in KbChatController
$result = $kbSearch->searchWithContext($q, $projectKey, $filters);
$grounded = $result->primary->filter(fn($c) => $c->vector_score >= config('kb.refusal.min_chunk_similarity', 0.45));
if ($grounded->count() < config('kb.refusal.min_chunks_required', 1)) {
    return response()->json([
        'answer' => __('kb.no_grounded_answer', ['lang' => $userLang]),
        'citations' => [],
        'confidence' => 0,
        'refusal_reason' => 'no_relevant_context',
        'meta' => [...],
    ]);
}
// else: continue to LLM call
```

**Strato 1 — Prompt rules (rinforzati):**
- Mantieni `kb_rag.blade.php` "use ONLY context".
- Aggiungi: *"If you cannot cite a chunk for a claim, omit the claim. If the entire question cannot be answered from context, respond exactly with `__NO_GROUNDED_ANSWER__` and stop."*
- Controller intercetta `__NO_GROUNDED_ANSWER__` e converte in refusal payload.

**Strato 2 — Confidence composite (always-on):**
```php
$confidence = round(100 * (
    0.40 * mean($grounded->vector_score) +
    0.20 * threshold_margin($grounded->vector_score, $minSim) +
    0.20 * chunk_diversity($grounded) +     // distinct documents / total chunks
    0.20 * citation_density($answer, $citations) // citations per 100 words
));
```
Esposto in `meta.confidence` (0-100), badge UI:
- `>= 80` verde "high confidence"
- `50-79` giallo "moderate"
- `< 50` rosso "low — verify manually"

**Strato 3 — Citation verifier ASYNC (opt-in, default-ON):**
- Flag globale `KB_CITATION_VERIFIER_ENABLED=true` (default), per-tenant override.
- Flag per-progetto `verify_claims: bool` (default = global).
- Pipeline:
  1. Risposta LLM ritorna a FE immediatamente (no latency hit).
  2. Job background `VerifyCitationsJob` chiama provider configurabile (default Haiku/Mini, separato da chat provider per cost optimization) con `{answer_sentences[], cited_chunks[]}`.
  3. Risultato persisted in `messages.metadata.citation_verification = [{sentence_id, support_score, verdict: supported|partial|unsupported}]`.
  4. Push via WebSocket (Laravel Reverb — locked-in §7.4) al canale `conversation.{id}` evento `citation.verified`.
  5. UI riceve update, sottolinea frasi "unsupported" con tooltip "AI couldn't verify this claim against cited sources".

**Schema additions:**
```php
// migration: add_grounding_columns_to_messages
$table->json('citation_verification')->nullable();  // [{sentence_idx, score, verdict}]
$table->unsignedTinyInteger('confidence')->nullable(); // 0-100
$table->string('refusal_reason')->nullable();          // 'no_relevant_context' | 'low_confidence' | null
```

**Config (`config/kb.php`):**
```php
'refusal' => [
    'min_chunk_similarity' => env('KB_REFUSAL_MIN_SIMILARITY', 0.45),
    'min_chunks_required' => env('KB_REFUSAL_MIN_CHUNKS', 1),
],
'verifier' => [
    'enabled' => env('KB_CITATION_VERIFIER_ENABLED', true),
    'provider' => env('KB_CITATION_VERIFIER_PROVIDER', 'anthropic'),
    'model' => env('KB_CITATION_VERIFIER_MODEL', 'claude-haiku-4-5-20251001'),
    'min_support_score' => env('KB_CITATION_VERIFIER_MIN_SCORE', 0.6),
],
```

---

### 2.3 Pilastro C — Connector framework (workspace-OAuth default)

**Decision locked-in:** workspace/service-account OAuth come default. `connector_credentials.user_id` nullable per supportare per-user OAuth come modalità futura senza schema change.

**Schema additions:**

```php
// migration: extend_knowledge_documents_for_connectors
Schema::table('knowledge_documents', function ($table) {
    $table->string('connector_type', 32)->nullable()->index();  // local|google-drive|onedrive|asana|imap
    $table->string('external_id', 255)->nullable();             // drive fileId / asana gid / imap msgUid
    $table->text('external_url')->nullable();                   // deep link click-through
    $table->string('content_storage', 16)->default('stored');   // stored|link_only
    $table->timestamp('last_synced_at')->nullable();
    $table->unique(['project_key', 'connector_type', 'external_id'], 'uq_kb_doc_connector_ext');
});

// migration: create_connector_credentials_table
Schema::create('connector_credentials', function ($table) {
    $table->id();
    $table->string('connector_type', 32)->index();              // 'google-drive', 'asana', ...
    $table->string('label', 120);                                // 'HR Workspace Drive', 'Engineering Asana'
    $table->foreignId('user_id')->nullable()->constrained();     // null = workspace, int = per-user
    $table->text('encrypted_token');                             // CredentialVaultInterface (default LaravelCryptVault adapter — Crypt::encrypt; locked-in §7.3)
    $table->text('encrypted_refresh_token')->nullable();
    $table->json('scopes')->nullable();
    $table->timestamp('expires_at')->nullable();
    $table->json('config')->nullable();                          // root folder ids, team ids, etc.
    $table->timestamps();
});

// migration: create_connector_sync_states_table
Schema::create('connector_sync_states', function ($table) {
    $table->id();
    $table->string('connector_type', 32);
    $table->string('project_key', 120)->index();
    $table->foreignId('credential_id')->constrained('connector_credentials');
    $table->string('last_cursor', 512)->nullable();              // page token / changes token
    $table->timestamp('last_sync_at')->nullable();
    $table->text('last_error')->nullable();
    $table->json('stats')->nullable();                           // {ingested, updated, deleted, failed}
    $table->unique(['connector_type', 'project_key', 'credential_id'], 'uq_sync_per_connector_project');
    $table->timestamps();
});
```

**OAuth scaffolding:**
- `composer require laravel/socialite` per Google + OneDrive (provider built-in).
- `composer require league/oauth2-client` per Asana (manual).
- Per IMAP: nessun OAuth, plain credentials encrypted.

**Storage policy locked-in:** **Hybrid extract-and-link**.
- Drive/OneDrive: download bytes → run Converter pipeline → store extracted markdown + chunks. NON archiviamo i bytes originali (zero raw_disk usage). Manteniamo `external_url` per click-through.
- Asana: testo task + descrizione + commenti → markdown unico → chunks. Allegati = `link_only` mode (solo metadata + URL).
- IMAP: subject + body + thread → markdown. Allegati = `link_only` (escluse PDF allegate piccole opzionali).

**Sync engine:**
- Scheduler `connector:sync` (configurabile, default ogni 15 min).
- Per ogni `(connector_credential_id, project_key)`: chiama `listChanges(cursor)`, dispatcha `IngestExternalDocumentJob` per ognuno.
- `IngestExternalDocumentJob` polymorphic: payload `{connector_type, external_id, credential_id, project_key}` → fetch via Connector → Convert → Chunk → Enrich → Embed → Persist.
- Cancellazioni remote (Drive trash, Asana archive) propagate via soft-delete locale.

**Connector implementati per release:**
- v3.2: `LocalDiskConnector` (refactored), `GoogleDriveConnector`, `AsanaConnector`.
- v3.3: `OneDriveConnector`, `ImapConnector`.

---

### 2.4 Pilastro D — Transparent reasoning

**Backend changes:**

`Reranker.php` già calcola `rerank_detail` per chunk (vector_score, keyword_score, heading_score, fused_score, canonical_boost, status_penalty). Modifica: invece di scartare, propaga in `SearchResult.meta.per_chunk_scores[]`.

`KbChatController` response shape:
```jsonc
{
  "answer": "...",
  "citations": [
    {
      "document_id": 42,
      "title": "...",
      "source_path": "...",
      "external_url": "https://drive.google.com/...",  // se da connector
      "headings": [...],
      "chunks_used": [...],
      "origin": "primary|related|rejected",
      "relevance": {
        "vector_score": 0.78,
        "keyword_score": 0.62,
        "heading_score": 0.30,
        "fused_score": 0.71,
        "canonical_boost": 0.15,
        "status_penalty": 0.0,
        "final_score": 0.86
      }
    }
  ],
  "confidence": 87,
  "refusal_reason": null,
  "meta": {
    "provider": "anthropic", "model": "claude-opus-4-7",
    "search_strategy": {
      "semantic_enabled": true,
      "fts_enabled": true,
      "fusion_method": "weighted",  // 0.6*vec + 0.3*kw + 0.1*head
      "graph_expansion_enabled": true,
      "rejected_injection_enabled": true,
      "filters_applied": {...}
    },
    "retrieval_stats": {
      "candidates_pre_threshold": 47,
      "candidates_post_threshold": 12,
      "primary_count": 5,
      "expanded_count": 3,
      "rejected_count": 1,
      "min_score_used": 0.31,
      "max_score_used": 0.92
    },
    "latency_ms": {"retrieval": 87, "llm": 1420, "total": 1507}
  }
}
```

Persistito in `messages.metadata.retrieval_trace` (1-3 KB JSON per turno).

**Nuovo endpoint admin:**
```
GET /api/admin/conversations/{id}/messages/{mid}/retrieval-trace
→ ritorna full trace + opzione "replay this turn with current KB" (utile per regression dopo re-tuning)
```

**Frontend changes:**
- `<RetrievalTracePanel>` collapsible in MessageBubble (default closed). Per-source: barra rilevanza, breakdown grafico, badge. **Visibile solo a `admin` + `analyst` ruoli (locked-in §7.2). `editor` + `viewer` vedono solo `<ConfidenceBadge>` + origin colorate.**
- `<SearchStrategyBadge>` chip che mostra "Hybrid: 60% semantic + 30% keyword + 10% heading" con popover spiegazione.
- Pannello admin `/admin/insights/retrieval-quality`:
  - Histogram dei confidence score per turno (ultimi 30g).
  - Top "uncertain turns" (confidence < 50).
  - Per-tenant grounding rate.
  - Conversion: "uncertain → rated thumbs-down" rate (validazione delle metriche).

---

### 2.5 Pilastro E — Vector DB abstraction (lean)

**Decision locked-in:** ship solo `PgvectorStore` adapter. Interface presente per futuro.

**Refactor steps:**

1. Crea `VectorStoreInterface` + `RetrievalFilters` DTO + `VectorStoreCapabilities` (declares: `hybrid_native`, `fts_native`, `multi_tenant_native`).
2. Estrai logica pgvector da `KbSearchService` in `PgvectorStore`:
   - `semanticSearch()`: il `<=>` SQL.
   - `fullTextSearch()`: `to_tsvector` + `@@`.
   - `persist()`: bulk insert chunks con embedding cast.
   - `delete()`: cascade chunks.
3. `KbSearchService` orchestrationonly: query embedding → store.semanticSearch → store.fullTextSearch → fusion → reranker → result.
4. Iniezione via container: `app()->bind(VectorStoreInterface::class, fn() => new PgvectorStore(config('kb-pipeline.vector_store')))`.
5. Test: tutti i test esistenti devono passare invariati (interfaccia trasparente).

**Blast radius:** 7 file critici (KbSearchService, DocumentIngestor, RejectedApproachInjector, GraphExpander, AdminMetricsService, KbReadChunkTool, KbResolveWikilinkController). ~250 LOC.

**FTS gating:** `VectorStoreCapabilities.fts_native = true` per pgvector. Se in futuro driver senza FTS nativa → fallback a query LIKE o disabilita hybrid via config.

**SQLite test path:** `PgvectorStore` rileva driver SQLite e usa `CosineCalculator` PHP-side per `semanticSearch`. Già infrastruttura esistente in `RejectedApproachInjector` + `tests/database/migrations/`.

---

### 2.6 Pilastro F — Filtri enterprise

**Schema:** zero modifiche. `kb_tags` + `knowledge_document_tags` già esistono (popolati ma non esposti). `source_path`, `canonical_type`, `source_type`, `connector_type`, `language`, `indexed_at`, `source_updated_at` tutti già nelle colonne.

**API extension** (KbChatController + nuovo endpoint conversations message):
```php
'filters' => 'array',
'filters.project_keys' => 'array',
'filters.project_keys.*' => 'string',
'filters.tag_slugs' => 'array',
'filters.tag_slugs.*' => 'string',
'filters.source_types' => 'array',          // markdown|pdf|docx|asana|drive|...
'filters.canonical_types' => 'array',       // decision|runbook|module|...
'filters.connector_types' => 'array',
'filters.doc_ids' => 'array',               // da @mention
'filters.doc_ids.*' => 'integer',
'filters.folder_globs' => 'array',          // ['docs/eng/**', 'hr/policies/**']
'filters.date_from' => 'nullable|date',
'filters.date_to' => 'nullable|date',
'filters.languages' => 'array',
```

**Backend:** `KbSearchService::applyFilters()` traduce in:
- `whereIn('project_key', ...)`, `whereIn('source_type', ...)`, `whereIn('canonical_type', ...)`, `whereIn('connector_type', ...)`, `whereIn('id', $doc_ids)`.
- Tag: `whereHas('tags', fn($q) => $q->whereIn('slug', $tag_slugs))` con escape complete (R19).
- Folder glob: usa `App\Support\KbPath::matchesAnyGlob()` con `FNM_PATHNAME` (R19).
- Date: `whereBetween('indexed_at', [$from, $to])`.

**Frontend (composer redesign):**
- Barra filtri persistente sopra textarea, sempre visibile.
- Chip per ogni filtro attivo con `×` per rimuovere.
- Popover "Add filter" con tab: Project, Tags, Source Type, Folder, Date.
- `@`-trigger nel textarea apre autocomplete (debounced 200ms) su `GET /api/kb/documents/search?q=...&project_keys=...` (FTS sui titoli, scope project corrente).
- Selezione `@mention` aggiunge doc_id al filtro `doc_ids[]` + chip visibile.
- "Saved filter sets" persistiti in `chat_filter_presets` (`{user_id, name, filters_json}`).

**Tags admin UI:**
- Già esiste `kb_tags` table. Nuova vista admin `/admin/kb/tags`:
  - Tab "Tags" — List + create + edit + delete + color picker.
  - Tab "Suggestions" — coda `tag_suggestions` pendenti (vedi Pilastro A AutoTagger), badge counter "N pending". Admin approva/rifiuta in batch (checkbox multi-select). Approvato → INSERT in `kb_tags` + UPDATE `tag_suggestions.status='approved'` + auto-link ai documenti che lo hanno proposto.
  - Bulk-tag documents (multi-select dal KB tree → "Add tag X").

**Schema addition (per AutoTagger hybrid 3-step, locked-in §7.6):**

```php
// migration: create_tag_suggestions_table
Schema::create('tag_suggestions', function ($table) {
    $table->id();
    $table->string('project_key', 120)->index();
    $table->string('proposed_slug', 120);
    $table->string('proposed_label', 120);
    $table->foreignId('knowledge_document_id')->constrained()->cascadeOnDelete();
    $table->string('proposed_by', 32)->default('autotagger');  // autotagger|user
    $table->string('status', 16)->default('pending');           // pending|approved|rejected
    $table->json('llm_context')->nullable();                    // {model, confidence, related_existing_tags}
    $table->foreignId('reviewed_by')->nullable()->constrained('users');
    $table->timestamp('reviewed_at')->nullable();
    $table->unique(['project_key', 'proposed_slug', 'knowledge_document_id'], 'uq_tag_sugg_doc_slug');
    $table->timestamps();
});
```

**Schema addition (per RBAC trace visibility, locked-in §7.2):**

```php
// migration: add_analyst_role (in v3.1)
// Spatie permission tables already exist. Add role + permission:
Role::firstOrCreate(['name' => 'analyst']);
Permission::firstOrCreate(['name' => 'view_retrieval_trace'])
    ->syncRoles(['admin', 'analyst']);
```

Frontend gates: `<RetrievalTracePanel>` wrappa con `<Can permission="view_retrieval_trace">`. Backend gates: endpoint `GET /api/admin/conversations/.../retrieval-trace` policy `RetrievalTracePolicy@view` controlla `view_retrieval_trace`.

---

## 3. Vision-LLM strategy (clarification follow-up)

**Modalità configurabili globalmente + per-progetto:**

```php
'image_extraction' => [
    'mode' => env('KB_IMAGE_MODE', 'ocr_with_llm_fallback'),
    // Options:
    //   'off'                      → immagini ignorate, solo metadata (filename, dim)
    //   'ocr_only'                 → Tesseract OCR sempre (free, ~veloce)
    //   'llm_always'               → vision-LLM sempre (massima qualità, costoso)
    //   'ocr_with_llm_fallback'    → Tesseract; se confidence < soglia → escalation LLM (Recommended)
    'ocr_min_confidence' => 0.65,
    'llm_provider' => env('KB_IMAGE_LLM_PROVIDER', 'openai'),  // riusa AiManager
    'llm_model' => env('KB_IMAGE_LLM_MODEL', 'gpt-4o-mini'),   // economico per OCR/caption
],
```

**Note:**
- `AiManager` resta authoritative per provider — il Vision-LLM è solo un'altra "task" che chiede al provider configurato di rispondere a un prompt + image_url. Non duplicare provider abstraction.
- Tesseract via `thiagoalessio/tesseract_ocr` (composer **suggest**, non require — opt-in install, locked-in §7.5). `ImageConverter` rileva Tesseract assente e degrada a `mode: off` con warning admin.
- Confidence Tesseract estratta da `--psm 3 --oem 3 -c tessedit_create_hocr=1` parsing.

---

## 4. Test strategy (R12/R13 enforcement)

**Vincolo non negoziabile** (per richiesta esplicita utente): test E2E con dati reali, anche per funzioni low-level. Dati realistici, controllo risultati realistici.

**Per ogni feature v3:**

1. **Unit test PHP (PHPUnit)** con dati fixture realistici, NON mocks gratuiti per dipendenze interne. Esempio:
   - `MarkdownChunkerTest` legge un vero markdown di 3KB con 5 sezioni, asserisce `heading_path` esatti, count chunks, token estimate.
   - `PdfConverterTest` carica un vero PDF di 2 pagine, asserisce text extraction.
   - `GoogleDriveConnectorTest` stubba SOLO la HTTP boundary verso Google API (`Http::fake()` con response Drive realistica), il resto è codice reale.

2. **Feature test Laravel** per ogni endpoint: esercita validator → service → DB → response. Real DB (SQLite in CI).

3. **E2E Playwright** per ogni feature UI: real backend, real DB. `page.route()` solo per AI provider + Google/MS/Asana boundaries (R13). Marker comment `R13: external boundary stub`.

4. **Per ogni connector:** test con response API realistica catturata da fixture (no fake data minimale).

5. **Per anti-hallucination:** test esplicito "0 chunks → refusal payload, NO LLM call" (`Http::fake()` asserisce zero call al provider).

6. **Per confidence:** test con fixture che producono confidence noti (chunks con vector_score noti) → asserisce composite formula.

**CI gates:**
- `vendor/bin/phpunit --testsuite=Unit,Feature` green.
- `npm run e2e` green (Playwright).
- `scripts/verify-e2e-real-data.sh` (R13 enforcement) green.
- Coverage report ≥ 70% PHP, ≥ 60% TS (target enterprise).

---

## 5. Sequencing / Release train

| Release | Pillar(s) | Nuove feature shipped | Stima | PR |
|---|---|---|---|---|
| **v3.0** | A (parziale) + F + B (Strato 0/1/2) | ChunkerInterface + ConverterInterface + PDF/DOCX/TXT/MD converters, filtri tag/folder/doc-type/@mention nel composer, refusal deterministico + confidence score | 5-6 sett | 2 PR |
| **v3.1** | A (completion) + D | CSV/RTF/ODT/XLSX converters, ImageConverter (OCR + vision-LLM opt), AutoTagger/Summary/Entity enrichers, transparent reasoning UI + admin replay | 5-6 sett | 2 PR |
| **v3.2** | C (parte 1) + B (Strato 3) | Connector framework (Interface + registry + OAuth + credential vault + sync engine), GoogleDriveConnector, AsanaConnector, citation verifier async + WebSocket | 6-8 sett | 2-3 PR |
| **v3.3** | C (parte 2) + E | OneDriveConnector, ImapConnector, VectorStoreInterface refactor (pgvector adapter only) | 4-5 sett | 1-2 PR |

**Totale: ~22-25 settimane (5.5-6.5 mesi) at full pace, 7-9 PR.**

Ogni release è **shippable indipendentemente**. v3.0 da sola dà valore enterprise (multi-format + filtri + grounding) a clienti che non hanno ancora cloud connector.

**Branch strategy (locked-in §7.1):**

```
main
 ├── feature/v3-design-spec     ← QUESTO doc (PR singolo design-only, merge subito a main)
 │
 └── feature/v3-platform        ← integration branch v3.0–v3.3
       ├── feature/v3.0          ← release branch
       │     ├── feature/v3.0-pipeline-pdf-docx       ← PR 1
       │     └── feature/v3.0-filters-and-grounding   ← PR 2
       │     (merge feature/v3.0 → feature/v3-platform)
       │
       ├── feature/v3.1
       │     ├── feature/v3.1-converters-image-enrich
       │     └── feature/v3.1-transparent-reasoning
       │
       ├── feature/v3.2
       │     ├── feature/v3.2-connector-framework-drive-asana
       │     └── feature/v3.2-citation-verifier-async
       │
       └── feature/v3.3
             ├── feature/v3.3-onedrive-imap
             └── feature/v3.3-vector-store-interface

(feature/v3-platform → main quando tutta la v3 è shippable, OPPURE merge cumulativo per release)
```

Convenzione commit: `feat(v3.X): description`, `fix(v3.X): description`, `docs(v3.X): description`.

---

## 6. Risks & mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| OAuth token refresh complexity | Medio | Workspace-OAuth riduce a 1 credential per connector per tenant. Refresh middleware unico (`ConnectorTokenRefresher`). |
| Vision-LLM cost esplosivo su grandi dataset | Alto | Default `ocr_with_llm_fallback` + flag per-progetto. Admin vede stima costo prima di abilitare. |
| Citation verifier latency percepita comunque (badge cambia) | Basso | UX: badge inizia "verifying...", animation soft. WS push dopo 2-5s tipici. |
| Pipeline enrichment cost cumulativo | Medio | Flag per-progetto `none|basic|full`, default basic. Admin dashboard mostra spese cumulate per provider. |
| Refactor VectorStoreInterface rompe test esistenti | Basso | Interfaccia trasparente (stesso comportamento), test esistenti devono passare invariati. CI gate. |
| Test coverage scivola con velocità | Alto | Pre-merge gate coverage ≥ 70% PHP / 60% TS. R12/R13 enforcement scripts già presenti. |
| FE composer redesign rompe E2E esistenti | Medio | Mantieni testid esistenti (`chat-composer-input`, `chat-composer-send`); aggiungi nuovi (`chat-filter-bar`, `chat-mention-popover`). |

---

## 7. Locked decisions (decise 2026-04-26 con utente)

Tutte le 6 open question originali risolte:

1. **Branch / repo strategy** → `feature/v3-platform` come integration branch; sotto-branch per release `feature/v3.0`, `feature/v3.1`, `feature/v3.2`, `feature/v3.3`. Ogni release fa 2-3 PR mergeati nel suo sotto-branch, poi sotto-branch → `feature/v3-platform` → `main`. Un merge a main per release. Design doc separato in `feature/v3-design-spec` mergeato a main subito (documentazione, non codice).
2. **RBAC retrieval-trace panel** → `admin` + nuovo ruolo `analyst` vedono full trace (per-source scores, search-strategy breakdown, replay button). `editor` + `viewer` vedono solo confidence badge + citations origin. Aggiungere migration per il ruolo `analyst` in v3.1.
3. **Cifratura credenziali connector** → `Crypt::encrypt` (Laravel APP_KEY default) + interfaccia `CredentialVaultInterface` con `LaravelCryptVault` adapter di default. Adapter KMS/Vault on-demand se cliente enterprise paranoid lo richiede (zero LOC extra in v3.2, l'interfaccia è già pronta).
4. **WebSocket infra** → **Laravel Reverb** (PHP-only, no Redis required, ufficiale Laravel). Setup in v3.2 quando arriva il citation verifier async. Reverb gira insieme a php-fpm, on-prem friendly, zero servizi terzi.
5. **Tesseract OCR dependency** → `composer suggest` (opt-in install). README v3.1 documenta `apt install tesseract-ocr` + language packs. `ImageConverter` rileva Tesseract assente e degrada a `mode: off` con warning admin.
6. **AutoTagger behavior** → **Hybrid 3-step**: (a) LLM riceve titolo+summary+lista `kb_tags` esistenti del progetto, (b) priorizza match con tag esistenti, (c) se nessuno calza propone fino a 2 nuovi tag che finiscono in tabella `tag_suggestions` con stato `pending`, admin approva/rifiuta in batch da `/admin/kb/tags?tab=suggestions`. Approvato → diventa `kb_tags` riusabile. Zero tag-proliferation, taxonomy sotto controllo umano.

---

## 8. Definition of Done v3 (release-by-release)

**v3.0 DoD:**
- Pipeline pluggable Converter+Chunker funzionante per .md/.txt/.pdf/.docx
- Composer FE con filtri tag/folder/doc-type/@mention attivi
- Refusal deterministico + confidence score esposto in API + UI badge
- E2E coverage per ogni nuovo flusso (real data)
- README sezione "Extending Converters" + "Extending Chunkers" scritta
- Coverage ≥ 70% PHP / 60% TS

**v3.1 DoD:**
- Converters CSV/RTF/ODT/XLSX/IMG funzionanti
- Vision-LLM 4 modalità + Tesseract integration
- AutoTagger + SummaryGenerator + EntityExtractor + TopicClassifier
- Transparent reasoning UI deployed (panel + admin replay)
- README sezione "Extending Enrichers"

**v3.2 DoD:**
- Connector framework + Google Drive + Asana funzionanti
- OAuth workspace flow admin UI
- Sync scheduler + cursor management
- Citation verifier async via WS
- README sezione "Extending Connectors"

**v3.3 DoD:**
- OneDrive + IMAP connectors
- VectorStoreInterface refactor con pgvector adapter (test parity verificata)
- README sezione "Extending Vector Stores"

---

## 9. Out of scope (riconfermato)

- Multi-tenant SaaS architecture
- Per-user OAuth come default (resta opzione futura, schema già supporta)
- Elasticsearch/Qdrant adapter (interfaccia pronta)
- Real-time collaboration (presence, typing indicators)
- AI agent loops / autonomous research
- Mobile app
- API pubblica self-serve (la API resta Sanctum-protected interna)

---

**End of design.**
