---
name: kb-path-normalization
description: Enforce App\Support\KbPath::normalize() on every KB source_path across controllers, jobs, commands, and tests. Prevents divergence between ingest and delete flows, blocks path traversal, and keeps paths idempotent. Trigger when touching KbIngestController, KbDeleteController, KbIngestFolderCommand, KbDeleteCommand, IngestDocumentJob, DocumentDeleter, DocumentIngestor, or any new code that accepts a user-supplied source_path / path argument.
---

# KB path normalization

## Rule

Every user-supplied KB source path **must** pass through
`App\Support\KbPath::normalize()` before it is used as a DB key, a disk read
key, or a disk write key. Do not re-implement `trim()`/`str_replace()` logic
inline.

```php
use App\Support\KbPath;

try {
    $path = KbPath::normalize($input);
} catch (\InvalidArgumentException $e) {
    // surface as 422 / exit 1 / validation failure — never swallow
}
```

## Why this exists

Copilot flagged (PR #6) that `KbDeleteCommand` and `KbDeleteController`
duplicated the `normalizePath()` logic from `KbIngestController`, with slight
differences — a document ingested as `docs//foo.md` was stored under
`docs/foo.md` but a later delete would query `docs//foo.md` and report "not
found". PR #5 also flagged that the original controller helper did not
reject `..` segments, leaving a path-traversal crack.

`KbPath::normalize()` is the single source of truth:

- Replaces `\` with `/`
- Collapses repeated `/` into one
- Trims leading/trailing `/`
- Rejects `.` and `..` segments (path-traversal guard)
- Throws `InvalidArgumentException` on empty input

## Checklist before opening a PR

- [ ] Search the diff for inline `str_replace('\\', '/', ...)`,
      `preg_replace('#/+#', '/', ...)`, or `trim($path, '/')` — replace with
      `KbPath::normalize()`.
- [ ] The HTTP controller surfaces the exception as `422` with a clear
      message (same key in ingest and delete responses).
- [ ] The Artisan command surfaces it with exit code `1`.
- [ ] A unit test in `tests/Unit/Support/KbPathTest.php` covers any new edge
      case you introduce.
- [ ] Ingest path and delete path normalize **identically** — assert with a
      feature test that POST then DELETE on the same payload succeeds.

## Counter-example (do not do this)

```php
// ❌ Divergence risk — KbDeleteController rolls its own trimmer
$path = trim($request->input('source_path'), '/');

// ❌ No path-traversal guard
$path = preg_replace('#/+#', '/', $path);
```

## Correct example

```php
use App\Support\KbPath;

public function __invoke(Request $r)
{
    $docs = collect($r->input('documents'))->map(function ($d) {
        return [
            'project_key' => $d['project_key'] ?? config('kb.ingest.default_project'),
            'source_path' => KbPath::normalize($d['source_path']),
            // ...
        ];
    });
    // dispatch jobs...
}
```
