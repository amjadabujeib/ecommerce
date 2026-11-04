<?php

namespace Tests\Feature\Users;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_gets_401_from_the_admin_dashboard(): void
    {
        $this->getJson(route('admin.dashboard'))->assertUnauthorized();
    }

    public function test_customer_is_forbidden_from_the_admin_dashboard(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)
            ->getJson(route('admin.dashboard'))
            ->assertForbidden();
    }

    public function test_customer_is_forbidden_from_admin_product_management(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)
            ->getJson(route('admin.products.index'))
            ->assertForbidden();

        $this->actingAs($customer)
            ->getJson(route('admin.orders.index'))
            ->assertForbidden();
    }

    public function test_admin_can_access_the_dashboard_and_statistics(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard'))
            ->assertOk()
            ->assertJsonPath('data.total_products', 0)
            ->assertJsonPath('data.total_orders', 0)
            ->assertJsonPath('data.total_sales', '0.00')
            ->assertJsonPath('data.stock_units', 0);
    }

    public function test_statistics_only_sum_completed_sales_and_all_stock(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();

        Product::factory()->withStock(5)->create();
        Product::factory()->withStock(10)->create();
        Product::factory()->withStock(0)->create();

        Order::factory()->for($customer)->completed()->create(['total_amount' => '100.50']);
        Order::factory()->for($customer)->completed()->create(['total_amount' => '50.00']);
        Order::factory()->for($customer)->create([
            'total_amount' => '999.99',
            'status' => OrderStatus::Pending,
        ]);
        Order::factory()->for($customer)->cancelled()->create(['total_amount' => '25.00']);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard'))
            ->assertOk()
            ->assertJsonPath('data.total_products', 3)
            ->assertJsonPath('data.total_orders', 4)
            ->assertJsonPath('data.total_sales', '150.50')
            ->assertJsonPath('data.stock_units', 15);
    }

    public function test_customer_cannot_view_another_customers_order(): void
    {
        $owner = User::factory()->create();
        $otherCustomer = User::factory()->create();
        $order = Order::factory()->for($owner)->create();

        $this->actingAs($otherCustomer)
            ->getJson(route('orders.show', $order))
            ->assertForbidden();
    }

    public function test_admin_can_view_a_customers_order(): void
    {
        $admin = User::factory()->admin()->create();
        $order = Order::factory()->create();

        $this->actingAs($admin)
            ->getJson(route('orders.show', $order))
            ->assertOk()
            ->assertJsonPath('data.id', $order->id);
    }
}
