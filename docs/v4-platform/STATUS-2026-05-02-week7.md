# v4.0 Week 7 closure — 2026-05-02

W7 ships **two coordinated deliverables** per `project_v40_week_sequence`:

1. **`padosoft/laravel-pii-redactor` v0.1.0** — standalone Apache-2.0 PII redaction package for Laravel. Six checksum-validated detectors (Email, IBAN with mod-97 over ~75 ISO 13616 countries, Credit Card with Luhn, Italian Phone +39 mobile + landline, Codice Fiscale with the DM 23/12/1976 CIN checksum, Partita IVA with Luhn-IT + zero-payload sentinel) and four redaction strategies (Mask, Hash with deterministic SHA-256 namespaced per-detector, Tokenise reversible with `detokenise()` / `dumpMap()` / `loadMap()`, Drop). Regex + checksum based — zero LLM dependency in v0.1.

2. **`padosoft/askmydocs-pro` foundation seed (BSL-1.1, private)** — the commercial sister package that the v4.0 CE release train graduates users into. Foundation-only (LICENSE, composer.json, README, `.claude/` vibe-coding pack, CI lint loop); product code, `src/`, and `tests/` land in v4.1+.

The pii-redactor package fills a real gap on Packagist: existing PHP redaction libraries (`spatie/data-redaction` and friends) are field-level only, with no native Italian-fiscal-identifier validation and no offline-only checksum-validating detectors. Comparable hosted services (Microsoft Presidio, AWS Comprehend PII, Google Cloud DLP) require sending data to a third-party API — disqualified for any pipeline operating under GDPR-strict on-prem constraints. `padosoft/laravel-pii-redactor` is the first PHP package to ship native Codice Fiscale + Partita IVA checksum validation alongside reversible pseudonymisation and offline-only operation.

The askmydocs-pro foundation is the BSL-1.1 commercial release-train target — the four-year change-date clock starts at each release per the canonical BSL parameters; the change license is Apache-2.0. The composer manifest declares the v4 sister-package dependency train (`lopadova/askmydocs ^4.0.0-rc1` + `padosoft/laravel-ai-regolo ^0.2` + `padosoft/laravel-flow ^0.1` + `padosoft/eval-harness ^0.1` + `padosoft/laravel-pii-redactor ^0.1`) so the dependency surface is locked before any product code lands.

## Sub-tasks shipped

