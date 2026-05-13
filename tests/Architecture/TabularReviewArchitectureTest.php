<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Models\Concerns\BelongsToTenant;
use App\Models\TabularCell;
use App\Models\TabularReview;
use Tests\TestCase;

/**
 * v4.7/W1 — Architecture invariants for tabular review models.
 *
 * R30/R31: both models must wire BelongsToTenant and list `tenant_id`
 * in $fillable. The enumeration in TenantIdMandatoryTest is the
 * source of truth — these targeted asserts catch regressions BEFORE
 * the broader enumeration runs.
 */
final class TabularReviewArchitectureTest extends TestCase
{
    public function test_tabular_review_uses_belongs_to_tenant(): void
    {
        $this->assertContains(
            BelongsToTenant::class,
            class_uses_recursive(TabularReview::class),
            'TabularReview must `use BelongsToTenant;` (R31).',
        );
    }

    public function test_tabular_cell_uses_belongs_to_tenant(): void
    {
        $this->assertContains(
            BelongsToTenant::class,
            class_uses_recursive(TabularCell::class),
            'TabularCell must `use BelongsToTenant;` (R31).',
        );
    }

    public function test_tabular_review_has_tenant_id_fillable(): void
    {
        $this->assertContains('tenant_id', (new TabularReview)->getFillable());
    }

    public function test_tabular_cell_has_tenant_id_fillable(): void
    {
        $this->assertContains('tenant_id', (new TabularCell)->getFillable());
    }
}
