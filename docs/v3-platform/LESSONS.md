# AskMyDocs v3.0 — LESSONS log

**Purpose:** Append-only log of bugs, rules, discoveries, gotchas, and runtime decisions surfaced during v3.0 implementation. Every sub-agent appends here before committing. Orchestrator injects this file into every subsequent dispatch.

**Format (mandatory for every entry):**

```markdown
## [YYYY-MM-DD HH:MM] Sub-task <ID> — <Title>

**Type:** bug | rule | discovery | gotcha | decision-runtime
**Severity:** low | medium | high | critical
**Applies to:** <area> (e.g. "ConverterInterface implementations", "FE composer", "all PDF processing")

**Finding:**
<2-4 lines: what was discovered>

**Why it matters:**
<1-2 lines: impact on future agents>

**How to apply:**
<1-2 lines: what to do differently>

**References:** <file:line(s) or PR/commit>
---
```

**Rules:**
- Append-only. Never modify existing entries.
- Every sub-task MUST add at least one entry (even `Type: discovery, Severity: low, Finding: nessuna scoperta rilevante`).
- Sort: chronological (newest at the bottom).
- T4.1 will produce `LESSONS-v3.0-digest.md` from this file at the end of v3.0.

---

<!-- ENTRIES BELOW — first entry will be appended by T1.1 sub-agent -->

## [2026-04-26 17:55] Sub-task T1.1 — Core Interfaces + DTOs

**Type:** discovery
**Severity:** medium
**Applies to:** all v3 sub-agents running on Windows + every v3 task that interacts with composer

