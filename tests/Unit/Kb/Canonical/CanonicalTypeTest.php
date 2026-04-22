<?php

namespace Tests\Unit\Kb\Canonical;

use App\Support\Canonical\CanonicalType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalType::class)]
class CanonicalTypeTest extends TestCase
{
    public function test_enum_declares_exactly_nine_types(): void
    {
        $this->assertCount(9, CanonicalType::cases());
    }

    public function test_tryFrom_resolves_each_known_value(): void
    {
        foreach (['project-index', 'module-kb', 'decision', 'runbook', 'standard', 'incident', 'integration', 'domain-concept', 'rejected-approach'] as $v) {
            $this->assertInstanceOf(CanonicalType::class, CanonicalType::tryFrom($v), "value must resolve: $v");
        }
    }

    public function test_tryFrom_returns_null_for_unknown_value(): void
    {
        $this->assertNull(CanonicalType::tryFrom('unknown-type'));
        $this->assertNull(CanonicalType::tryFrom(''));
        $this->assertNull(CanonicalType::tryFrom('DECISION'));
    }

    public function test_pathPrefix_maps_each_type_to_canonical_folder(): void
    {
        $expected = [
            'project-index' => '.',
            'module-kb' => 'modules',
            'decision' => 'decisions',
            'runbook' => 'runbooks',
            'standard' => 'standards',
            'incident' => 'incidents',
            'integration' => 'integrations',
            'domain-concept' => 'domain-concepts',
            'rejected-approach' => 'rejected',
        ];
        foreach ($expected as $value => $folder) {
            $this->assertSame($folder, CanonicalType::from($value)->pathPrefix());
        }
    }

    public function test_nodeType_returns_graph_node_label(): void
    {
        $this->assertSame('decision', CanonicalType::Decision->nodeType());
        $this->assertSame('module', CanonicalType::Module->nodeType());
        $this->assertSame('project', CanonicalType::ProjectIndex->nodeType());
        $this->assertSame('rejected-approach', CanonicalType::RejectedApproach->nodeType());
    }
}
