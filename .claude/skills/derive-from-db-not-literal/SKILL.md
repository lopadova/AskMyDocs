---
name: derive-from-db-not-literal
description: UI dropdowns, filters, and file-extension handling derive from the real DB / API domain, not from a hard-coded sample. Applies to project-key filters, time-window selectors, role lists, file-extension handling in file-name derivation. Trigger when editing any component with a `<select>`, when adding a CLI/HTTP filter that mirrors a cache key, when deriving output filenames from a path, or when seeding fixtures that will drive production-like admin features.
---

# Derive from DB, not a literal subset

## Rule

Any UI list / filter / dataset that maps to a DB-derived domain
**must** fetch the actual domain, not hard-code a subset. The
canonical cases:

1. **Project-key filter** — query `SELECT DISTINCT project_key FROM
   knowledge_documents` (or a dedicated endpoint), never list
   `['hr-portal', 'engineering']` in FE.
2. **Time-window filter** — every time range the cache key encodes
   must be accepted by the query-layer; don't pin `days=7` in code
   while caching `(project, days)`.
3. **File-extension handling** — if the ingest pipeline accepts
   `.md` AND `.markdown`, output-filename derivation must strip
   both. Use `pathinfo(..., PATHINFO_FILENAME)`, not
   `basename(..., '.md')`.
4. **Seeders / backfills** — don't grant EVERY user access to EVERY
   tenant "because it makes the seed pretty". Use a DB-backed rule
   (ProjectMembership row per intent).

## Symptoms in a review diff

- `const projects = ['hr-portal', 'engineering'] as const;`
- `$days = 7; // topProjects hardcoded`
- `return basename($sourcePath, '.md') . '.pdf';` where ingest also
  accepts `.markdown`.
- Seeder loops: `foreach ($users as $u) foreach ($projects as $p) {
  ProjectMembership::create([...]); }`

## How to detect in-code

```bash
# Hard-coded project keys in FE
rg -n "'hr-portal'|'engineering'" frontend/src/features/admin/

# Time-window pinned in service
rg -n 'days\s*=\s*[0-9]+' app/Services/Admin/ app/Http/Controllers/Api/Admin/

# basename('.md')
rg -n "basename\([^,]+,\s*['\"]\\.md['\"]" app/

# Fan-out seeders
rg -n "foreach.*project.*foreach.*user" database/seeders/
```

## Fix templates

### Project filter from API (PR #24 KbView)

```tsx
// ❌
const PROJECT_OPTIONS = [
  { value: '', label: 'All projects' },
  { value: 'hr-portal', label: 'HR Portal' },
  { value: 'engineering', label: 'Engineering' },
];

// ✅ — use a shared hook
// frontend/src/features/admin/kb/useProjectKeys.ts
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

export function useProjectKeys() {
  return useQuery({
    queryKey: ['admin', 'project-keys'],
    queryFn: () => api.get('/api/admin/projects/keys').then(r => r.data),
    staleTime: 60_000,
  });
}

// component
const { data: keys = [] } = useProjectKeys();
const options = [{ value: '', label: 'All projects' }, ...keys.map(k => ({ value: k, label: k }))];
```

BE endpoint returns `DISTINCT project_key` with R10 scoping:

```php
// GET /api/admin/projects/keys
public function keys(): JsonResponse
{
    $keys = KnowledgeDocument::query()
        ->select('project_key')
        ->distinct()
        ->orderBy('project_key')
        ->pluck('project_key');
    return response()->json($keys);
}
```

### Time-window accepts what the cache key encodes (PR #22 topProjects)

```php
// ❌
public function topProjects(): Collection
{
    $days = 7;  // fixed
    return Cache::remember("admin.top-projects.{$project}.{$days}",
        600, fn () => $this->query($days));
}

// ✅
public function topProjects(?string $project, int $days): Collection
{
    $days = max(1, min($days, 365));
    $pk = $project ?? 'all';
    return Cache::remember("admin.top-projects.{$pk}.{$days}",
        600, fn () => $this->query($project, $days));
}
```

### File-extension stripping handles every accepted extension (PR #27)

```php
// ❌
$filename = basename($sourcePath, '.md') . '.pdf';
// foo.markdown → foo.markdown.pdf   ← wrong

// ✅
// pathinfo(..., PATHINFO_FILENAME) strips ANY extension.
$filename = pathinfo($sourcePath, PATHINFO_FILENAME) . '.pdf';
// Or, strict allow-list:
$base = preg_replace('/\.(md|markdown)$/i', '', basename($sourcePath));
$filename = $base . '.pdf';
```

### Seeder grants intent-based access, not cross-product (PR #18 RbacSeeder)

```php
// ❌
foreach (User::all() as $u) {
    foreach (KnowledgeDocument::query()->distinct()->pluck('project_key') as $pk) {
        ProjectMembership::create(['user_id' => $u->id, 'project_key' => $pk]);
    }
}

// ✅ — one project per demo user, matching the demo intent
$fixtures = [
    ['admin@acme.io'  => ['hr-portal', 'engineering']],
    ['editor@acme.io' => ['hr-portal']],
    ['viewer@acme.io' => ['engineering']],
];
foreach ($fixtures as $row) {
    [$email, $keys] = [array_key_first($row), $row[array_key_first($row)]];
    $user = User::where('email', $email)->firstOrFail();
    foreach ($keys as $pk) {
        ProjectMembership::firstOrCreate(['user_id' => $user->id, 'project_key' => $pk]);
    }
}
```

## Related rules

- R9 — docs-match-code: if the doc says "filter accepts every
  project_key", the FE component must actually drive a dynamic list.
- R10 — canonical-awareness: DB domain queries for a canonical filter
  must use `canonical()` / `accepted()` scopes where appropriate.
- R3 — any `DISTINCT` pluck on a large table runs fine, but if the
  distinct query is called on every render, cache it.

## Enforcement

- Linter can't catch "looks like a subset". The
  `copilot-review-anticipator` sub-agent grep patterns cover
  hard-coded project keys + `basename('.md')`.
- At feature-test time: when a seeder creates 5 project_keys but the
  UI only renders 2, the E2E spec that iterates "every project" will
  visibly miss 3. R12 + R13 catch the integration drift.

## Counter-example

```tsx
// ❌ UI lists 2 of 5 tenant keys; admins can't scope to the other 3.
const PROJECT_OPTIONS = [
  { value: 'hr-portal', label: 'HR Portal' },
  { value: 'engineering', label: 'Engineering' },
];

// ❌ exportPdf produces foo.markdown.pdf
$filename = basename($path, '.md') . '.pdf';

// ❌ backfillUser gives everyone everything
foreach (User::all() as $u) {
    foreach ($allProjects as $pk) {
        ProjectMembership::create(['user_id' => $u->id, 'project_key' => $pk]);
    }
}
```
