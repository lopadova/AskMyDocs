<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Ocr\Drivers;

use App\Services\Kb\Ocr\Drivers\VisionLlmOcrDriver;
use Tests\TestCase;

/**
 * ADR 0029 §5 — the run key is content-addressed over the ENGINE that
 * produced it: the fingerprint must name the provider and model
 * recognise() actually uses, including the defaults it falls back to, so a
 * changed default is never reused as if it were the same engine.
 */
final class VisionLlmOcrDriverFingerprintTest extends TestCase
{
    public function test_fingerprint_names_the_effective_provider_and_its_default_model_when_unconfigured(): void
    {
        config(['ai.default' => 'openai', 'kb.ocr.vision_llm.provider' => null, 'kb.ocr.vision_llm.model' => null, 'ai.providers.openai.models.text.default' => 'gpt-4o', 'ai.providers.openai.key' => 'k']);
        $before = app(VisionLlmOcrDriver::class)->fingerprint();
        $this->assertStringContainsString('provider=openai;model=gpt-4o;', $before);

        config(['ai.providers.openai.models.text.default' => 'gpt-4.1']);
        $this->assertStringContainsString('provider=openai;model=gpt-4.1;', app(VisionLlmOcrDriver::class)->fingerprint());
        $this->assertNotSame($before, app(VisionLlmOcrDriver::class)->fingerprint(), 'a changed default model is a new engine');

        config(['kb.ocr.vision_llm.provider' => 'openai', 'kb.ocr.vision_llm.model' => 'gpt-4o-mini']);
        $this->assertStringContainsString('provider=openai;model=gpt-4o-mini;', app(VisionLlmOcrDriver::class)->fingerprint());
    }

    /**
     * The driver extracts no figures, so its Markdown must cite none: an
     * image link the model emits anyway — the old `![Figure](figure)`
     * placeholder, or an external URL of the model's choosing — becomes the
     * italic description the prompt asks for (SEC-LLM-001 gate 6).
     */
    public function test_image_links_in_the_model_output_become_text_placeholders(): void
    {
        $this->assertSame('Intro *[Figure]* tail', VisionLlmOcrDriver::stripImageLinks('Intro ![Figure](figure) tail'));
        $this->assertSame('*[Figure]*', VisionLlmOcrDriver::stripImageLinks('![](figure)'));
        $this->assertSame('*[Figure: bar chart of revenue]*', VisionLlmOcrDriver::stripImageLinks('![bar chart of revenue](https://evil.example/track.png)'));
        $this->assertSame('*[Figure 2: a diagram]*', VisionLlmOcrDriver::stripImageLinks('![Figure 2: a diagram](images/fig-1-1.png)'));
        $this->assertSame('plain text with [a link](https://example.test) kept', VisionLlmOcrDriver::stripImageLinks('plain text with [a link](https://example.test) kept'));
    }
}
