<?php

namespace Tests\Feature;

use Tests\TestCase;

final class ShopPageTest extends TestCase
{
    public function test_shop_is_public_with_same_origin_assets_and_restrictive_csp(): void
    {
        $this->get('/')->assertOk()->assertSee('NovaCart')
            ->assertSee('/assets/shop.js')->assertSee('/assets/shop.css')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    }

    public function test_api_errors_are_json_even_for_a_browser_accept_header(): void
    {
        $this->get('/api/v1/me', ['Accept' => 'text/html'])
            ->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    }
}
