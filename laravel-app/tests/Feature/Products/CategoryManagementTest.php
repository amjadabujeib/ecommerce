<?php

namespace Tests\Feature\Products;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_category_and_use_it_in_the_product_form(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson(route('admin.categories.store'), ['name' => '  Garden   Tools '])
            ->assertCreated()
            ->assertJsonPath('message', 'Category "Garden Tools" was created.')
            ->assertJsonPath('data.name', 'Garden Tools')
            ->assertJsonPath('data.slug', 'garden-tools')
            ->assertJsonPath('data.products_count', 0);

        $this->actingAs($admin)
            ->getJson(route('admin.products.create'))
            ->assertJsonPath('data.categories.0.name', 'Garden Tools');
    }

    public function test_category_name_is_required_and_unique(): void
    {
        $admin = User::factory()->admin()->create();
        Category::factory()->create(['name' => 'Books']);

        $this->actingAs($admin)
            ->postJson(route('admin.categories.store'), ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->actingAs($admin)
            ->postJson(route('admin.categories.store'), ['name' => 'Books'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'A category with this name already exists.']);
    }

    public function test_names_that_share_a_slug_get_distinct_slugs(): void
    {
        $admin = User::factory()->admin()->create();
        Category::factory()->create(['name' => 'C', 'slug' => 'c']);

        $this->actingAs($admin)
            ->postJson(route('admin.categories.store'), ['name' => 'C++'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'c-2');
    }

    public function test_customers_cannot_manage_categories(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)
            ->postJson(route('admin.categories.store'), ['name' => 'Toys'])
            ->assertForbidden();

        $this->assertDatabaseCount('categories', 0);
    }

    public function test_admin_can_list_and_rename_categories(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create(['name' => 'Phones']);
        Product::factory()->count(2)->for($category)->create();

        $this->actingAs($admin)
            ->getJson(route('admin.categories.index'))
            ->assertOk()
            ->assertJsonPath('data.0.products_count', 2);

        $this->actingAs($admin)
            ->putJson(route('admin.categories.update', $category), ['name' => 'Phones'])
            ->assertOk();

        $this->actingAs($admin)
            ->putJson(route('admin.categories.update', $category), ['name' => 'Mobile Phones'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'mobile-phones');
    }

    public function test_only_an_unused_category_can_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $empty = Category::factory()->create();
        $withProducts = Category::factory()->create(['name' => 'Audio']);
        Product::factory()->for($withProducts)->create();

        $this->actingAs($admin)
            ->deleteJson(route('admin.categories.destroy', $withProducts))
            ->assertConflict()
            ->assertJsonPath('message', 'The category "Audio" still has 1 product(s). Move or delete them first.');

        $this->actingAs($admin)
            ->deleteJson(route('admin.categories.destroy', $empty))
            ->assertOk();

        $this->assertModelMissing($empty);
    }

    public function test_category_of_a_product_kept_for_order_history_cannot_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create();
        OrderItem::factory()->for(Order::factory()->completed())->for($product)->create();

        $this->actingAs($admin)->deleteJson(route('admin.products.destroy', $product))->assertOk();

        $this->actingAs($admin)
            ->deleteJson(route('admin.categories.destroy', $category))
            ->assertConflict();

        $this->assertModelExists($category);
    }
}
