# v4.0 W4 — `padosoft/laravel-patent-box-tracker` v0.1 design

**Status:** DRAFT — design doc per `project_v40_week_sequence` (W4 deliverable) and `project_patent_box_auto_tracker_v40` (priority pivot 2026-04-28).

**Scope:** a standalone Laravel package that walks one or more git repositories, classifies each commit as R&D-qualified (and along which Patent Box phase), correlates evidence with design docs and AI attribution, and emits a tamper-evident PDF + JSON dossier suitable for an Italian Patent Box compliance review.

**Strategic frame:** triple win per `project_patent_box_auto_tracker_v40` —
(a) ships an Apache-2.0 open-source package the Italian Laravel community can use,
(b) battle-tested by its own author against a real Italian Patent Box dossier (Padosoft ditta individuale, FY2026),
(c) generates the actual fiscal documentation Lorenzo needs to file with Agenzia delle Entrate.

---

## 1. Why this package

The Italian Patent Box (regime "Nuovo Patent Box" introduced from FY2021 and consolidated under D.L. 146/2021 art. 6) grants a **110% super-deduction** on qualified R&D costs incurred to develop intangible assets — patents, registered software (SIAE), industrial designs. The deduction reduces the taxable base by 110% of the eligible expense, so each €1 of qualified R&D effectively saves ~€0.30 of tax (IRPEF + IRAP + INPS combined for a ditta individuale).

The mandatory counterpart is documentation. To survive an Agenzia delle Entrate audit, the taxpayer must produce on demand:

1. **Identification of the qualified IP** — patent number, SIAE registration ID, design certificate
2. **Time-bound trail of R&D activity** — what was done, when, by whom, on which IP
3. **Phase classification** of each activity — research, design, implementation, validation, documentation
4. **Cost allocation** — hourly rates × hours × phase, mapped to fiscal-year buckets
5. **Tamper-evident integrity** — the documentation must demonstrate it could not have been retroactively fabricated

The current state of practice for software IP is manually compiled spreadsheets reconstructed from memory, calendars, and Git logs. This is expensive (a commercialista bills 8–20 hours per dossier) and error-prone (commits drift in classification, AI-assisted code is rarely separated, cross-repo work is hand-stitched).

`laravel-patent-box-tracker` replaces the manual reconstruction with a deterministic pipeline that walks the repositories and produces the same artefact in minutes.

### 1.1 Dogfooding angle

Lorenzo (Padosoft ditta individuale) is filing for the Italian Patent Box on the AskMyDocs IP — `lopadova/askmydocs` (CE) and the v4.0 work on `feature/v4.0` plus the sister Padosoft Apache packages (`padosoft/laravel-ai-regolo`, `padosoft/laravel-flow`, `padosoft/eval-harness`, `padosoft/laravel-pii-redactor`, `padosoft/patent-box-tracker` itself). The package ships in W4 SO THAT the W5–W8 work it tracks is captured from day 1 — and the W2–W3 work that already happened is reconstructed from `feature/v4.0` history at the same time.

### 1.2 Community angle

No equivalent package exists on Packagist as of 2026-04. The closest analogues are:

- **`gitinspector`** (Python) — generic git contribution stats, no fiscal classification
- **`commitlint` + custom downstream tooling** — message linting only
- **Toggl / Harvest plugins** — time tracking, but no Patent Box mapping
- **Italian commercialista Excel templates** — the manual baseline this package replaces

The market gap for an Italian Patent Box-aware Laravel package is real and large enough that the package is expected to drive its own adoption arc independent of AskMyDocs.

---

## 2. Italian Patent Box primer (background for non-Italian contributors)

> Skip this section if you already file Italian Patent Box dossiers.

The Patent Box is a tax incentive available to entrepreneurs (artigiani included), companies (SRL, SpA), and professionals (with VAT registration) that develop qualified intangible IP and incur R&D costs to do so. Key elements:

