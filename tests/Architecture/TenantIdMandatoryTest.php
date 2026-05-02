<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use Tests\TestCase;

/**
 * R31 — tenant_id is mandatory in every tenant-aware Eloquent model.
 *
 * This architecture test enumerates app/Models/*.php and asserts that
 * every Model that represents a tenant-scoped domain entity has:
 *   1. The `BelongsToTenant` trait wired in
 *   2. `tenant_id` listed in `$fillable` (OR `$guarded = ['id']` only,
 *      which makes everything mass-assignable except the PK)
 *
 * Excluded on purpose:
 *   - User                      (cross-tenant identity)
 *   - The system tables (jobs / failed_jobs / activity_log)
 *
 * Adding a new tenant-aware model? Add it to TENANT_AWARE_MODELS below
 * and wire `use BelongsToTenant;` + `'tenant_id'` in $fillable.
 */
final class TenantIdMandatoryTest extends TestCase
{
    /**
     * Domain models that MUST be tenant-aware.
     *
     * Sync with the table list in
     * `database/migrations/2026_04_28_000001_add_tenant_id_to_v3_tables.php`.
     */
    private const TENANT_AWARE_MODELS = [
        \App\Models\KnowledgeDocument::class,
        \App\Models\KnowledgeChunk::class,
        // EmbeddingCache is intentionally NOT tenant-aware — the cache is
        // a cross-tenant reuse layer keyed by (text_hash, provider, model)
        // and globally unique on text_hash. EmbeddingCacheService queries
        // are NOT scoped by tenant_id on purpose. PR #98 Copilot review.
        \App\Models\ChatLog::class,
        \App\Models\Conversation::class,
        \App\Models\Message::class,
        \App\Models\KbNode::class,
        \App\Models\KbEdge::class,
        \App\Models\KbCanonicalAudit::class,
        \App\Models\ProjectMembership::class,
        \App\Models\KbTag::class,
        \App\Models\KnowledgeDocumentAcl::class,
        \App\Models\AdminCommandAudit::class,
        \App\Models\AdminCommandNonce::class,
        \App\Models\AdminInsightsSnapshot::class,
        \App\Models\ChatFilterPreset::class,
    ];

    public function test_every_tenant_aware_model_uses_belongs_to_tenant_trait(): void
    {
        foreach (self::TENANT_AWARE_MODELS as $modelClass) {
            $reflection = new ReflectionClass($modelClass);
            $traits = $this->collectAllTraits($reflection);

            $this->assertContains(
                BelongsToTenant::class,
                $traits,
                "Model {$modelClass} must `use BelongsToTenant;` (R31)"
            );
        }
    }

    public function test_every_tenant_aware_model_has_tenant_id_fillable_or_guarded(): void
    {
        foreach (self::TENANT_AWARE_MODELS as $modelClass) {
            /** @var Model $instance */
            $instance = new $modelClass;

            $fillable = $instance->getFillable();
            $guarded = $instance->getGuarded();

            // Mass-assignable iff either:
            //   a) tenant_id is in $fillable
            //   b) $guarded == ['id'] or [] (everything but PK is fillable)
            $isFillable = in_array('tenant_id', $fillable, true);
            $isGuardedFully = $guarded === ['id'] || $guarded === [];

            $this->assertTrue(
                $isFillable || $isGuardedFully,
                "Model {$modelClass} must have 'tenant_id' in \$fillable, "
                . "or use \$guarded = ['id'] / [] for full mass-assignment (R31). "
                . "Got fillable=" . json_encode($fillable) . ", guarded=" . json_encode($guarded)
            );
        }
    }

    /**
     * Collect every trait used by a class, including transitively-used
     * traits (a trait that uses another trait). Eloquent models stack
     * traits like `SoftDeletes`, `HasFactory`, `BelongsToTenant`, etc.
     * — class_uses_recursive returns the FQCN keys we need.
     *
     * @return array<int, string>
     */
    private function collectAllTraits(ReflectionClass $reflection): array
    {
        $names = [];
        $current = $reflection;
        while ($current !== false) {
            $names = array_merge($names, $current->getTraitNames());
            $current = $current->getParentClass();
        }

        // Recurse into parent traits (a trait may `use` another trait).
        $expanded = [];
        foreach ($names as $traitName) {
            $expanded[] = $traitName;
            $traitReflection = new ReflectionClass($traitName);
            $expanded = array_merge($expanded, $traitReflection->getTraitNames());
        }

        return array_unique($expanded);
    }
}
