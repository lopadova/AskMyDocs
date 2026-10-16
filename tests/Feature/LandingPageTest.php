<?php

namespace Tests\Feature;

use Tests\TestCase;

class LandingPageTest extends TestCase
{
    public function test_public_landing_renders_one_blade_source_with_the_react_select_mount(): void
    {
        $this->withoutVite();

        $this->get('/landing?lang=fr')
            ->assertOk()
            ->assertViewIs('landing')
            ->assertSee('id="language-select-root"', false)
            ->assertDontSee('<select', false);
    }

    public function test_legacy_static_landing_redirects_and_preserves_language_links(): void
    {
        $this->get('/landing/index.html?lang=de')
            ->assertStatus(301)
            ->assertRedirect('/landing?lang=de');
    }
}