- **Qualified IP** — software protected by copyright (typically registered with SIAE), patents (registered with UIBM or EPO), designs, certain trademarks before 2022
- **Qualified R&D costs** — personnel, AI tooling subscriptions, cloud compute, contractor invoices, dedicated equipment, IP registration fees. Marketing, sales, generic admin do NOT qualify.
- **Phase taxonomy** — the Agenzia delle Entrate distinguishes research, experimental development, design, implementation, integration, validation, and documentation activities. Manutenzione (bug fixes on shipped code) does NOT qualify; bug fixes that ship before the IP is "released" generally do.
- **Documentation regime** — the taxpayer can opt for the "documentazione idonea" regime (D.M. 6 ottobre 2022 + provv. AdE 15 febbraio 2023) which protects against monetary penalties on classification errors if the dossier is filed. The taxpayer must communicate the option in the tax return.
- **Cost calculation** — qualified costs × 110% = additional deduction. The base 100% is already deducted normally; the +10% is the incentive.
- **Audit window** — Agenzia delle Entrate can challenge the dossier within 5 fiscal years from filing.

The package targets the **documentazione idonea** regime: the output dossier is what the taxpayer attaches when communicating the option, and what they hand to Agenzia delle Entrate during audit.

---

## 3. Evidence sources (inputs)

The tracker is **pluggable** — every evidence source implements `EvidenceCollector` and registers with the boot-time validation pattern from R23 (FQCN at boot + `supports()` mutex). v0.1 ships four collectors; future versions add more without breaking the registry.

### 3.1 `GitSourceCollector` — primary

For each tracked repo:

- Walks `git log --first-parent <branch> --since=<from> --until=<to>` plus `git log --all` for completeness
- Extracts: commit SHA, author name + email, committer email, timestamp (UTC), commit message, branch references, parent SHAs, files changed (with insertions / deletions per file)
- Computes per-commit hash chain (`H(prev_hash || commit_sha)`) for tamper-evidence
- Filters out merge commits, dependabot, and any author email matching the `excluded_authors` config

### 3.2 `AiAttributionExtractor` — Co-Authored-By + author-email signature

Parses each commit message for:

- `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>` and variants — counts as AI-assisted
- `Co-Authored-By: GitHub Copilot <bot@github.com>` and variants
- The committer-email pattern `noreply@anthropic.com` / `<bot>@github.com`
- Inline conventional-commit trailers like `AI: claude-opus-4-7` if introduced as a Padosoft convention later

Output per commit: `ai_attribution ∈ { 'human' | 'ai_assisted' | 'ai_authored' | 'mixed' }` and `attribution_confidence ∈ [0,1]`.

### 3.3 `DesignDocCollector` — design intent evidence

Walks the repo's `docs/` and `plans/` folders for files matching:

- `docs/v4-platform/PLAN-*.md` — phase intent, scope, decision points
- `docs/adr/*.md` — architectural decisions
- `docs/superpowers/specs/*.md` — implementation specs
- `docs/plans/lessons-learned.md` — retrospective evidence

Each design doc is correlated to commits by:

- Filename match in commit-changed-files
- Slug match in commit message body
- Date proximity (design doc commit date ± 14 days from the commit being classified)

Output: `evidence_link[]` per commit with `kind ∈ {plan, adr, spec, lessons}`, `slug`, `link`, and `correlation_strength ∈ {direct, proximate, weak}`.

### 3.4 `BranchSemanticsCollector` — branch-naming heuristics

Parses branch names for the tax-meaningful prefixes:

- `feature/v4.0-W{N}.{x}-...` → development phase, version cycle ID
- `feature/enh-...` → enhancement, often qualifies
- `chore/*`, `ci/*` → typically NON-qualified
- `fix/*` → context-dependent (pre-release fix qualifies; post-release maintenance does not)

Branches are reconstructed from `git for-each-ref refs/heads` plus the historical refs visible in `git log --all`.

### 3.5 Out of scope for v0.1 (parking lot for v0.2+)

- `TerminalSessionCollector` — capture interactive Claude Code / Cursor sessions for richer context
- `CiRunCollector` — count CI iterations as proxy for validation effort
- `TimeTrackingCollector` — Toggl / Harvest / RescueTime integration
- `CalendarCollector` — Google Calendar / Outlook event titles for off-keyboard R&D time
- `IpRegistrationCollector` — UIBM / SIAE / EPO API to auto-link IP filings to dossier

