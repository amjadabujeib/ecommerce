<?php

namespace Tests\Feature\Products;

use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_product_with_an_image(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create([
            'name' => 'Electronics',
            'slug' => 'electronics',
        ]);

        $response = $this->actingAs($admin)
            ->withHeader('Accept', 'application/json')
            ->post(route('admin.products.store'), [
                'title' => 'Aurora 4K Monitor',
                'description' => 'A sharp 27-inch display with USB-C power delivery.',
                'price' => '349.99',
                'stock_quantity' => 12,
                'category_id' => $category->id,
                'image' => $this->fakePng('monitor.png'),
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.title', 'Aurora 4K Monitor')
            ->assertJsonPath('data.price', '349.99')
            ->assertJsonPath('data.stock_quantity', 12)
            ->assertJsonPath('data.category.id', $category->id);

        $product = Product::query()->firstOrFail();

        $this->assertSame('349.99', $product->price);
        $this->assertSame($category->id, $product->category_id);
        $this->assertNotNull($product->image_path);
        $this->assertStringStartsWith('products/', $product->image_path);
        Storage::disk('public')->assertExists($product->image_path);
    }

    public function test_product_creation_requires_a_valid_category_and_fields(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson(route('admin.products.store'), [
                'title' => '',
                'description' => '',
                'price' => '0',
                'stock_quantity' => -4,
                'category_id' => 999,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'title',
                'description',
                'price',
                'stock_quantity',
                'category_id',
            ]);
    }

    public function test_price_may_not_have_more_than_two_decimals(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create();

        $this->actingAs($admin)
            ->postJson(route('admin.products.store'), [
                'title' => 'Imprecise price',
                'description' => 'Test',
                'price' => '10.999',
                'stock_quantity' => 1,
                'category_id' => $category->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('price');
    }

    public function test_image_must_be_an_allowed_mime_type(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create();

        $this->actingAs($admin)
            ->withHeader('Accept', 'application/json')
            ->post(route('admin.products.store'), [
                'title' => 'Invalid image product',
                'description' => 'Test',
                'price' => '19.99',
                'stock_quantity' => 1,
                'category_id' => $category->id,
                'image' => UploadedFile::fake()->create('manual.pdf', 100, 'application/pdf'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');

        $this->assertDatabaseCount('products', 0);
    }

    public function test_image_may_not_exceed_five_megabytes(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create();

        $this->actingAs($admin)
            ->withHeader('Accept', 'application/json')
            ->post(route('admin.products.store'), [
                'title' => 'Huge image product',
                'description' => 'Test',
                'price' => '19.99',
                'stock_quantity' => 1,
                'category_id' => $category->id,
                'image' => $this->fakePng('huge.png', 6 * 1024 * 1024),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');

        $this->assertDatabaseCount('products', 0);
    }

    public function test_non_admin_cannot_create_products(): void
    {
        $customer = User::factory()->create();
        $category = Category::factory()->create();

        $this->actingAs($customer)
            ->postJson(route('admin.products.store'), [
                'title' => 'Unauthorized product',
                'description' => 'Test',
                'price' => '10.00',
                'stock_quantity' => 1,
                'category_id' => $category->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('products', 0);
    }

    public function test_admin_can_open_the_create_form_category_dropdown(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create(['name' => 'Electronics']);

        $this->actingAs($admin)
            ->getJson(route('admin.products.create'))
            ->assertOk()
            ->assertJsonPath('data.categories.0.id', $category->id)
            ->assertJsonPath('data.categories.0.name', 'Electronics');
    }

    public function test_replacing_an_image_deletes_the_old_file(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create();

        $oldPath = 'products/old-image.png';
        Storage::disk('public')->put($oldPath, 'old');

        $product = Product::factory()->create([
            'category_id' => $category->id,
            'image_path' => $oldPath,
        ]);

        $this->actingAs($admin)
            ->withHeader('Accept', 'application/json')
            ->put(route('admin.products.update', $product), [
                'title' => $product->title,
                'description' => $product->description,
                'price' => $product->price,
                'stock_quantity' => $this->inventory->stockOf($product),
                'category_id' => $category->id,
                'image' => $this->fakePng('new-image.png'),
            ])
            ->assertOk();

        $product->refresh();

        $this->assertNotSame($oldPath, $product->image_path);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($product->image_path);
    }

    public function test_deleting_a_product_removes_its_image(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create();

        $path = 'products/to-delete.png';
        Storage::disk('public')->put($path, 'image');

        $product = Product::factory()->create([
            'category_id' => $category->id,
            'image_path' => $path,
        ]);

        $this->actingAs($admin)
            ->deleteJson(route('admin.products.destroy', $product))
            ->assertOk();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_stock_cannot_be_negative_and_can_be_set_to_zero(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->withStock(5)->create();

        $this->actingAs($admin)
            ->patchJson(route('admin.products.stock', $product), ['stock_quantity' => -1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stock_quantity');

        $this->assertSame(5, $this->inventory->stockOf($product));

        $this->actingAs($admin)
            ->patchJson(route('admin.products.stock', $product), ['stock_quantity' => 0])
            ->assertOk()
            ->assertJsonPath('data.stock_quantity', 0);

        $this->assertSame(0, $this->inventory->stockOf($product));
    }
}
