<?php

namespace Tests\Feature\Api\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sanctum auto-registers GET /sanctum/csrf-cookie from its service provider.
 * The SPA must hit this endpoint once at bootstrap to prime the XSRF-TOKEN
 * cookie before any POST to /api/auth/*.
 */
class CsrfCookieTest extends TestCase
{
    use RefreshDatabase;

    public function test_sanctum_csrf_cookie_endpoint_is_registered_and_returns_204(): void
    {
        $response = $this->get('/sanctum/csrf-cookie');

        $response->assertNoContent(204);
    }
}