| Sub-task | PR | Merge SHA on `main` | Outcome |
|---|---|---|---|
| W7.0 — pii-redactor minimal scaffold + test-count skill | laravel-pii-redactor #2 | `f3b658a` | `composer.json` baseline, PHPUnit scaffold, `test-count-readme-sync` skill imported from the Padosoft baseline |
| W7.A — pii-redactor full scaffold + redactor core | laravel-pii-redactor #3 | `956089b` | Full Padosoft `.claude/` vibe-coding pack; CI matrix PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13 with Pint + PHPStan level 6 + Unit + Architecture suites; `composer.json` PHP ^8.3 + Laravel 12 / 13 with NO `laravel/ai` dep (the package is regex + checksum based, not LLM-based for v0.1); `phpunit.xml` Unit (default) + Architecture + Live (opt-in via `PII_REDACTOR_LIVE=1`); `pint.json` + `phpstan.neon.dist` aligned with sister packages; `RedactorEngine` with deterministic left-to-right overlap resolution; six first-party detectors (`EmailDetector`, `IbanDetector` with PHP_INT_MAX-safe chunked mod-97 arithmetic, `CreditCardDetector` Luhn, `PhoneItalianDetector` +39 mobile + landline, `CodiceFiscaleDetector` DM 23/12/1976 CIN checksum, `PartitaIvaDetector` Luhn-IT + zero-payload sentinel); four strategies (`MaskStrategy`, `HashStrategy` with deterministic SHA-256 + per-detector namespace + required salt, `TokeniseStrategy` reversible, `DropStrategy`); `Pii` Facade; `pii:scan` Artisan command (file + stdin); `DetectionReport` with counts + sample cap + `toArray`; non-final exception hierarchy per the W4.C lesson (`PiiRedactorException` parent + per-strategy / per-detector subclasses) |
| W7.B — askmydocs-pro foundation seed | askmydocs-pro #1 | `085a89c` | BSL 1.1 LICENSE with the four canonical parameters declared inline (Licensor: Padosoft di Lorenzo Padovani; Licensed Work: AskMyDocs PRO; Change Date: 4 years from each release date; Change Licence: Apache License, Version 2.0) + reference to https://mariadb.com/bsl11/; `composer.json` with `type: project` + `license: BUSL-1.1` + the v4 sister-package dependency train (`lopadova/askmydocs ^4.0.0-rc1`, `padosoft/laravel-ai-regolo ^0.2`, `padosoft/laravel-flow ^0.1`, `padosoft/eval-harness ^0.1`, `padosoft/laravel-pii-redactor ^0.1`); internal customer-facing `README.md` (NOT the open-source 14-section template — covers licence summary, commercial-licence contact `lorenzo.padovani@padosoft.com`, link to AskMyDocs CE); `.claude/` vibe-coding pack imported from `padosoft/laravel-patent-box-tracker` `main`; `.github/workflows/ci.yml` with `composer validate --strict --no-check-publish` plus `php -l` syntax check (the lint loop tolerates an empty `src/` and skips with a clear message — CI passes without product code); `.editorconfig` UTF-8 LF + `.gitignore` standard PHP / Laravel + `CHANGELOG.md` with `## [Unreleased]` block |
| W7.B fix-up — Copilot review must-fix items | askmydocs-pro #2 | `53577ce` | LICENSE: switched the BSL parameter label from "Change Licence" (UK spelling) to **"Change License"** (US spelling) per the canonical mariadb.com/bsl11 wording — both occurrences fixed; CONTRIBUTING.md prepended with a foundation-only-state caveat clarifying that `composer install` / `vendor/bin/phpunit` / `vendor/bin/phpstan` document the v0.1.0+ steady-state workflow (until product code lands, only `composer validate --strict` is functional); CHANGELOG.md surfaces the canonical-US-spelling decision so it's grep-able outside the LICENSE diff |

## Acceptance gates passed

### `padosoft/laravel-pii-redactor`

- CI matrix on every laravel-pii-redactor PR — PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13 (6 cells per PR) plus Pint + PHPStan level 6 + the architecture suite — converged GREEN before merge on every PR.
- Test count post-W7.A merge: **68 PHPUnit unit tests / 122 assertions** (every detector has positive + invalid-checksum + wrong-length + edge-case tests) + **2 architecture tests / 10 assertions** in `StandaloneAgnosticTest` enforcing zero AskMyDocs / sister Padosoft package symbol leakage in `src/` + 1 placeholder Live test self-skipping on missing `PII_REDACTOR_LIVE`. Total 71 tests across the package.
- Standalone-agnostic invariant maintained throughout — zero references to `KnowledgeDocument`, `KbSearchService`, `kb_*` tables, `lopadova/askmydocs`, `padosoft/laravel-patent-box-tracker`, `padosoft/laravel-flow`, `padosoft/eval-harness`, or `padosoft/askmydocs-pro` in `src/`. `composer.json` declares only first-party Laravel components — NO `laravel/ai`, NO `symfony/yaml`. The architecture test grep walks every `.php` file under `src/`.
- Copilot Code Review converged to **0 outstanding must-fix** on the W7.A PR after the R36 loop.
- Test fixtures use obviously-placeholder strings: `mario.rossi@example.com`, `IT60X0542811101000000123456`, `4242424242424242` (Stripe test PAN), `RSSMRA85T10A562S` (textbook codice fiscale example, not a real person). No real PII in committed test data.

### `padosoft/askmydocs-pro`

