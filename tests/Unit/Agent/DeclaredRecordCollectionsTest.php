<?php

namespace Tests\Unit\Agent;

use App\Agent\Evidence\DeclaredRecordCollections;
use PHPUnit\Framework\TestCase;

final class DeclaredRecordCollectionsTest extends TestCase
{
    private function properties(): array
    {
        return ['groups' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
            'lines' => ['type' => 'array', 'x-field' => 'items', 'items' => ['type' => 'object', 'properties' => [
                'sku' => ['type' => 'string', 'x-field' => 'reference'],
                'size' => ['type' => 'integer', 'x-field' => 'variant'],
                'qty' => ['type' => 'integer', 'x-field' => 'quantity'],
                'margin' => ['type' => 'number'],
            ]]],
        ]]]];
    }

    public function test_only_explicit_collections_and_scalar_business_roles_are_projected(): void
    {
        $properties = $this->properties();
        $properties['addresses'] = ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['street' => ['type' => 'string']]]];
        $declarations = DeclaredRecordCollections::describe($properties);
        $this->assertSame([['role' => 'items', 'path' => 'groups.*.lines.*',
            'fields' => ['reference' => 'sku', 'variant' => 'size', 'quantity' => 'qty']]], $declarations);
        $record = ['groups' => [['lines' => [['sku' => 'SKU-A', 'size' => 40, 'qty' => 1, 'margin' => 97]]],
            ['lines' => [['sku' => 'SKU-B', 'size' => 41, 'qty' => 2, 'margin' => 98]]]], 'addresses' => [['street' => 'PRIVATE']]];
        $projected = DeclaredRecordCollections::project($record, $declarations);
        $this->assertSame([['sku' => 'SKU-A', 'size' => 40, 'qty' => 1], ['sku' => 'SKU-B', 'size' => 41, 'qty' => 2]], $projected[0]['rows']);
        $this->assertSame(2, $projected[0]['total_rows']);
        $this->assertStringNotContainsString('margin', json_encode($projected));
        $this->assertStringNotContainsString('PRIVATE', json_encode($projected));
        $this->assertSame(97, $record['groups'][0]['lines'][0]['margin']);
    }

    public function test_bounded_display_retains_the_original_count_and_does_not_guess_duplicate_roles(): void
    {
        $schema = $this->properties();
        $schema['groups']['items']['properties']['lines']['items']['properties']['other_code'] = ['type' => 'string', 'x-field' => 'reference'];
        $declarations = DeclaredRecordCollections::describe($schema);
        $this->assertArrayNotHasKey('reference', $declarations[0]['fields']);
        $record = ['groups' => [['lines' => array_fill(0, 25, ['sku' => 'SKU-A', 'size' => 40, 'qty' => 1])]]];
        $projection = DeclaredRecordCollections::project($record, $declarations);
        $this->assertCount(20, $projection[0]['rows']);
        $this->assertSame(25, $projection[0]['total_rows']);
    }
}
