<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regression guard for the dual-login bug.
 *
 * The guest auth screens (login / forgot-password / reset-password) USED to
 * be standalone Blade views with no @vite bundle, while React's own
 * root-level auth routes were only reachable once the SPA shell (served only
 * under /app/*) was already mounted. Result: a soft (in-SPA) navigation
 * showed the branded React login, but a HARD page load of /login — e.g. a
 * cache-cleared reload — served the un-branded Blade page. The same URL
 * rendered two different UIs depending on how you got there.
 *
 * These tests lock in the fix: every guest auth GET route now renders the
 * React SPA shell (SpaController -> view('app')) so the dark React login is
 * canonical on hard loads too. They inherit the base TestCase defineRoutes
 * hook, which loads the real routes/web.php, so they assert the PRODUCTION
 * wiring rather than a mirror.
 */
class GuestAuthRoutesServeSpaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Make @vite a no-op so view('app') renders without a manifest read
        // (mirrors tests/Feature/Spa/SpaControllerTest).
        $this->withoutVite();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function guestAuthGetPaths(): array
    {
        return [
            'login' => ['/login'],
            'forgot-password' => ['/forgot-password'],
            // Query shape — token + email are search params, matching the SPA
            // resetRoute schema.
            'reset-password' => ['/reset-password?token=tok123&email=user%40example.com'],
        ];
    }

    #[DataProvider('guestAuthGetPaths')]
    public function test_guest_auth_get_route_renders_the_spa_shell(string $path): void
    {
        $response = $this->get($path);

        $response->assertStatus(200);
        // The SPA shell hosts <div id="root">; the retired Blade pages never did.
        $response->assertSee('id="root"', false);
        // Negative guard: the old light Blade login must not come back.
        $response->assertDontSee('Accedi al Knowledge Base');
    }

    public function test_login_route_is_handled_by_the_spa_controller(): void
    {
        $route = Route::getRoutes()->getByName('login');

        $this->assertNotNull($route, "Named route 'login' must exist (Authenticate redirects unauthenticated users to it).");
        $this->assertStringContainsString('SpaController', $route->getActionName());
        $this->assertStringNotContainsString('LoginController', $route->getActionName());
    }

    public function test_password_reset_route_is_query_shaped_and_served_by_the_spa(): void
    {
        $route = Route::getRoutes()->getByName('password.reset');

        $this->assertNotNull($route);
        // No more /reset-password/{token} path segment — token rides the query.
        $this->assertSame('reset-password', $route->uri());
        $this->assertStringContainsString('SpaController', $route->getActionName());
    }

    public function test_reset_password_email_link_uses_the_spa_query_contract(): void
    {
        // The framework's ResetPassword notification builds its URL from this
        // named route. With the route un-parameterised, token + email become
        // query args — exactly what the SPA resetRoute reads via useSearch.
        $url = route('password.reset', ['token' => 'TESTTOKEN', 'email' => 'user@example.com'], false);

        $this->assertSame('/reset-password?token=TESTTOKEN&email=user%40example.com', $url);
    }
}
