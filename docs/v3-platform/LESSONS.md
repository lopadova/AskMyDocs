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
3. PHP 8.3 `final readonly` + property-promotion DTO pattern works cleanly for the v3 pipeline DTOs (PHP 8.4.20 confirmed). Constructor-promoted public readonly props are testable via direct access; no getters required.

**Why it matters:**
- Subsequent agents on Windows MUST use PowerShell tool, not Bash, for php commands.
- If a future task touches composer.json, run `composer update <pkg>` AND verify `composer.lock` is committed in the same commit.
- The `final readonly` + constructor-promotion pattern is the standard for ALL v3 pipeline DTOs (SourceDocument, ConvertedDocument, ChunkDraft, RetrievalFilters, ConnectorCredential, ...).

**How to apply:**
- For tests/lint commands: prefer the PowerShell tool over Bash.
- For composer changes: NOTE that `composer.lock` is gitignored in this repo. After modifying `composer.json` (T1.5 adds smalot/pdfparser, T1.6 adds phpoffice/phpword), you must (a) run `composer require <pkg>:<version>` so it's installed locally, (b) verify CI installs the same version (CI runs `composer install` against the JSON since there's no lock), and (c) document the new dep in README's setup section. Future agents bootstrapping locally must run the same `composer require` or `composer update <pkg>` after pulling.
- For new DTOs: follow the pattern in `app/Services/Kb/Pipeline/SourceDocument.php` — `final readonly class X { public function __construct(public string $a, public ?int $b, public array $c) {} }`.

**References:** `app/Services/Kb/Pipeline/SourceDocument.php`, `app/Services/Kb/Contracts/ConverterInterface.php`, `tests/Unit/Services/Kb/Pipeline/ContractsTest.php`

---
