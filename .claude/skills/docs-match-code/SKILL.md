---
name: docs-match-code
description: Schema, signature, env-var, and config snippets in CLAUDE.md, .github/copilot-instructions.md, README.md and SKILL.md files must match the real code. Verify against migrations, models, config files, and Artisan signatures before merging. Trigger when editing any documentation file that quotes column names, env vars, command flags, route paths, or config keys; or when changing a migration / model / command / config / route in a way that any of those docs reference.
---

# Docs match code

## Rule

Anything in a doc that names a real artifact — a column, an env var, a
config key, a command flag, a route, a signature — must be **verified
against the source** before commit. Stale docs are worse than missing docs:
they lie with authority and survive grep.

Specifically, before editing:

- A schema description → open the matching migration in
  `database/migrations/` (and the test mirror under
  `tests/database/migrations/`) and copy the column names verbatim.
- An env var listing → open `.env.example` AND `config/<area>.php` and
  cross-check the key name + default.
- A command signature → run `php artisan <command> --help` (or read
  `protected $signature` in the command class).
- An API route → open `routes/api.php` / `routes/web.php`.
- A non-trivial code snippet → load the referenced class and confirm the
  method exists with that signature.

## Why this exists

Copilot flagged on PR #7 that the `knowledge_chunks` schema in `CLAUDE.md`
named a `chunk_index` column and omitted `chunk_hash`. The actual migration
ships `chunk_order`, `chunk_hash`, plus `project_key` and `metadata`. The
`knowledge_documents` summary was similarly partial. Both errors would have
been caught by a 30-second look at
`database/migrations/2026_01_01_000002_create_knowledge_chunks_table.php`.

A wrong schema in `CLAUDE.md` is load-bearing damage: future PRs use the
file as a quick-reference and silently propagate the mistake into queries,
tests, and follow-up docs.

## Patterns

### Cross-check a schema before quoting it

```bash
# Bash one-liner: list every column the migration actually creates
php artisan tinker --execute="echo collect(Schema::getColumnListing('knowledge_chunks'))->implode(', ');"

# …or just grep the migration:
grep -E "\\\$table->" database/migrations/*knowledge_chunks*
```

Then write the doc against that output, not from memory.

### Cross-check an env var

```bash
grep '^KB_' .env.example                    # env names + comments
grep -RIn 'config(.kb' app/ config/         # who reads them
grep -RIn 'env(.KB_' config/                # default chain
```

The README quick-start, `.env.example`, and `config/kb.php` must agree on
the **same** key name with the **same** default. If they don't, fix all
three in the same diff (this is also rule R6).

### Cross-check an Artisan signature

```bash
php artisan kb:ingest-folder --help
```

Copy the signature/options from the help output, not from a half-remembered
PR description.

## Checklist before opening a PR that touches docs

- [ ] Every column name quoted in `CLAUDE.md` /
      `.github/copilot-instructions.md` matches a `$table->` line in the
      corresponding migration.
- [ ] Every env var quoted matches `.env.example` AND the `env('…')` call
      in `config/*.php`.
- [ ] Every command name + flag matches `php artisan <command> --help`.
- [ ] Every route path matches `routes/api.php` / `routes/web.php`.
- [ ] Every class / method referenced in a code snippet actually exists
      (open it; do not paste from memory).
- [ ] When you rename a column, env var, config key, command flag, or
      route, grep the docs and update every mention in the same commit.

## Counter-example

```markdown
### `knowledge_chunks`
`id`, `knowledge_document_id` FK, `chunk_index`, `chunk_text`,
`heading_path`, `embedding vector(N)`.
```

The actual migration creates `chunk_order` (not `chunk_index`) and a
`chunk_hash` column the doc forgot. Anyone writing a query off this doc
will get a "column does not exist" error.

## Correct example

```markdown
### `knowledge_chunks`
`id`, `knowledge_document_id` FK (ON DELETE CASCADE), `project_key`,
`chunk_order`, `chunk_hash` (SHA-256), `heading_path`, `chunk_text`,
`metadata` JSON, `embedding vector(N)`. UNIQUE
`(knowledge_document_id, chunk_hash)`.
```

Each column name copied straight from
`database/migrations/2026_01_01_000002_create_knowledge_chunks_table.php`.

---

## Extension: comment-drift + PROGRESS.md drift + docblock/impl drift

Distilled from the PR16 live re-harvest — 22 `doc-drift` findings
across PRs #16–#31. The migration-filename / column-rename drift the
original skill covered is half of the story. The other half is
**in-repo commentary drifting from code**: block comments atop a
component that no longer reflects its tab set, PROGRESS.md checklists
referencing filenames that do not exist, docblocks claiming "encrypted
+ sha256" token derivation when the impl is plain random + sha256,
config comments that say `TTL=0 disables` when the impl treats
`TTL=0` as "no expiry".

