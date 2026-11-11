<?php

namespace Tests\Feature\Products;

use App\Modules\Products\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ConditionalCorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cross_origin_clients_can_read_and_revalidate_product_etags(): void
    {
        config(['performance.enabled' => true]);
        $product = Product::factory()->withStock(1)->create();
        $origin = 'https://review.example.test';
        $preflight = $this->options(route('products.show', $product), [], [
            'Origin' => $origin, 'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'if-none-match',
        ])->assertStatus(204);
        $this->assertStringContainsString('if-none-match', strtolower($preflight->headers->get('Access-Control-Allow-Headers')));
        $full = $this->getJson(route('products.show', $product), ['Origin' => $origin])->assertOk();
        $this->assertStringContainsString('etag', strtolower($full->headers->get('Access-Control-Expose-Headers')));
        $this->getJson(route('products.show', $product), ['Origin' => $origin, 'If-None-Match' => $full->headers->get('ETag')])
            ->assertStatus(304)->assertContent('');
    }
}
