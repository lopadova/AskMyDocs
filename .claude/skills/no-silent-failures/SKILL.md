---
name: no-silent-failures
description: Never ignore the return value of a side-effecting call. Storage::put/delete/copy, @mkdir, file_put_contents, copy, rename all return false on failure — surface that failure as an HTTP error or exception instead of pretending success. Also bans @-silenced errors and world-writable (0777) permissions. Trigger when editing controllers that write to a disk (KbIngestController), bootstrap/test setup code that creates directories, or any new I/O path.
---

# No silent failures

## Rule

Every side-effecting call whose return value signals success or failure must
be checked. When it fails, **surface** the failure — never log and continue.

Applies to:

- `Storage::disk(...)->put/delete/copy/move/makeDirectory/deleteDirectory(...)`
- `file_put_contents`, `copy`, `rename`, `unlink`, `mkdir`, `rmdir`
- `@`-prefixed variants — ban them outright.
- Library calls that return `false` on failure (HTTP clients with `throw =>
  false`, Flysystem writes).

## Why this exists

Copilot flagged (PR #5) that `KbIngestController` called
`Storage::disk($disk)->put(...)` without checking the return. The `kb` disk
is configured with `throw => false`, so a disk-full / permission failure
returned `false`, the controller answered `202 Accepted`, and
`IngestDocumentJob` later died with "file not found". From the client's
perspective the ingestion silently dropped documents.

Copilot also flagged (PR #4) `@mkdir($cacheDir, 0777, true)` in
`tests/bootstrap.php` — both the `@` silencer and the world-writable
permissions are unacceptable.

## Patterns

### Storage write

```php
$ok = Storage::disk($this->disk)->put($target, $content);

if ($ok === false) {
    throw new RuntimeException("Unable to persist {$target} on disk {$this->disk}.");
    // …or: return response()->json(['error' => 'write_failed'], 503);
}
```

### Directory creation

```php
if (! is_dir($cacheDir) && ! mkdir($cacheDir, 0755, true) && ! is_dir($cacheDir)) {
    throw new RuntimeException("Unable to create cache dir: {$cacheDir}");
}
```

### HTTP client (the app pattern)

```php
$response = Http::withToken($token)
    ->timeout(config('ai.providers.openai.timeout'))
    ->post($url, $payload);

$response->throw();  // or inspect $response->failed() explicitly
```

## Checklist before opening a PR

- [ ] Grep the diff for `@mkdir`, `@unlink`, `@file_`, `@copy`, `@rename` —
      remove the `@`.
- [ ] Grep for `Storage::` / `->put(` / `->delete(` / `->copy(` — every
      return value is checked or the call is wrapped in a method that does.
- [ ] No `0777` permissions. `0755` for directories, `0644` for files.
- [ ] HTTP responses from AI providers call `->throw()` or explicitly handle
      `failed()` — the user path already does this.
- [ ] The failure surfaces to the caller as a non-2xx HTTP response, a CLI
      exit code ≠ 0, or a thrown exception. Logging alone is not enough.

## Counter-example

```php
// ❌ `put()` can return false; controller answers 202; job crashes later.
Storage::disk($disk)->put($target, $content);
IngestDocumentJob::dispatch(...);
return response()->json(['queued' => 1, 'status' => 'queued'], 202);

// ❌ Silent mkdir; PHPUnit fails with an opaque error when it can't write.
@mkdir($cacheDir, 0777, true);
```
