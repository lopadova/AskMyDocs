<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class UserLocaleControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_fallback_for_an_existing_user_without_locale(): void
    {
        config()->set('agent.locales.fallback', 'en');
        $user = $this->user();

        $this->actingAs($user)
            ->getJson('/api/me/locale')
            ->assertOk()
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('supported.0', 'en')
            ->assertJsonPath('supported.1', 'it');
    }

    public function test_it_persists_a_normalized_supported_locale(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->patchJson('/api/me/locale', ['locale' => 'it_it'])
            ->assertOk()
            ->assertJsonPath('locale', 'it-IT');

        $this->assertSame('it-IT', $user->refresh()->locale);
    }

    public function test_it_rejects_unsupported_or_malformed_locales(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->patchJson('/api/me/locale', ['locale' => 'fr-FR'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('locale');

        $this->actingAs($user)
            ->patchJson('/api/me/locale', ['locale' => '../../it'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('locale');
    }

    private function user(): User
    {
        return User::create([
            'name' => 'Localized user',
            'email' => 'locale-'.uniqid().'@example.com',
            'password' => Hash::make('secret-pass-123'),
        ]);
    }
}
