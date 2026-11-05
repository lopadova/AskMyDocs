<?php

namespace Tests\Unit\Actions;

use App\Actions\ActionCanonicalizer;
use PHPUnit\Framework\TestCase;

class ActionCanonicalizerTest extends TestCase
{
    public function test_canonical_json_sorts_object_keys_but_preserves_list_order(): void
    {
        $canonicalizer = new ActionCanonicalizer;

        $this->assertSame(
            '{"a":{"x":"é","z":true},"b":2,"list":[3,1]}',
            $canonicalizer->json(['b' => 2, 'a' => ['z' => true, 'x' => 'é'], 'list' => [3, 1]]),
        );
        $this->assertSame(
            'c6684c950a3327126868f5b1f1b60eaf24311040e378cee43c34344b13aff979',
            $canonicalizer->digest(['b' => 2, 'a' => ['z' => true, 'x' => 'é'], 'list' => [3, 1]]),
        );
    }
}
