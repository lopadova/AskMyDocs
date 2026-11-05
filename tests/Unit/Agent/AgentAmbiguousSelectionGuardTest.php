<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Agent\Planning\AgentAmbiguousSelectionGuard;
use Tests\TestCase;

final class AgentAmbiguousSelectionGuardTest extends TestCase
{
    public function test_it_blocks_an_id_taken_from_one_of_multiple_rows_without_a_selection(): void
    {
        $guard = app(AgentAmbiguousSelectionGuard::class);
        $evidence = [[
            'tool' => 'search-customers',
            'result' => ['data' => ['items' => [
                ['id' => 147768, 'display_name' => 'Riccardo Lorini'],
                ['id' => 147767, 'display_name' => 'Riccardo Lorini'],
            ]]],
        ]];

        $this->assertTrue($guard->blocks($evidence, ['customer_id' => 147768], null));
        $this->assertFalse($guard->blocks($evidence, ['page' => 1], null));
    }

    public function test_it_allows_the_id_when_the_user_selected_that_record(): void
    {
        $guard = app(AgentAmbiguousSelectionGuard::class);
        $evidence = [[
            'tool' => 'search-customers',
            'result' => ['items' => [
                ['id' => 147768, 'display_name' => 'Riccardo Lorini'],
                ['id' => 147767, 'display_name' => 'Riccardo Lorini'],
            ]],
        ]];

        $this->assertFalse($guard->blocks(
            $evidence,
            ['customer_id' => 147768],
            ['record' => ['id' => 147768, 'display_name' => 'Riccardo Lorini']],
        ));
    }
}
