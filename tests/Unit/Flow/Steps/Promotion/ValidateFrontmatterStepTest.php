<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Steps\Promotion;

use App\Flow\Steps\Promotion\ValidateFrontmatterStep;
use Padosoft\LaravelFlow\FlowContext;
use RuntimeException;
use Tests\TestCase;

final class ValidateFrontmatterStepTest extends TestCase
{
    public function test_returns_parsed_payload_for_valid_markdown(): void
    {
        $step = $this->app->make(ValidateFrontmatterStep::class);
        $result = $step->execute($this->context([
            'markdown' => $this->validDecision('dec-x'),
        ]));

        $this->assertTrue($result->success);
        $this->assertSame('dec-x', $result->output['parsed']['slug']);
        $this->assertSame('decision', $result->output['parsed']['type']);
    }

    public function test_throws_on_empty_markdown(): void
    {
        $step = $this->app->make(ValidateFrontmatterStep::class);

        $this->expectException(RuntimeException::class);
        $step->execute($this->context(['markdown' => '']));
    }

    public function test_throws_on_missing_frontmatter(): void
    {
        $step = $this->app->make(ValidateFrontmatterStep::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no YAML frontmatter/i');
        $step->execute($this->context(['markdown' => "# Body only"]));
    }

    public function test_throws_on_invalid_frontmatter(): void
    {
        $step = $this->app->make(ValidateFrontmatterStep::class);
        $invalid = "---\ntype: decision\nstatus: accepted\n---\n\n# Missing slug";

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid canonical frontmatter/');
        $step->execute($this->context(['markdown' => $invalid]));
    }

    public function test_dry_run_runs_validation(): void
    {
        $step = $this->app->make(ValidateFrontmatterStep::class);
        $result = $step->execute($this->context([
            'markdown' => $this->validDecision('dec-x'),
        ], dryRun: true));

        $this->assertTrue($result->success);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function context(array $input, bool $dryRun = false): FlowContext
    {
        return new FlowContext(
            flowRunId: 'val-test',
            definitionName: 'kb.promote',
            input: array_merge(['tenant_id' => 'default'], $input),
            stepOutputs: [],
            dryRun: $dryRun,
        );
    }

    private function validDecision(string $slug): string
    {
        return <<<MD
---
id: DEC-0001
slug: {$slug}
type: decision
status: accepted
---

# Body
MD;
    }
}
