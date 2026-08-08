<?php

declare(strict_types=1);

namespace Tests\Unit\Widget;

use App\Services\Widget\WidgetIntroService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class WidgetIntroServiceTest extends TestCase
{
    public function test_defaults_are_disabled_and_backward_compatible(): void
    {
        $intro = app(WidgetIntroService::class)->resolve(null);

        $this->assertFalse($intro['enabled']);
        $this->assertSame('card', $intro['variant']);
        $this->assertSame([], $intro['suggestions']);
    }

    public function test_it_sanitizes_text_lists_suggestions_and_urls(): void
    {
        $intro = app(WidgetIntroService::class)->sanitize([
            'enabled' => true,
            'variant' => 'hero',
            'title' => "  Product\0 assistant  ",
            'imageUrl' => 'https://cdn.example.test/hero.webp',
            'imageAlt' => 'Product assistant',
            'bullets' => [' First ', '', 'Second', 'Third', 'Fourth', 'ignored'],
            'suggestions' => [
                ['label' => ' Start ', 'prompt' => ' Explain the product '],
                ['label' => '', 'prompt' => 'ignored'],
            ],
        ]);

        $this->assertTrue($intro['enabled']);
        $this->assertSame('Product assistant', $intro['title']);
        $this->assertSame(['First', 'Second', 'Third'], $intro['bullets']);
        $this->assertSame([['label' => 'Start', 'prompt' => 'Explain the product']], $intro['suggestions']);
        $this->assertSame('https://cdn.example.test/hero.webp', $intro['imageUrl']);
    }

    public function test_enabled_intro_requires_a_title_and_images_require_alt_text(): void
    {
        $service = app(WidgetIntroService::class);

        try {
            $service->assertUsable($service->sanitize(['enabled' => true]));
            $this->fail('Expected a validation exception.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('intro.title', $error->errors());
        }

        try {
            $service->assertUsable($service->sanitize([
                'enabled' => true,
                'title' => 'Hello',
                'imageUrl' => 'https://cdn.example.test/hero.webp',
            ]));
            $this->fail('Expected a validation exception.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('intro.imageAlt', $error->errors());
        }
    }
}
