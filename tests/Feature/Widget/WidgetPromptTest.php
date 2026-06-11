<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use Tests\TestCase;

/**
 * R40 nit#2 — il system prompt KITT (resources/views/prompts/widget_kitt) si
 * adatta alla disponibilità dei tool: con un provider non tool-capable (o
 * nessun tool abilitato) NON deve istruire l'LLM a emettere tool_call, così il
 * widget degrada in modo pulito a solo-risposta (R43 OFF-path).
 */
final class WidgetPromptTest extends TestCase
{
    /** @return array<string, mixed> */
    private function vars(bool $hasTools): array
    {
        return [
            'hasKb' => false,
            'hasTools' => $hasTools,
            'chunks' => collect(),
            'expanded' => collect(),
            'rejected' => collect(),
            'snapshotJson' => '{}',
            'hasHostTools' => false,
            'hostTools' => [],
        ];
    }

    public function test_prompt_includes_agentic_instructions_when_tools_available(): void
    {
        $prompt = view('prompts.widget_kitt', $this->vars(hasTools: true))->render();

        $this->assertStringContainsString('tool_call', $prompt);
        $this->assertStringContainsString('AZIONI SULLA PAGINA', $prompt);
    }

    public function test_prompt_omits_agentic_instructions_when_no_tools(): void
    {
        $prompt = view('prompts.widget_kitt', $this->vars(hasTools: false))->render();

        // niente istruzioni a emettere tool_call né sezione azioni DOM…
        $this->assertStringNotContainsString('emetti UNA sola tool_call', $prompt);
        $this->assertStringNotContainsString('AZIONI SULLA PAGINA', $prompt);
        // …ma la guida a rispondere solo a parole è presente.
        $this->assertStringContainsString('NON sono disponibili azioni sulla pagina', $prompt);
    }
}
