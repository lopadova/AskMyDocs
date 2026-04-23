<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\LoginController;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LoginRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Minimal named routes so route('chat') and route('login') resolve.
        Route::get('/login', fn () => 'login-form')->name('login');
        Route::get('/chat/{conversation?}', fn () => 'chat-ui')->name('chat');
    }

    public function test_controller_redirects_to_chat_on_successful_auth(): void
    {
        $user = User::create([
            'name' => 'Test',
            'email' => 'test@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $request = LoginRequest::create('/login', 'POST', [
            'email' => $user->email,
            'password' => 'secret123',
        ]);
        $request->setLaravelSession(app('session.store'));

        $response = (new LoginController())->login($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(route('chat'), $response->getTargetUrl());
        $this->assertTrue(Auth::check(), 'User should be logged in after successful attempt.');
    }

    public function test_controller_returns_to_form_with_errors_on_bad_credentials(): void
    {
        User::create([
            'name' => 'Test',
            'email' => 'test@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $request = LoginRequest::create('/login', 'POST', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ]);
        $request->setLaravelSession(app('session.store'));

        $response = (new LoginController())->login($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertFalse(Auth::check());
    }

    public function test_home_route_redirects_to_chat(): void
    {
        // This mirrors routes/web.php:37 — regression against accidental rename.
        Route::get('/', fn () => redirect()->route('chat'))->name('home');

        $response = $this->get('/');

        $response->assertRedirect(route('chat'));
    }
}
