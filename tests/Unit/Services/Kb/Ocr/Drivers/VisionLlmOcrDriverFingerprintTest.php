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
}