- CI rollup — `composer validate --strict --no-check-publish` + `php -l` lint loop — converged GREEN on PHP 8.3 / 8.4 / 8.5 on both PRs at merge time. The lint loop is intentionally degenerate for the foundation (no product code yet) but exists so the workflow is wired up and v4.1 PRs adding `src/` find a working baseline.
- Repository visibility verified `PRIVATE` before any push — the foundation seed is BSL-1.1 commercial, not open source.
- Copilot Code Review converged to **0 outstanding must-fix** after the R36 loop. PR #1 merged before Copilot's formal review fully landed (CI green at 14:55, formal review at 14:59, merge at 15:00); three of the four must-fix were absorbed by Copilot's auto-fix commit `c81de70`, the remaining four were landed via PR #2 (`53577ce`).

## Lessons captured during W7

The lessons below are codified back into the AskMyDocs `.claude/skills/` pack and into agent memory so future PRs (on AskMyDocs and on every padosoft/* repo) inherit the fixes.

- **Wait for BOTH the formal Copilot review AND the conversational reply before squash-merge** (W7.B PR #1 → PR #2 sequence). Copilot's review pipeline emits two artefacts on every PR: the **formal review** (`/pulls/<N>/reviews`) and the **conversational comments** (`/issues/<N>/comments`). The R36 loop must poll BOTH endpoints. PR #1 squash-merged at 15:00 with CI green at 14:55; the formal review landed at 14:59 with four must-fix items, three of which Copilot's own auto-fix commit `c81de70` absorbed but one needed PR #2 anyway. Lesson: do not squash-merge before BOTH artefacts arrive — wait at least 5 minutes after CI completion on the LAST commit, not just the initial commit. Codified in skill `copilot-pr-review-loop` (existing) — the dual-bot polling pattern was already documented after W4 patent-box-tracker PR #2 cycle 2; W7 reinforces it.
- **BSL parameter spelling**. The canonical BSL 1.1 wording at https://mariadb.com/bsl11/ uses "Change License" (US spelling), not "Change Licence" (UK spelling). When ingesting an MIT-or-BSD-trained operator's first-pass BSL LICENSE draft, grep for "Licence" and replace before opening the PR. Recorded for any future BSL-licensed Padosoft package.
- **Zero `laravel/ai` dependency on the v0.1 surface** of the pii-redactor was a deliberate choice — the package is regex + checksum based, not LLM-based. Every Padosoft package's `composer.json` should declare ONLY the runtime deps the v0.1 surface actually uses. The `suggest` block is the right place to advertise optional integrations (e.g. an `Ner-based detector` v0.2 candidate could `suggest: padosoft/laravel-ai-regolo`).
- **Italian fiscal identifier checksums are non-trivial**. The IBAN mod-97 implementation must do PHP_INT_MAX-safe chunked arithmetic — a naive `bcmod($numericIban, 97)` works only if `bcmath` is enabled, which is not guaranteed across hosting environments; the v0.1 implementation does string-chunked manual arithmetic so it works on every PHP 8.3+ build with no extension dependencies. The Codice Fiscale CIN checksum is a per-character lookup table from DM 23/12/1976; the test suite covers known textbook examples to lock the implementation. Future Padosoft packages handling fiscal identifiers should inherit the same standalone-arithmetic pattern.
- **The askmydocs-pro composer manifest declares the v4 release train BEFORE any product code lands**. Locking the dependency surface at the foundation seed means v4.1+ product PRs cannot accidentally drift out of the sister-package versions sanctioned by the v4.0 release. The pattern is re-usable for any future commercial sister-package seed.

## Production impact

### `padosoft/laravel-pii-redactor`

W7.A ships `padosoft/laravel-pii-redactor` v0.1.0 to Packagist. The tag landed on `padosoft/laravel-pii-redactor` `main` after the W7.A PR merged at `956089b`; the GitHub release is published and the package is installable via `composer require padosoft/laravel-pii-redactor:^0.1`.

The package is **standalone-agnostic** — zero `require` on `lopadova/askmydocs`, `padosoft/askmydocs-pro`, `padosoft/laravel-patent-box-tracker`, `padosoft/laravel-flow`, `padosoft/eval-harness`, or any other sister Padosoft package. `composer require padosoft/laravel-pii-redactor` on a fresh empty Laravel application produces a working PII redactor that detects Italian fiscal identifiers and applies any of the four strategies.

Footprint: ~1,300 LOC across 16 PHP files under `src/`, 68 Unit tests / 122 assertions + 2 Architecture tests / 10 assertions, opt-in Live testsuite. CI matrix PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13. The `.claude/` vibe-coding pack ships in the box per `feedback_package_readme_must_highlight_vibe_coding_pack`.

**Forward-looking integration in AskMyDocs**: `padosoft/laravel-pii-redactor` is the natural redaction layer for the AskMyDocs ingestion pipeline. A v4.1 candidate is wiring `RedactorEngine` into `DocumentIngestor::ingestMarkdown()` so PII never makes it into `knowledge_chunks` — the cross-tenant isolation rule R30 already prevents leak across tenants, but redaction is the strict-on-prem GDPR layer that some enterprise customers require.

### `padosoft/askmydocs-pro`

W7.B seeds `padosoft/askmydocs-pro` (private BSL-1.1) on GitHub. No Packagist release — the package is private. Foundation-only: LICENSE + `composer.json` declaring the v4 sister-package dependency train + internal README + `.claude/` pack + CI lint loop + .editorconfig + .gitignore + CHANGELOG.

The composer manifest's `require` block locks the v4 sister-package versions:

- `lopadova/askmydocs ^4.0.0-rc1` (the CE base — RC channel until v4.0 GA)
- `padosoft/laravel-ai-regolo ^0.2` (W2)
- `padosoft/laravel-flow ^0.1` (W5)
- `padosoft/eval-harness ^0.1` (W6)
- `padosoft/laravel-pii-redactor ^0.1` (W7)

The patent-box-tracker is intentionally NOT in the dependency train — askmydocs-pro is the commercial product, the tracker is Lorenzo's personal Patent Box dossier tool, and the architectural separation matters.

Product code (the actual PRO features layered on top of CE) lands in v4.1+ — out of scope for v4.0.

## Residual items parked

### `padosoft/laravel-pii-redactor` v0.2

Per the W7.A PR body and the README §16 Roadmap:

- Heuristic Italian street-address detector (deferred to v0.2 alongside the NER layer — the v0.1 scope was kept tight to the spec's six detectors).
- NER-based detector layer (`laravel/ai` integration as an opt-in strategy for free-form name / address recognition).
- `audit_trail_enabled` config key (currently informational; v0.2 will emit detection events for downstream subscribers).
- Additional national fiscal identifier detectors (Spanish DNI, French SIRET, German Steuer-Identifikationsnummer, UK National Insurance number, US SSN) — community-contribution candidates.

### `padosoft/askmydocs-pro` v4.1+

- Product code under `src/` (currently the foundation has none).
- `tests/` scaffolding aligned with the BSL release train.
- Service provider, migrations, controllers — none of those exist yet.
- Inline README rewrite once product code lands (currently customer-facing only and intentionally minimal).

## Next: W8 — final v4.0.0 GA + merge to main per R37

Per `project_v40_week_sequence`: the closing milestone of the v4.0 cycle. W8 work covers any remaining release-engineering polish across the integration branch, then `feature/v4.0` merges into `main` per R37 (single merge per major release) and the `v4.0.0` GA tag lands. The `feature/v4.0` integration branch closes after the merge; the next major (`feature/v4.1`) starts fresh from the new `main`.

Per R39, `v4.0.0-rc4` ships at this W7 closure (gated by the docs PR you are reading). RC2 / RC3 / RC4 land as separate `gh release create --prerelease` invocations against the same `feature/v4.0` HEAD SHA, since W5 / W6 / W7 closed in a single 24-hour window — the rc tag train serialises milestone visibility even when the underlying milestones rolled up tightly.