**Finding:**
Three concrete environment lessons discovered while building T1.1 contracts:
1. `php` is NOT on bash PATH in this Windows env. Must use the PowerShell tool (`pwsh -c "php ..."`) per the existing `env_php_shim` memory. Bash `vendor/bin/phpunit` returns `/usr/bin/env: 'php': No such file or directory`.
2. `composer.lock` is `.gitignore`d in this project. Every fresh clone (and every agent's first run) must execute `composer install` from a fresh state — but if `composer.json` declares packages whose lock state isn't reproducible (e.g. recently added deps), tests fail with cryptic `Class X not found` errors. Today this hit `spatie/laravel-permission` ^6.25 + `spatie/laravel-activitylog` (declared in composer.json but no historical lock to reinstall from). Symptom: 126/242 tests in Unit suite fail with `Class "Spatie\Permission\PermissionServiceProvider" not found`. Fix: `composer update spatie/laravel-permission spatie/laravel-activitylog`. Cannot commit composer.lock (gitignored) — the fix must be re-applied per-environment until T4.2 documents this in README's "Quick Start" section as a setup step.
3. PHP 8.3 `final readonly` + property-promotion DTO pattern works cleanly for the v3 pipeline DTOs. The repo declares `php ^8.3` in composer.json, so this pattern is portable across the supported range (8.3, 8.4, 8.5 — all matrix entries in `tests.yml`). Constructor-promoted public readonly props are testable via direct access; no getters required.

**Why it matters:**
- Subsequent agents on Windows MUST use PowerShell tool, not Bash, for php commands.
- `composer.lock` is intentionally NOT committed in this repo. Bootstrap on a fresh clone always runs `composer install` against `composer.json`. Newly declared deps that fail to resolve must be installed via `composer update <pkg>`, NOT `composer require` (which mutates the manifest again and risks bumping unrelated packages).
- The `final readonly` + constructor-promotion pattern is the standard for ALL v3 pipeline DTOs (SourceDocument, ConvertedDocument, ChunkDraft, RetrievalFilters, ConnectorCredential, ...).

**How to apply:**
- For tests/lint commands: prefer the PowerShell tool over Bash.
- For composer changes: NOTE that `composer.lock` is gitignored in this repo, so it cannot be committed. After modifying `composer.json` (T1.5 adds smalot/pdfparser, T1.6 adds phpoffice/phpword), you must (a) run `composer update <pkg>` for the dependency you changed so it resolves locally without re-mutating the manifest, (b) verify a fresh-clone bootstrap still works via `composer install` against the manifest alone, and (c) document the new dep in README's setup section. Future agents bootstrapping locally should start with `composer install` after pulling, then run `composer update <pkg>` only if a declared dependency fails to resolve.
- For new DTOs: follow the pattern in `app/Services/Kb/Pipeline/SourceDocument.php` — `final readonly class X { public function __construct(public string $a, public ?int $b, public array $c) {} }`.

**References:** `app/Services/Kb/Pipeline/SourceDocument.php`, `app/Services/Kb/Contracts/ConverterInterface.php`, `tests/Unit/Services/Kb/Pipeline/ContractsTest.php`

---

## [2026-04-26 22:25] Sub-task T1.2 — MarkdownChunker behind ChunkerInterface

**Type:** discovery + decision-runtime
**Severity:** medium
**Applies to:** all v3 sub-agents that follow plan literal-text without first inspecting the existing code; future ChunkerInterface implementations (T1.7 PdfPageChunker); T1.4 DocumentIngestor refactor.

**Finding:**
Three concrete lessons surfaced during T1.2:

1. **The plan's stated "existing signature" was wrong.** Plan §Task 1.2 Step 3 says the legacy method is `chunk(string $markdown, string $filename, ?string $strategy = null): array`. The REAL existing signature is `chunk(string $filename, string $markdown): Collection` (filename FIRST, returns Illuminate `Collection`, no strategy arg). Following the plan literally would have broken 22 callsites in `tests/Unit/Kb/MarkdownChunkerTest.php` (which call `->chunk('f.md', $md)`) and the Mockery expectation in `tests/Feature/Kb/DocumentIngestorTest.php` (which returns a Collection of bare arrays). I preserved the REAL signature verbatim under the renamed `chunkLegacy()` method — parity is what the Verification Gate required, not plan literal-text.

2. **The plan's "Files" list is necessary-but-not-sufficient.** Plan listed only `app/Services/Kb/MarkdownChunker.php` + the new test file. But renaming `chunk` → `chunkLegacy` cascades to ALL callers: `app/Services/Kb/DocumentIngestor.php:59`, 22 calls in `tests/Unit/Kb/MarkdownChunkerTest.php`, and (less obvious) a Mockery `shouldReceive('chunk')` expectation in `tests/Feature/Kb/DocumentIngestorTest.php:29`. The Feature test failure surfaced ONLY when running the full Feature suite — the Unit suite passed without touching it. Always run BOTH suites at the Verification Gate even when the plan only specifies Unit.

3. **`Mockery::mock(ConcreteClass)` does NOT proxy unstubbed calls** — it raises `Mockery\Exception\BadMethodCallException: Received Mockery_X::chunkLegacy(), but no expectations were specified`. So renaming a public method silently breaks every mock that pinned the OLD name, and the failure surfaces only when that specific test runs. There is no "rename public method on a class with mocks pinned to it" tooling; you must grep `shouldReceive('<oldname>')` across `tests/` whenever you rename.

**Why it matters:**
- T1.7 (PdfPageChunker) and T1.5/T1.6 (Pdf/Docx converters) will face the same "plan-vs-reality" gap. Sub-agents must inspect the existing code BEFORE accepting the plan's signature claims.
- T1.4 (DocumentIngestor refactor) will be the cutover that finally drops `chunkLegacy()` AND the 22 legacy test calls. Plan it as a single atomic refactor: switch the caller, update the 22 tests to the new signature, delete `chunkLegacy()`. Don't try to keep both methods past T1.4.
- Future renames of public methods on classes consumed by Mockery: always `grep -rn "shouldReceive('<oldname>')" tests/` BEFORE assuming Unit-only is sufficient.

**How to apply:**
- For any "rename existing method" step: run `grep -rn "->\w*<oldname>(\|shouldReceive('<oldname>')" app/ tests/` first. Update all hits in the same commit.
- Always run `--testsuite=Unit` AND `--testsuite=Feature` at the Verification Gate, even if the plan lists only Unit.
- When the plan's claimed "existing signature" disagrees with the source: trust the source, document the deviation in the progress log + LESSONS.md, do NOT silently break parity to match the plan literal.
- Adapter pattern is fine: new `chunk(ConvertedDocument)` delegates to `chunkLegacy()` via a thin map to ChunkDraft. No logic duplication, no stale code paths.

**References:** `app/Services/Kb/MarkdownChunker.php` (chunk + chunkLegacy + adapter), `app/Services/Kb/DocumentIngestor.php:59` (callsite update), `tests/Unit/Services/Kb/MarkdownChunkerInterfaceTest.php` (T1.2 own tests), `tests/Unit/Kb/MarkdownChunkerTest.php` (22 cascade renames), `tests/Feature/Kb/DocumentIngestorTest.php:29` (Mockery rename), `docs/v3-platform/progress/T1.2.md`.

---

## [2026-04-26 22:55] Sub-task T1.2 — Orchestrator workflow gotchas (cycle-2 addendum)

**Type:** rule + discovery
**Severity:** high
**Applies to:** EVERY future v3 sub-task PR loop (the orchestrator and every fix-cycle agent).

**Finding:**
Two operational gotchas surfaced during the Copilot fix loop on PR #37 — both block the workflow silently if not handled:

1. **Copilot does NOT auto-re-trigger on a fix push when its prior review state is `COMMENTED`.** Cycle-1 review was COMMENTED (2 must-fix inline comments). After pushing the fix commit `7c4117d`, no new "Copilot code review" workflow run appeared and the reviews API still showed only the cycle-1 entry tied to the original commit. Copilot does NOT behave like a human reviewer here — it requires an explicit re-request. Workaround: `gh api "repos/<owner>/<repo>/pulls/<n>/requested_reviewers" -X POST -f 'reviewers[]=copilot-pull-request-reviewer[bot]'` after every fix push. Cycle-2 review then arrives ~5 min later. Without this re-request, the orchestrator polls forever waiting for a review that never comes.

2. **`.github/workflows/tests.yml` ONLY runs on `main` push and PR-against-`main` (`on: { push: { branches: [main] }, pull_request: { branches: [main] } }`).** PR #37 targets `feature/v3.0-pipeline-foundation`, so PHPUnit / vitest / lint workflows DO NOT execute on these intra-feature PRs. The PR is gated SOLELY by Copilot review. This means: (a) the Verification Gate run by the sub-agent locally is the ONLY automated test signal before merge, (b) any Playwright-flake escalation rule from the prompt is moot for these PRs (no Playwright runs), (c) when T4.6 finally opens the v3.0 → main PR, the FULL test suite will fire for the first time across all v3 commits — surprises possible. Plan T4.x accordingly.

**Cycle-2 verdict pattern:** when Copilot returns COMMENTED with body containing the literal string `"generated no comments"` and no new entries in `pulls/<n>/comments`, that IS the approval signal — there is no formal APPROVED state with this app config. Treat as merge-ready.

**Why it matters:**
- Without rule (1), the orchestrator wastes 10-15 min polling a review queue that's empty by design, then escalates falsely.
- Without rule (2), an agent might assume "PR opened, CI will catch any regression I missed" and skip the local Feature-suite gate. There IS no CI on these branches.
- Cycle-2 verdict pattern is the only deterministic merge signal — relying on `reviewDecision` (which stays empty) or the literal "APPROVED" string would block forever.

**How to apply:**
- After every fix push: re-request Copilot via REST POST. Bake into the orchestrator's STEP H.
- Sub-agents: run BOTH Unit AND Feature suites at the Verification Gate. Do not assume CI will catch it. (Reinforces T1.2 main entry rule 2.)
- Merge-readiness check: review state COMMENTED + body contains "generated no comments" + zero new entries in `pulls/<n>/comments` after the fix commit's sha → merge-eligible.
- Wakeup cadence: 270s (within 5-min cache TTL) is the right poll interval; cycle-2 review arrives ~3-5 min after re-request based on PR #37 timing.

**References:** PR #37 cycle-1 (commit 4f30ea2) review at 20:29:42Z, fix push 7c4117d at 22:38, re-request via API at ~22:45, cycle-2 (commit 7c4117d) review at 20:50:28Z, merge sha 050c75e. Closeout commit `e7f8672` on `feature/v3.0-pipeline-foundation`.

---
