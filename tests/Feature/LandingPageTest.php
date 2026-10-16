<?php

namespace Tests\Feature;

use App\Models\InstallationAppearance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_landing_renders_one_blade_source_with_the_react_select_mount(): void
    {
        $this->withoutVite();

        $this->get('/landing?lang=fr')
            ->assertOk()
            ->assertViewIs('landing')
            ->assertSee('id="language-select-root"', false)
            ->assertSee('id="theme-toggle"', false)
            ->assertSee('data-i18n-aria="theme"', false)
            ->assertSee('aria-pressed="false"', false)
            ->assertDontSee('<select', false);
    }

    public function test_legacy_static_landing_redirects_and_preserves_language_links(): void
    {
        $this->get('/landing/index.html?lang=de')
            ->assertStatus(301)
            ->assertRedirect('/landing?lang=de');
    }

    public static function designThemes(): array
    {
        return [
            ['humanistic', '/editorial'],
            ['tech-pro', '/editorial/themes/tech-pro'],
            ['retro-future', '/editorial/themes/retro-future'],
            ['dynamic-office', '/editorial/themes/dynamic-office'],
            ['luxury', '/editorial/themes/luxury'],
        ];
    }

    #[DataProvider('designThemes')]
    public function test_landing_uses_the_installation_theme_for_its_initial_images(string $theme, string $base): void
    {
        InstallationAppearance::create(['id' => 1, 'theme' => $theme]);
        $this->withoutVite();

        $response = $this->get('/landing');
        $response->assertOk()
            ->assertSee('data-design-theme="'.$theme.'"', false)
            ->assertSee('data-theme="dark"', false)
            ->assertSee('content="'.$base.'/knowledge-night.webp" data-editorial-og="knowledge"', false)
            ->assertSee('imagesrcset="'.$base.'/knowledge-night-small.webp 640w, '.$base.'/knowledge-night.webp 1280w"', false);

        foreach (['knowledge', 'archive', 'community'] as $artwork) {
            $response->assertSee('src="'.$base.'/'.$artwork.'-night.webp"', false)
                ->assertSee('data-editorial-artwork="'.$artwork.'"', false);
        }
    }
}
