---
name: surface-failures-loudly
description: Never return 200 with an empty / null / NaN body where 4xx or 5xx is the correct semantic. Applies to endpoints, PDF renderers, log readers, React Query fetchers, chart components, and any code path where a failure silently becomes a "successful" response. Trigger when editing controllers that return files / previews, services that wrap an external call, React Query fetchers, renderer classes in `app/Services/Admin/Pdf/`, log-reader services, or chart components with array-reduction math.
---

# Surface failures loudly

## Rule

Every HTTP endpoint, renderer, log reader, and preview path must map
failure → the correct status code (404 missing, 500 unreadable, 503
downstream outage). Empty body on 200, `""` PDF on 200, `null` JSON on
500-underneath, `-Infinity` / NaN in chart coordinates — these are all
the same bug: **the caller cannot tell success from silent failure.**

Corollary: choose exception TYPE to drive status code, never exception
MESSAGE. Sniffing `str_starts_with($ex->getMessage(), 'File missing')`
is brittle — any copy change flips the status code.

## Symptoms in a review diff

- A `return response(...)->header(...)` path where the file handle is
  never checked for `false`.
- `return null` in a `useMutation` / `useQuery` fetcher after a
  `catch` — React Query now sees `isError=false`.
- `Math.max(...data)` or `Math.min(...data)` without an `arr.length ===
  0` guard.
- A `catch (RuntimeException $e)` that chooses between 404 and 500 by
  reading `$e->getMessage()`.
- An `(int) $maybeZero` or `$totalLines === 0` branch that discards a
  non-empty single-line edge case.
- A service method whose contract returns `string` but falls back to
  `''` when the underlying library returned a non-string value.

## How to detect in-code

```bash
# Controllers returning 200 in what looks like an error branch
rg -n "response\(\)->json\([^)]*\], *200\)" app/Http/Controllers/
rg -n "return response\(\)->make\(.*, *200\)" app/Http/Controllers/

# FE silent null-on-error
rg -n "catch[^{]*\{\s*return null" frontend/src/

# Unguarded reducer math
rg -n "Math\.(max|min)\(\.\.\." frontend/src/

# Message-prefix sniffing
rg -n "str_starts_with\(\\\$[a-z]+->getMessage" app/Http/Controllers/
```

## Fix templates

### Controller: 200-on-missing → proper 404/500

```php
// ❌ PR #25 KbDocumentController::printable
$body = $this->readMarkdown($document);  // returns null on missing/unreadable
return response()->make($body ?? '', 200)->header('Content-Type', 'text/html');

// ✅
try {
    $body = $this->readMarkdown($document);
} catch (FileNotFoundException $e) {
    abort(404, 'Markdown file not found on disk.');
} catch (UnreadableFileException $e) {
    abort(500, 'Markdown file is on disk but unreadable.');
}
return response()->make($body, 200)->header('Content-Type', 'text/html');
```

### Renderer: empty-string PDF → throw

```php
// ❌ PR #27 DompdfPdfRenderer::render
$raw = $this->dompdf->output();
return is_string($raw) ? $raw : '';

// ✅
$raw = $this->dompdf->output();
if (! is_string($raw) || $raw === '') {
    throw new PdfEngineFailure('Dompdf returned non-string / empty output.');
}
return $raw;
```

### React Query: caught 500 → rethrow

```tsx
// ❌ PR #20 WikilinkHover
async function fetchWikilink(slug: string) {
  try { const r = await api.get(`/wikilink/${slug}`); return r.data; }
  catch { return null; }  // React Query never sees isError
}

// ✅
async function fetchWikilink(slug: string) {
  const r = await api.get(`/wikilink/${slug}`);  // let React Query handle failures
  return r.data;
}
// The component reads `isError` and renders the error state.
```

### Chart: empty-array → explicit empty state

```tsx
// ❌ PR #19 AreaChart
const max = Math.max(...data) * 1.1;  // -Infinity when data = []

// ✅
if (data.length === 0) return <div data-testid="chart-empty" data-state="empty" />;
const max = Math.max(...data.map(d => d.value)) * 1.1;
```

### Log tail: single-line file

```php
// ❌ PR #28 LogTailService
$file->seek(PHP_INT_MAX);
$total = $file->key();       // 0 when file is single-line
if ($total === 0) return []; // drops the only line

// ✅
if (filesize($path) === 0) return [];
$file->seek(PHP_INT_MAX);
$total = $file->key() + 1;   // 1-based count
```

### Exception type, not message, drives status code

```php
// ❌ PR #28 LogViewerController::application
} catch (RuntimeException $e) {
    $status = str_starts_with($e->getMessage(), 'File missing') ? 404 : 500;
    abort($status, $e->getMessage());
}

// ✅
} catch (MissingLogFile $e)       { abort(404, $e->getMessage()); }
  catch (UnreadableLogFile $e)     { abort(500, $e->getMessage()); }
```

## Related rules

- R4 — no silent failures on side-effecting calls (complement: R4 is
  about `put()` → `false`; R14 is about the HTTP/DOM surface).
- R7 — no `@`-silenced errors.
- R11 — FE surfaces errors in the DOM (no swallowed `useMutation`).

## Enforcement

Currently no dedicated CI script. The `copilot-review-anticipator`
sub-agent (`.claude/agents/copilot-review-anticipator.md`) includes
grep patterns for R14 in its pre-push review pass. A future script
could grep for the patterns above; the per-symptom signal-to-noise
ratio is too low for a blanket gate, so prefer the agent review.

## Counter-example

```php
// ❌ Answers 200 with zero-byte PDF; caller can't tell rendering failed.
public function exportPdf(Document $doc): Response
{
    $pdf = $this->renderer->render($doc);
    return response($pdf, 200)->header('Content-Type', 'application/pdf');
}

// ❌ Non-string -> '' -> 200 with zero bytes.
return is_string($raw) ? $raw : '';

// ✅ Throw on failure; controller translates to 5xx.
if (! is_string($raw) || $raw === '') {
    throw new PdfEngineFailure(...);
}
return $raw;
```
