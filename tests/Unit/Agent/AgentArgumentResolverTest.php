<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Agent\Planning\AgentArgumentResolver;
use PHPUnit\Framework\TestCase;

final class AgentArgumentResolverTest extends TestCase
{
    public function test_it_resolves_nested_values_from_completed_steps(): void
    {
        $resolved = (new AgentArgumentResolver)->resolve([
            'customer_id' => ['path' => 'items.0.id', '$from' => 'find_customer'],
            'filters' => ['state' => 'open'],
        ], [
            'find_customer' => ['items' => [['id' => 42, 'name' => 'Ada']]],
        ]);

        $this->assertSame(42, $resolved['customer_id']);
        $this->assertSame('open', $resolved['filters']['state']);
    }

    public function test_it_rejects_missing_steps_values_and_unsafe_paths(): void
    {
        $resolver = new AgentArgumentResolver;

        foreach ([
            [['id' => ['$from' => 'missing', 'path' => 'items.0.id']], []],
            [['id' => ['$from' => 'step', 'path' => 'items.9.id']], ['step' => ['items' => []]]],
            [['id' => ['$from' => 'step', 'path' => '../secret']], ['step' => ['secret' => 1]]],
        ] as [$arguments, $results]) {
            try {
                $resolver->resolve($arguments, $results);
                $this->fail('An invalid dependency reference was accepted.');
            } catch (\DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
