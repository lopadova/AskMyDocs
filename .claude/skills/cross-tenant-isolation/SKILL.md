---
name: cross-tenant-isolation
description: Every Eloquent query against tenant-aware tables MUST be scoped to the active tenant_id. Use `forTenant($tenantId)` scope (provided by BelongsToTenant trait) or explicit `where('tenant_id', $ctx->current())`. The TenantContext singleton holds the active tenant; never bypass it. Trigger when writing services / scopes / controllers that query tenant-aware models, when reviewing PRs that touch persistence, or when investigating R30 architecture test failures.
---

# R30 — Cross-tenant isolation

## Rule

Every Eloquent query against a **tenant-aware table** MUST be scoped to
the active tenant. Pick one of:

1. **`forTenant($tenantId)`** scope (preferred, provided by `BelongsToTenant`)
2. Explicit `where('tenant_id', $ctx->current())`

Tenant-aware tables (sync with `TenantIdMandatoryTest::TENANT_AWARE_MODELS`):
knowledge_documents, knowledge_chunks, embedding_cache, chat_logs,
conversations, messages, kb_nodes, kb_edges, kb_canonical_audit,
project_memberships, kb_tags, knowledge_document_tags,
knowledge_document_acl, admin_command_audit, admin_command_nonces,
admin_insights_snapshots, chat_filter_presets.

## Why

A query like `KnowledgeDocument::where('project_key', 'lvr-store')->get()`
returns rows from tenant_id='surface', tenant_id='lvr', AND
tenant_id='outlet' if all three have a project_key='lvr-store'. With
tenant_id, two different customers can legitimately share the same
project_key. Cross-tenant leakage = customer data exposure = catastrophic
GDPR violation + loss of trust.

## How to apply

### In services / repositories
```php
use App\Support\TenantContext;

final class KbSearchService
{
    public function __construct(private TenantContext $ctx) {}

    public function search(string $projectKey, string $query): Collection
    {
        return KnowledgeDocument::query()
            ->forTenant($this->ctx->current())   // <-- ALWAYS first
            ->where('project_key', $projectKey)
            ->where('title', 'like', "%{$query}%")
            ->get();
    }
}
```

### In controllers
```php
public function index(Request $request, TenantContext $ctx): JsonResponse
{
    $documents = KnowledgeDocument::forTenant($ctx->current())
        ->where('status', 'published')
        ->paginate();

    return response()->json($documents);
}
```

### In tests
```php
public function test_query_scopes_to_tenant(): void
{
    $ctx = $this->app->make(TenantContext::class);
    $ctx->set('lvr-store');

    KnowledgeDocument::factory()->create(['tenant_id' => 'lvr-store']);
    KnowledgeDocument::factory()->create(['tenant_id' => 'outlet']);

    $found = KnowledgeDocument::forTenant('lvr-store')->get();
    $this->assertCount(1, $found);
}
```

## Counter-examples (DO NOT)

```php
// ❌ No tenant scope — returns rows from any tenant
KnowledgeDocument::where('project_key', 'lvr-store')->get();

// ❌ Hard-coded 'default' bypasses real multi-tenancy
KnowledgeDocument::where('tenant_id', 'default')->get();

// ❌ Query Builder bypassing the model scope
DB::table('knowledge_documents')->where('project_key', 'x')->get();

// ❌ Eager-loaded relations without inherited tenant scope
KnowledgeDocument::with('chunks')->get();
// (chunks belong to a document → inherit document's tenant_id, but
//  if you query KnowledgeChunk directly later, you must scope again)
```

## Architecture test

A future R30 architecture test will scan `app/Services/` for
`Model::query()->where(` patterns missing `forTenant(` adjacent. For now,
this is enforced via Copilot review + manual audit.

## Reference

- `app/Support/TenantContext.php` — singleton holder
- `app/Models/Concerns/BelongsToTenant.php::scopeForTenant` — the scope
- `tests/Architecture/TenantIdMandatoryTest.php` — model-side enforcement
- `CLAUDE.md` R30 + R31 (codified rules)