These are the v0.2+ roadmap; v0.1 cleanly admits "off-keyboard time is not represented in the v0.1 dossier — the taxpayer adds it manually via the `manual_supplement` config block."

---

## 4. Classifier pipeline

### 4.1 Per-commit classification

Each commit goes through an LLM-based classifier that, given the commit metadata + the diff summary + the evidence links, emits:

```php
final class CommitClassification
{
    public string $phase;              // research | design | implementation | validation | documentation | non_qualified
    public bool   $is_rd_qualified;
    public float  $rd_qualification_confidence; // 0..1
    public string $rationale;          // 1-3 sentences for audit trail
    public ?string $rejected_phase;    // when LLM had a tie, the runner-up
    public array  $evidence_used;      // ['plan:PLAN-W3', 'adr:0007', ...]
}
```

The classifier prompt is **deterministic-seeded** (`temperature=0`, `top_p=1`, fixed `seed`) so re-running on the same commit produces an identical classification — Patent Box auditors can re-execute the run and verify byte-for-byte.

### 4.2 Provider — `laravel/ai` SDK + `padosoft/laravel-ai-regolo`

Per `feedback_packages_standalone_agnostic`, the tracker depends on the SDK abstraction, not on a specific provider. Configuration (in the consumer's `config/patent-box-tracker.php`):

```php
'classifier' => [
    'driver' => 'regolo', // any laravel/ai SDK provider key
    'model'  => env('PATENT_BOX_MODEL', 'claude-sonnet-4-6'),
    'temperature' => 0,
    'seed'   => 0xC0DEC0DE,
    'batch_size' => 20,        // commits per LLM call
    'cost_cap_eur_per_run' => 50.00, // hard stop guard
],
```

The default uses Regolo for cost reasons (€-cents per run) but anyone can swap to OpenAI, Anthropic direct, or Gemini in 1 line. **The package itself ships zero provider credentials — they live in the consumer's `.env`.**

### 4.3 Hand-graded ground truth

Before v0.1.0 tags, a 50-commit manually-labelled validation set is added under `tests/fixtures/golden-classifications.json`. The classifier is held to ≥ 80% F1 on this set as a release gate. The golden set is drawn from real `feature/v4.0` history with the labels assigned by Lorenzo + 1 commercialista review.

### 4.4 Cost cap

Each `track` run computes the projected token cost upfront (`commit_count × avg_input_tokens × price_per_1k`). If the projection exceeds `cost_cap_eur_per_run`, the runner aborts with exit code 2 and a clear error. This protects against accidentally classifying a 10-year-old monorepo at full price.

---

## 5. Output: dossier formats

### 5.1 PDF dossier

Rendered via Browsershot (Chromium) for fidelity, with a DomPDF fallback for environments where headless Chromium is unavailable. The template is Italian-fiscal-style A4 portrait with:

| Section | Content |
|---|---|
| Frontmatter | Tax identity (denomination, P.IVA, fiscal year), reporting period, dossier hash, generation timestamp |
| Executive summary | Total qualified hours, total qualified cost, AI-assist split, cross-repo summary, IP-output table |
| Phase breakdown | Pie + bar chart (research / design / implementation / validation / documentation), absolute hours + % |
| Repository inventory | Per-repo: commit count, author count, qualified-cost subtotal, IP linkage |
| Commit-level evidence | Tabular: SHA short, date, author, phase, qualified flag, rationale (truncated to 200 chars + dossier ref) |
| Evidence trail | Per-phase: linked design docs (PLAN, ADR, spec) with creation date and impact summary |
| AI attribution | Per-commit AI-assisted flag, aggregated to "X% AI-assisted, Y% human-only, Z% mixed" |
| IP-output table | Per IP (patent / SIAE software / design): registration ID, filing date, classification phases that produced it |
| Tamper-evidence appendix | Hash chain manifest (one row per commit, parent_hash → commit_sha → next_hash); SHA-256 of the entire JSON sidecar |

### 5.2 JSON sidecar

Same data, machine-readable, signed via the hash chain. Suitable for fiscal software integrations (e.g. eventually feeding into Italian gestionale software).

```json
{
  "dossier_version": "0.1",
  "generated_at": "2026-12-31T18:30:00Z",
  "tax_identity": {
    "denomination": "Padosoft di Lorenzo Padovani",
    "p_iva": "IT01234567890",
    "fiscal_year": "2026",
    "regime": "documentazione_idonea"
  },
  "reporting_period": { "from": "2026-01-01", "to": "2026-12-31" },
  "summary": {
    "total_qualified_hours_estimate": 1240,
    "total_qualified_cost_eur": 62000,
    "phase_breakdown": { "research": 120, "design": 280, "implementation": 580, "validation": 180, "documentation": 80 },
    "ai_attribution": { "human": 0.42, "ai_assisted": 0.51, "mixed": 0.07 }
  },
  "repositories": [ ... ],
  "ip_outputs": [ ... ],
  "commits": [ ... ],
  "evidence_links": [ ... ],
  "hash_chain": { "head": "sha256:...", "manifest": [ ... ] }
}
```

### 5.3 Two languages, one data model

The renderer accepts a `locale` parameter — `it` (default, Italian fiscal style) or `en` (generic English). v0.1 ships `it` only; `en` is a v0.2 addition because the Patent Box rules do not translate cleanly to English-speaking tax regimes (UK / Ireland / Germany have analogous regimes with different evidence formats).

---

## 6. Architecture

### 6.1 Package layout (Laravel package convention)

```
padosoft/laravel-patent-box-tracker/
├── composer.json                    # require: php ^8.3, laravel/ai ^0.x, illuminate/* ^11|^12|^13
├── README.md                        # 14-section WOW per feedback_open_source_readme_quality
├── CHANGELOG.md
├── LICENSE                          # Apache-2.0
├── .claude/                         # vibe-coding pack — feedback_package_readme_must_highlight_vibe_coding_pack
│   ├── skills/                      # cherry-picked from AskMyDocs (R36, R37, R38, R23, R30, etc.)
│   ├── rules/                       # Padosoft baseline
│   └── agents/                      # copilot-review-anticipator, classifier-prompt-tuner
├── .github/
│   └── workflows/
│       └── ci.yml                   # PHP 8.3 / 8.4 / 8.5 matrix + Laravel 11/12/13
├── config/
│   └── patent-box-tracker.php       # default config — provider, locale, paths, cost cap
├── database/
│   └── migrations/
│       ├── ..._create_tracking_sessions_table.php
│       ├── ..._create_tracked_commits_table.php
│       ├── ..._create_tracked_evidence_table.php
│       └── ..._create_tracked_dossiers_table.php
├── src/
│   ├── PatentBoxTrackerServiceProvider.php
│   ├── PatentBoxTracker.php         # public API entry — fluent builder
│   ├── Sources/
│   │   ├── EvidenceCollector.php    # interface
│   │   ├── GitSourceCollector.php
│   │   ├── AiAttributionExtractor.php
│   │   ├── DesignDocCollector.php
│   │   └── BranchSemanticsCollector.php
│   ├── Classifier/
│   │   ├── CommitClassifier.php     # LLM call wrapper
│   │   ├── ClassifierBatcher.php
│   │   ├── ClassifierPrompts.php    # prompt templates
│   │   └── GoldenSetValidator.php   # hand-graded gate
│   ├── Models/
│   │   ├── TrackingSession.php
│   │   ├── TrackedCommit.php
│   │   ├── TrackedEvidence.php
│   │   └── TrackedDossier.php
│   ├── Renderers/
│   │   ├── DossierRenderer.php      # interface
│   │   ├── PdfDossierRenderer.php   # Browsershot + DomPDF fallback
│   │   └── JsonDossierRenderer.php
│   ├── Hash/
│   │   └── HashChainBuilder.php
│   └── Console/
│       ├── TrackCommand.php          # patent-box:track
│       ├── RenderCommand.php         # patent-box:render
│       └── CrossRepoCommand.php      # patent-box:cross-repo
├── resources/
│   └── views/
│       ├── pdf/it/dossier.blade.php  # Italian template
│       └── pdf/it/partials/*.blade.php
├── tests/
│   ├── Unit/                         # offline — Testbench
│   ├── Feature/                      # offline — Testbench + recorded git fixtures
│   └── Live/                         # opt-in — feedback_package_live_testsuite_opt_in
│       ├── LiveTestCase.php
│       └── ClassifierLiveTest.php
└── phpunit.xml                       # Unit + Feature default; Live opt-in
```

### 6.2 Public API surface (fluent builder)

```php
use Padosoft\PatentBoxTracker\PatentBoxTracker;

$session = PatentBoxTracker::for([
        '/path/to/askmydocs',
        '/path/to/laravel-ai-regolo',
        '/path/to/laravel-flow',
    ])
    ->coveringPeriod('2026-01-01', '2026-12-31')
    ->classifiedBy('regolo')
    ->withTaxIdentity([
        'denomination' => 'Padosoft di Lorenzo Padovani',
        'p_iva'        => 'IT01234567890',
        'fiscal_year'  => '2026',
        'regime'       => 'documentazione_idonea',
    ])
    ->withCostModel([
        'hourly_rate_eur' => 80,
        'daily_hours_max' => 8,
    ])
    ->run();

// $session is a TrackingSession Eloquent model with relations to TrackedCommit / TrackedEvidence

$dossier = $session->renderDossier()
    ->locale('it')
    ->toPdf();      // Symfony\Component\HttpFoundation\StreamedResponse OR string OR file

$dossier->save(storage_path('dossier-2026.pdf'));

// JSON sidecar
$session->renderDossier()->toJson()->save(storage_path('dossier-2026.json'));
```

### 6.3 Console commands

```bash
# Single-repo track
php artisan patent-box:track /path/to/askmydocs \
    --from=2026-01-01 --to=2026-12-31 \
    --provider=regolo --model=claude-sonnet-4-6

# Render an existing session
php artisan patent-box:render <session-id> --format=pdf --out=storage/dossier.pdf

# Cross-repo from a YAML config (the Padosoft canonical use case)
php artisan patent-box:cross-repo config/patent-box-2026.yml
```

The cross-repo YAML config:

```yaml
fiscal_year: 2026
period:
  from: 2026-01-01
  to: 2026-12-31
tax_identity:
  denomination: Padosoft di Lorenzo Padovani
  p_iva: IT01234567890
  regime: documentazione_idonea
cost_model:
  hourly_rate_eur: 80
  daily_hours_max: 8
classifier:
  provider: regolo
  model: claude-sonnet-4-6
repositories:
  - path: /home/lpad/Code/askmydocs
    role: primary_ip
  - path: /home/lpad/Code/laravel-ai-regolo
    role: support
  - path: /home/lpad/Code/laravel-flow
    role: support
  - path: /home/lpad/Code/eval-harness
    role: support
  - path: /home/lpad/Code/laravel-pii-redactor
    role: support
  - path: /home/lpad/Code/laravel-patent-box-tracker
    role: meta_self
manual_supplement:
  off_keyboard_research_hours: 60
  conferences:
    - { name: "Laracon EU 2026", days: 3 }
ip_outputs:
  - kind: software_siae
    title: "AskMyDocs Enterprise Platform v4.0"
    registration_id: "SIAE-2026-..."
  - kind: brevetto_uibm
    title: "Canonical Knowledge Compilation Engine"
    application_id: "PCT/IT2026/..."
```

### 6.4 Storage schema (high-level)

```
tracking_sessions
  - id
  - tax_identity_json
  - period_from, period_to
  - cost_model_json
  - classifier_provider, classifier_model, classifier_seed
  - status: pending | running | classified | rendered | failed
  - cost_eur_actual, cost_eur_projected
  - golden_set_f1_score
  - created_at, updated_at, finished_at

tracked_commits
  - id, tracking_session_id (FK)
  - repository_path, repository_role
  - sha, author_name, author_email, committer_email, committed_at
  - message, files_changed_count, insertions, deletions
  - branch_name_canonical, branch_semantics_json
  - ai_attribution: human | ai_assisted | ai_authored | mixed
  - phase: research | design | implementation | validation | documentation | non_qualified
  - is_rd_qualified, rd_qualification_confidence
  - rationale, rejected_phase, evidence_used_json
  - hash_chain_prev, hash_chain_self
  - UNIQUE (tracking_session_id, repository_path, sha)

tracked_evidence
  - id, tracking_session_id (FK)
  - kind: plan | adr | spec | lessons
  - path, slug, title
  - first_seen_at, last_modified_at
  - linked_commit_count

tracked_dossiers
  - id, tracking_session_id (FK)
  - format: pdf | json
  - locale: it | en
  - path, byte_size, sha256
  - generated_at
```

### 6.5 Standalone-agnostic constraints (per `feedback_packages_standalone_agnostic`)

- ZERO `require` on `lopadova/askmydocs` or `padosoft/askmydocs-pro`
- ZERO references to `App\Models\KnowledgeDocument`, `KbSearchService`, `kb_*` table names
- All code uses ONLY first-party Laravel packages + `laravel/ai`
- Tests run against `Orchestra\Testbench` only, with synthetic git fixtures (no AskMyDocs models)
- `composer install` on a fresh empty Laravel app must produce a working tracker that can run against any git repository — not just AskMyDocs

An architecture test enforces these:

```php
// tests/Architecture/StandaloneAgnosticTest.php
test('package source code does not reference AskMyDocs symbols', function () {
    $files = glob(__DIR__ . '/../../src/**/*.php');
    foreach ($files as $f) {
        $contents = file_get_contents($f);
        expect($contents)
            ->not->toContain('KnowledgeDocument')
            ->not->toContain('KbSearchService')
            ->not->toContain('kb_documents')
            ->not->toContain('lopadova/askmydocs');
    }
});
```

---

## 7. Test plan

### 7.1 Unit suite (offline, default)

- `CommitClassifierTest` — given a fixture commit + canned LLM response, classifier emits the expected `CommitClassification`. LLM call is faked via `Http::fake()`.
- `GitSourceCollectorTest` — runs against `tests/fixtures/repos/synthetic-r-and-d.git` (a recorded bare repo with 30 known commits across 5 phases). Asserts every commit is extracted with the right metadata.
- `AiAttributionExtractorTest` — 12 commit-message variants → expected attribution flag.
- `DesignDocCollectorTest` — synthetic `docs/` tree → expected evidence-link graph.
- `BranchSemanticsCollectorTest` — branch-name patterns → expected semantics.
- `HashChainBuilderTest` — same input → same chain; tampering one commit breaks the chain at that exact position.
- `JsonDossierRendererTest` — round-trip a synthetic session → JSON → re-parse → re-render produces byte-identical output.
- `GoldenSetValidatorTest` — synthetic golden set + synthetic classifier outputs → F1 score matches a hand-computed value.

### 7.2 Feature suite (offline, default)

- `TrackCommandTest` — `php artisan patent-box:track <fixture-repo>` end-to-end with `Http::fake()`. Asserts session row created, tracked_commits populated, hash chain valid, no live HTTP calls.
- `CrossRepoCommandTest` — YAML config + 3 fixture repos → one session with 3 repository-roles, cross-repo summary correct.
- `RenderCommandTest` — pre-populated session → PDF exists, page count > 1, JSON sidecar valid against schema.
- `StandaloneAgnosticTest` — see §6.5.

### 7.3 Live suite (opt-in, never in CI) per `feedback_package_live_testsuite_opt_in`

- `tests/Live/LiveTestCase.php` — base class; `markTestSkipped()` when `PATENT_BOX_LIVE_API_KEY` is missing
- `tests/Live/ClassifierLiveTest.php` — runs the classifier against 5 hand-picked real `feature/v4.0` commits using the real Regolo API. Asserts ≥ 80% match against the golden labels. Cost: ~€0.05 per run.
- `tests/Live/RendererLiveTest.php` — generates a real PDF and validates it opens in `pdf-parser` without errors.
- README documents the env vars + cost expectation + how to run.

### 7.4 CI matrix per `feedback_padosoft_repo_ci_versions`

- PHP **8.3, 8.4, 8.5**
- Laravel **11.x, 12.x, 13.x** (matrix; the package's `composer.json` declares `^11.0|^12.0|^13.0`)
- Default suite: Unit + Feature only. Live suite NEVER runs in CI.

### 7.5 Acceptance gate before tagging v0.1.0

- All Unit + Feature green on the 9-cell matrix (3 PHP × 3 Laravel)
- Live suite runs locally on Lorenzo's machine and passes
- Standalone-agnostic architecture test green
- Hand-graded golden set ≥ 80% F1
- README WOW 14-section audit passed (per `feedback_open_source_readme_quality`)
- `.claude/` vibe-coding pack present + README highlights it (per `feedback_package_readme_must_highlight_vibe_coding_pack`)
- One end-to-end run on `feature/v4.0` produces a PDF that Lorenzo's commercialista validates as Patent-Box-suitable

---

## 8. Sub-task breakdown

Each sub-task is its own PR targeting `main` of the new repo (per R37 — new padosoft repos PR target main directly, no integration branch).

| Sub-task | Branch | Scope | Estimate | Risk |
|---|---|---|---|---|
| W4.0 | `feature/v4.0-W4.0-design` (THIS PR, on AskMyDocs feature/v4.0) | Design doc + W3 closure. No package code. | 0.5 day | none |
| W4.A | `feature/scaffold` (on the new repo) | Repo scaffold: composer.json, ServiceProvider, config, migrations skeleton, README WOW skeleton, .claude pack, CI matrix, Live testsuite scaffold | 3 days | low |
| W4.B | `feature/classifier` | `EvidenceCollector` interface + 4 collectors + `CommitClassifier` + `ClassifierBatcher` + Unit testsuite + golden set | 4 days | medium — prompt engineering |
| W4.C | `feature/dossier-renderer` | PDF + JSON renderers, Italian template, `HashChainBuilder`, `RenderCommand` | 3 days | medium — template fidelity |
| W4.D | `feature/cross-repo-orchestration` | `CrossRepoCommand` + YAML config schema + dogfooding run on AskMyDocs `feature/v4.0` + 5 sister repos. Closure status doc. | 2 days | medium — multi-repo throughput + commercialista validation |

**Total: ~12.5 days** focused work. W3 ran 1.5 weeks; W4 fits inside one calendar week with focused execution because the package is greenfield and avoids the pre-existing-test-fidelity surface that drove W3 review cycles.

### 8.1 Acceptance criteria for W4 done

1. `padosoft/laravel-patent-box-tracker` v0.1.0 published on Packagist
2. AskMyDocs `feature/v4.0` PR closes the W4 cycle with a status doc analogous to STATUS-2026-05-01-week3.md
3. Real PDF dossier generated against `feature/v4.0` + 5 sister repos for the period 2026-01-01 → 2026-04-30 (W1 → W3 actual deliverables)
4. Lorenzo + commercialista review of the dossier passes
5. The `tests/Live/ClassifierLiveTest.php` golden-set F1 ≥ 80% on the real run
6. PHP 8.3/8.4/8.5 × Laravel 11/12/13 matrix green
7. Repo README scores ≥ 12/14 sections in the `feedback_open_source_readme_quality` audit

---

## 9. Risks + mitigations

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| LLM classifier non-determinism causing dossier drift between runs | medium | audit-trail credibility | Deterministic seed + temperature=0; record prompt + model + seed in dossier metadata so a re-run reproduces byte-for-byte |
| Italian fiscal template inaccuracies | medium | dossier rejected by commercialista | Template review by Lorenzo's commercialista before tagging v0.1.0 (Acceptance gate §7.5) |
| Multi-repo Git walk slow on cold caches | low | UX (one-time setup tax) | Parallel `git rev-list` per repo; shallow clone documented as the supported workflow for first run |
| Cost overrun on classifier when classifying a large monorepo | low | unexpected API bill | `cost_cap_eur_per_run` hard stop (§4.4); pre-flight cost estimate printed to stderr before run |
| Standalone-agnostic violation creeps in via dogfooding | medium | community adoption risk | Architecture test in §6.5 + grep on `'kb_'`, `'KnowledgeDocument'`, `'lopadova/'` runs in CI |
| AI attribution false negatives (custom AI tooling without standardised trailers) | medium | misclassified human/ai split | v0.1 documents the limitation; v0.2 adds inline trailer convention `AI-Tool: <name>` for projects to opt in |
| Patent Box rules change mid-build | low | dossier format obsolete | Decree text quoted verbatim in `docs/patent-box-italia.md` with the cited articles + dates; package version bumps when rules change |

---

## 10. Out of scope for v0.1

- Live terminal session capture (Cursor / Claude Code session recording) — v0.2
- Time-tracking integrations (Toggl / Harvest / RescueTime / Clockify) — v0.2
- Calendar integration for off-keyboard R&D time — v0.2
- Multi-language dossier (English / German / French) — v0.2
- Web UI dashboard (Filament panel) — v1.0
- Tax-jurisdiction support beyond Italy (UK Patent Box, Irish Knowledge Box, German FuE-Zulage) — v1.0
- AI-assisted vs human-written line-level diff classification (token-by-token attribution) — v0.2
- Direct UIBM / SIAE / EPO API integration — v0.3
- Multi-tenant SaaS deployment (one tracker, many clients) — v1.0

---

## 11. Open questions for Lorenzo

1. **Scope of v0.1** — stick to "git-history-only dossier" or include manual-supplement input form (off-keyboard R&D, conferences)? Recommend git-history-only + a YAML `manual_supplement` block (already in §6.3) — no UI for v0.1.
2. **Classifier accuracy gate** — proposed ≥ 80% F1 on a 50-commit hand-graded golden set (§4.3). Acceptable, or stricter (≥ 90%)?
3. **PDF template language** — Italian only for v0.1, or Italian + English from day 1? Recommend Italian only — English locale is a v0.2 deliverable.
4. **Cost model input** — manual hourly rate via config (`hourly_rate_eur`) or auto-derive from commit-volume heuristic? Recommend manual — Patent Box auditors prefer transparent inputs over inferred numbers.
5. **Cross-repo orchestration in v0.1 or v0.2?** — Recommend v0.1: Lorenzo's dogfood case requires it (AskMyDocs + 5 sister repos), and the orchestration code is small (~150 LOC). Single-repo only would ship an incomplete tool.
6. **Classifier model default** — `claude-sonnet-4-6` (best accuracy, ~€0.05 per 1k commits) or `claude-haiku-4-5` (cheaper, slightly lower F1)? Recommend Sonnet as default, Haiku documented as the cheap fallback.
7. **Patent Box regime selection** — `documentazione_idonea` (penalty-protection regime) or `non_documentazione` (taxpayer self-assesses, exposed to penalties on errors)? Recommend `documentazione_idonea` as the package default since the package output is precisely the "documentazione idonea" artefact.
8. **Repo bootstrapping action** — when this design PR merges, is Claude authorised to `gh repo create padosoft/laravel-patent-box-tracker --public --license=Apache-2.0` directly, or does Lorenzo want to perform the create manually and grant the org-write permission case-by-case?

---

## 12. Once approved → execution checklist

- [ ] Lorenzo answers §11 open questions in the PR review thread
- [ ] [HUMAN] `gh repo create padosoft/laravel-patent-box-tracker --public --license=Apache-2.0` (Q8 — pending Lorenzo's call on direct vs manual)
- [ ] W4.A PR — scaffold + .claude pack + CI matrix + README skeleton; R36 loop; merge to `main`
- [ ] W4.B PR — collectors + classifier + Unit + golden set; R36 loop; merge to `main`
- [ ] W4.C PR — PDF + JSON renderers + Italian template + hash chain; R36 loop; merge to `main`
- [ ] W4.D PR — cross-repo orchestration + dogfood run on AskMyDocs `feature/v4.0` + sister repos; R36 loop; merge to `main`
- [ ] Tag `v0.1.0` on the new repo + Packagist publish
- [ ] AskMyDocs PR adding the dogfood YAML config under `tools/patent-box/` + STATUS-{date}-week4.md closure on `feature/v4.0`
- [ ] Commercialista review of the generated dossier — log outcome in the closure status doc

---

**Document author:** Claude Opus 4.7 (1M context) for `lorenzo.padovani@padosoft.com`.
**Review checkpoint:** Lorenzo to confirm §11 questions before W4.A starts; specifically Q8 (repo bootstrap authorisation) gates the next concrete action.
