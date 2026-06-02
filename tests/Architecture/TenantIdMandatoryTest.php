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
     * Two source authorities (the older v3 backfill + every newer
     * cycle's own create-table migration):
     *  - v3 backfill: `database/migrations/2026_04_28_000001_add_tenant_id_to_v3_tables.php`
     *    enumerates the v3 domain tables that received `tenant_id` retroactively.
     *  - v4.x..v8.x cycles: each create-table migration adds `tenant_id`
     *    inline, so newer tenant-aware tables (workflows, mcp_servers,
     *    mcp_tool_call_audit, notification_events,
     *    notification_preferences, notification_digests, etc.) are NOT
     *    in the v3 backfill list and are added directly to this
     *    enumeration when shipped.
     *
     * When adding a tenant-aware model to this list: also wire
     * `use BelongsToTenant;` + `'tenant_id'` in `$fillable` on the model,
     * and ensure the migration starts every composite unique with
     * `tenant_id` (R30).
     */
    private const TENANT_AWARE_MODELS = [
        \App\Models\KnowledgeDocument::class,
        \App\Models\KnowledgeChunk::class,
        // EmbeddingCache is intentionally NOT tenant-aware — the cache is
        // a cross-tenant reuse layer. Schema enforces UNIQUE on text_hash
        // alone (see 2026_01_01_000006_create_embedding_cache_table.php);
        // provider + model are informational filters used by
        // EmbeddingCacheService at retrieval time. EmbeddingCacheService
        // queries are NOT scoped by tenant_id on purpose. PR #98 / PR #99
        // Copilot review.
        \App\Models\ChatLog::class,
        \App\Models\Conversation::class,
        \App\Models\Message::class,
        \App\Models\KbNode::class,
        \App\Models\KbEdge::class,
        \App\Models\KbCanonicalAudit::class,
        \App\Models\ProjectMembership::class,
        \App\Models\KbTag::class,
        // v8.7/W1 — per-(tenant, project) synonym groups for query expansion.
        \App\Models\KbSynonym::class,
        \App\Models\KnowledgeDocumentAcl::class,
        \App\Models\AdminCommandAudit::class,
        \App\Models\AdminCommandNonce::class,
        \App\Models\AdminInsightsSnapshot::class,
        \App\Models\ChatFilterPreset::class,
        \App\Models\ChatLogProvenance::class,
        // v4.7/W1 — tabular review backend tables.
        \App\Models\TabularReview::class,
        \App\Models\TabularCell::class,
        // v4.7/W2 — workflows backend. `workflow_shares` is an
        // association table whose FK to `workflows` carries the tenant
        // boundary transitively; it does NOT need its own tenant_id
        // column and is intentionally not listed here.
        \App\Models\Workflow::class,
        \App\Models\HiddenWorkflow::class,
        // v4.6 — connector framework models now ship in
        // `padosoft/askmydocs-connector-base` (v1.1.1) — the package
        // owns the `BelongsToTenant` trait on
        // `ConnectorInstallation` + `ConnectorCredential` and exercises
        // R31 in its own CI. No host-side entries here so this gate
        // doesn't drift between package and host.
        // v5.0/W1 — MCP server registry + per-call audit (both
        // tenant-aware; added retroactively 2026-05-18 when the v8.0
        // R31 audit caught the omission).
        \App\Models\McpServer::class,
        \App\Models\McpToolCallAudit::class,
        \App\Models\McpTenantToken::class,
        // v8.0/W1.1 — notification system foundation (ADR 0012).
        \App\Models\NotificationEvent::class,
        \App\Models\NotificationPreference::class,
        \App\Models\NotificationDigest::class,
        // v8.0/W2.3 — per-tenant baseline preferences for new users.
        \App\Models\NotificationTenantDefault::class,
        // v8.0/W4 — decision-debt health snapshot + tier-2 scheduler overrides.
        \App\Models\KbCanonicalHealthSnapshot::class,
        \App\Models\TenantSchedulerOverride::class,
        // v8.0/W5.1 — Living Collections foundation schema.
        \App\Models\KbCollection::class,
        \App\Models\KbCollectionMember::class,
        // v8.0/W8.1 — compliance differential pack foundation schema.
        \App\Models\ComplianceReport::class,
        // v8.0/W3.4 — per-chunk thumbs-down feedback for retrieval
        // refinement. Added during the v8.0.1 deep-review pass (F4 —
        // governance gap caught after GA). Trait + fillable both
        // wired since the migration shipped; this entry locks the
        // gate so a future refactor cannot silently drop them.
        \App\Models\KbChunkFeedback::class,
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
