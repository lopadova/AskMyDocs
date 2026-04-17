---
name: github-action-hygiene
description: Avoid the three recurring bugs in .github/actions/ingest-to-askmydocs/action.yml — ARG_MAX when passing file content via jq --arg, pattern drift between full-sync and diff mode, and git diff --diff-filter that misses renames. Trigger when editing the composite action, its inputs, or any new action.yml/workflow that batches ingest/delete requests.
---

# GitHub Action hygiene (ingest / delete pipeline)

## Rule

The composite action at `.github/actions/ingest-to-askmydocs/action.yml` has
three known failure modes. Every edit to that file (and to any workflow that
mimics its shape) must keep the guardrails intact.

## Bug 1 — ARG_MAX when passing file content via `jq --arg`

Copilot flagged (PR #5): `jq --arg content "$(cat "$file")" ...`
passes the entire file via the command-line. For docs ≥ a few hundred KB
that busts `ARG_MAX` and silently **strips the trailing newline** from the
content. Use `--rawfile` instead:

```bash
# ❌
jq --arg content "$(cat "$file")" '...'

# ✅
jq --rawfile content "$file" '...'
```

`--rawfile` reads from disk (no ARG_MAX), preserves every byte, and is how
the action must serialise any markdown body.

## Bug 2 — Pattern drift between full-sync and diff mode

Copilot flagged (PR #5): the default input `pattern` is `*.md`, but the diff
branch enumerates both `.md` **and** `.markdown` via `git diff`. Result:
full-sync silently skips `.markdown` files.

Keep the two branches in lock-step. The accepted pattern is:

```bash
collect_full_sync_files() {
  if [ "$PATTERN" = "*.md" ]; then
    find "$DOCS_PATH" -type f \( -name "*.md" -o -name "*.markdown" \) 2>/dev/null || true
  else
    find "$DOCS_PATH" -type f -name "$PATTERN" 2>/dev/null || true
  fi
}
```

…with matching user-facing `echo` messages for each branch.

## Bug 3 — `--diff-filter` missing renames

Copilot flagged (PR #6): the ingest set was collected with
`git diff --name-only --diff-filter=AM` (added + modified). Renames ship
under status `R…`. Result: a rename deletes the old source_path correctly
but the **new** path is never ingested, so the document disappears from the
KB until the next full sync.

The right split:

| set | diff-filter |
|---|---|
| Ingest (new + modified + renamed-to) | `AMR` |
| Delete (removed + renamed-from) | `DR` (use `RENAMED_OLD` for the `R` side) |

Always test the rename path explicitly. A rename is the single easiest way
for the RAG store to drift silently.

## Checklist before editing the action

- [ ] Any place that serialises a file body into JSON uses `jq --rawfile`.
- [ ] Full-sync `find` and diff `git diff` accept the same set of extensions.
- [ ] Ingest filter is `AMR`; delete filter is `DR` (or explicit `D` + `R`
      handling). A rename produces **one** ingest and **one** delete, not
      only one of the two.
- [ ] Non-2xx responses from `curl` fail the step (the action already does
      this; do not break it).
- [ ] Batch size respects the server hard-cap of 100; default 50 stays.
- [ ] A rename-only PR in a downstream consumer repo produces both an ingest
      for the new path and a delete for the old path — add a note to any
      manual smoke-test plan.

## Where to add tests

The composite action itself is tested indirectly via the controllers it
targets (`tests/Feature/Api/KbIngestControllerTest.php`,
`tests/Feature/Api/KbDeleteControllerTest.php`). Cover new behaviours by
asserting the controller accepts the exact JSON shape the action emits —
especially full-content bodies with trailing newlines.
