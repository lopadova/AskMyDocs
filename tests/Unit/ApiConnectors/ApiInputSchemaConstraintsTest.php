<?php

declare(strict_types=1);

namespace Tests\Unit\ApiConnectors;

use App\ApiConnectors\ApiInputSchemaConstraints;
use PHPUnit\Framework\TestCase;

final class ApiInputSchemaConstraintsTest extends TestCase
{
    public function test_retesting_preserves_constraints_but_uses_current_names_types_and_descriptions(): void
    {
        $inferred = ['type' => 'object', 'properties' => ['orderBy' => ['type' => 'string', 'description' => 'Current description'],
            'limit' => ['type' => 'integer'], 'changed' => ['type' => 'integer']], 'required' => ['limit']];
        $previous = ['properties' => ['orderBy' => ['type' => 'string', 'enum' => ['data', 'num_fattura'], 'pattern' => '^(?:data|num_fattura)$', 'default' => 'data'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
            'changed' => ['type' => 'string', 'enum' => ['old']], 'removed' => ['type' => 'string', 'pattern' => '^old$']]];
        $result = (new ApiInputSchemaConstraints)->preserve($inferred, $previous);
        $this->assertSame(['data', 'num_fattura'], $result['properties']['orderBy']['enum']);
        $this->assertSame('Current description', $result['properties']['orderBy']['description']);
        $this->assertSame('data', $result['properties']['orderBy']['default']);
        $this->assertSame(200, $result['properties']['limit']['maximum']);
        $this->assertSame(['type' => 'integer'], $result['properties']['changed']);
        $this->assertArrayNotHasKey('removed', $result['properties']);
        $this->assertSame(['limit'], $result['required']);
    }

    public function test_constraints_in_a_stored_tool_definition_survive_a_missing_route_schema(): void
    {
        $inferred = ['type' => 'object', 'properties' => ['orderBy' => ['type' => 'string']]];
        $definition = ['properties' => ['orderBy' => ['type' => 'string', 'enum' => ['data']]]];
        $this->assertSame(['data'], (new ApiInputSchemaConstraints)->preserve($inferred, null, $definition)['properties']['orderBy']['enum']);
        $this->assertSame($inferred, (new ApiInputSchemaConstraints)->preserve($inferred, null));
    }
}