### Symptom classes

1. **Block comment vs impl** — the top-of-file comment lists tabs /
   props / behaviours that no longer match.
   - PR #26 `DocumentDetail.tsx` + `KbView.tsx`: block comment said
     "Preview/Meta/History only"; Source tab had been added.
   - PR #30 `MetaTab.tsx`: block comment said "renders nothing while
     loading"; impl renders explicit loading + error UIs.
2. **PROGRESS.md row drift** — the checklist claims filenames, PR
   numbers, test counts that don't line up with reality.
   - PR #23 PROGRESS: references a migration filename that doesn't
     exist.
   - PR #24 PROGRESS: G1 row "branch parent = PR7" but the checklist
     below is titled "PR8 — Phase G1".
   - PR #29 PROGRESS: migration filenames without timestamp prefix.
   - PR #30 PROGRESS: claims "4 new Playwright scenarios" while
     enumerating 6.
3. **Docblock vs impl** — the `/** */` over a class / method
   misrepresents the real contract.
   - PR #17 `LoginRequest::throttleKey()`: docblock says "hashing
     email" but impl only lower-cases.
   - PR #23 `UserStoreRequest`: docblock says email uniqueness spans
     "live + trashed"; rule scopes to `whereNull('deleted_at')`.
   - PR #29 `config/admin.php`: docblock says tokens are
     `Crypt::encryptString + sha256 nonce`; impl is plain random +
     `sha256($token)`. Same PR's migration docblock mismatches too.
4. **Migration filename drift** — docs reference a migration that
   doesn't exist or uses a different date prefix than the filesystem.
5. **Table name drift** — LESSONS.md / CLAUDE.md quotes
   `admin_command_audits` (plural) while migration creates
   `admin_command_audit` (singular). PR #29.
6. **Env-var comment drift** — `env.example` comment describes
   defaulting to `false` when the config actually defaults to `true`.

### Detection recipes

```bash
# PROGRESS.md filename claims vs real files
rg -n 'database/migrations/[0-9_]+_[a-z_]+\.php' docs/enhancement-plan/PROGRESS.md \
  | while IFS=: read -r file line quoted; do
      [[ -f "$quoted" ]] || echo "MISSING: $quoted (line $line)"
    done

# Block-comment-vs-impl drift
# Find components with a block comment at top and a `VALID_TABS` / tab-set const
rg -n --multiline '^/\*\*[\s\S]*?\*/\s*\n[\s\S]*?VALID_TABS' frontend/src/

# Table-name drift
rg -n "admin_command_audits|admin_command_audit" docs/ CLAUDE.md .github/

# Docblock claim grep
rg -n "sha256|encrypt|hash" app/Services/ -B 1 -A 2 | rg -C2 '/\*\*|//'
```

### Fix template — keep docs + impl in lock-step in the SAME PR

When you change:

| Source | Docs that reference it |
|---|---|
| `database/migrations/*` (new / rename) | `CLAUDE.md §4`, `README.md` quick-start, `docs/enhancement-plan/PROGRESS.md` checklist |
| `protected $signature` on an Artisan command | `CLAUDE.md §5 Scheduler`, `README.md` command list, `.env.example` comment for any knob |
| `config/<area>.php` default | `.env.example`, `README.md` "Configuration" section, any SKILL.md that cites the default |
| Block comment atop a component | Component code itself; if you add a tab, remove / update the comment in the same commit |
| Class docblock `/** */` | Impl must match; grep the docblock claims (`hash`, `encrypt`, `sha256`, `never`, `always`) against the body |

### PROGRESS.md row drift — the hardest to catch

Add a git-pre-commit hook in the agent-dispatch briefing: every time
the PR row in PROGRESS.md is edited, the agent must run
`rg 'database/migrations/[a-z0-9_/]+\.php' PROGRESS.md` and check
each referenced path with `test -f`. Failing to ship this check is
exactly why PR #23 / #29 / #30 each landed with a filename drift.

### Pre-PR checklist — extended

- [ ] Every column / env / flag / route / filename quoted in a doc
      was copied from the source of truth this diff modifies.
- [ ] Every block comment atop a modified component matches the
      component's current surface (tab set, prop list, behaviour).
- [ ] Every docblock (`/** */`) over a modified method matches the
      method body.
- [ ] PROGRESS.md filenames `test -f` clean.
- [ ] LESSONS.md table / column / env names match migration output.
- [ ] Counts (tests, scenarios, routes) match `vendor/bin/phpunit
      --list-tests | wc -l`, `npx playwright test --list | wc -l`.
