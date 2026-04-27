---
description: Use when adding a new converter, chunker, source-type, or any registry-based dispatch component. Enforces FQCN validation at boot + supports() mutex check.
---

# Pluggable Pipeline Registry

When you add a new entry to a registry-style dispatcher (`PipelineRegistry`, `PipelineRegistry::registerConverter()`, `PipelineRegistry::registerChunker()`, future MCP-tool registry, future provider registry), apply two invariants:

## 1. Validate FQCN at boot

Every registered class string MUST be checked at registration time to ensure it implements the expected interface. If not, throw `InvalidArgumentException` with the registered class + expected interface in the message.

```php
// app/Services/Kb/Pipeline/PipelineRegistry.php (T1.4 reference)
public static function bootInstance(array $config): self {
    $registry = new self();
    foreach ($config['converters'] ?? [] as $fqcn) {
        if (! is_subclass_of($fqcn, ConverterInterface::class)) {
            throw new InvalidArgumentException(
                "{$fqcn} does not implement " . ConverterInterface::class
            );
        }
        $registry->converters[] = app($fqcn);
    }
    // ... same for chunkers
    return $registry;
}
```

**Why**: silent registration of a wrong-interface class produces a runtime fatal at the first dispatch, often deep in a queue worker. Boot-time validation surfaces it where the config lives.

## 2. `supports()` mutex check

Two components in the same registry MUST NOT both return `true` from `supports()` for the same input. First-match-wins resolution silently picks the wrong handler when overlap exists (T1.7 caught PdfPageChunker would have shadowed MarkdownChunker for `.md` files because both supported `'pdf'` AND `'markdown'` source types initially).

Test:

```php
public function test_no_two_chunkers_both_support_the_same_source_type(): void {
    $registry = $this->app->make(PipelineRegistry::class);
    $allTypes = ['markdown', 'text', 'pdf', 'docx'];
    foreach ($allTypes as $type) {
        $supporters = collect($registry->chunkers())
            ->filter(fn($c) => $c->supports($type))
            ->all();
        $this->assertCount(1, $supporters,
            "{$type} should be supported by EXACTLY one chunker (got " . count($supporters) . ")");
    }
}
```

**Why**: with ≥2 supporting chunkers, `array_first(filter)` returns the FIRST one in registration order — which can shift silently when `.env` config reorders them. The test pins exactly-one.

## Counter-example (anti-pattern)

```php
// WRONG — overlap silently broken when MarkdownChunker registered first:
class PdfPageChunker implements ChunkerInterface {
    public function supports(string $type): bool {
        return in_array($type, ['pdf', 'markdown']);  // why is markdown here??
    }
}
```

The `'markdown'` clause is leftover from copy-paste. The mutex test catches it; the boot validation does not (it's a valid `ChunkerInterface` impl, just a wrong `supports()` predicate).

## When to invoke this skill

- Adding a new converter / chunker / source-type to the ingestion pipeline
- Adding a new provider to a multi-provider abstraction (AI, embeddings, OCR)
- Building any registry-style dispatcher with `register()` + `find()` semantics

## References

- `app/Services/Kb/Pipeline/PipelineRegistry.php` (the canonical implementation)
- `tests/Feature/Kb/Pipeline/PipelineRegistryTest.php` (the mutex test)
- LESSONS.md T1.1, T1.4, T1.7 (origin notes)
- CLAUDE.md R23
