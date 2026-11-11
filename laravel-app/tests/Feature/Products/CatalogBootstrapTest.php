<?php

namespace Tests\Feature\Products;

use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CatalogBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_bootstrap_never_rewrites_or_recreates_managed_defaults(): void
    {
        config(['store.admin.password' => 'BootstrapOnly123!']);
        $this->seed(DatabaseSeeder::class);
        $category = Category::where('slug', 'electronics')->firstOrFail();
        $category->update(['name' => 'Electronics!']);
        $products = Product::orderBy('id')->get();
        $products[0]->update(['title' => 'Renamed monitor', 'price' => '450.00', 'description' => 'Managed edit']);
        $products[1]->forceDelete();
        $this->seed(CategorySeeder::class);
        $this->seed(ProductSeeder::class);
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseCount('products', 7);
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Electronics!']);
        $this->assertDatabaseHas('products', ['id' => $products[0]->id, 'title' => 'Renamed monitor', 'price' => '450.00', 'description' => 'Managed edit']);
    }

    public function test_upgrade_marks_existing_managed_catalog_complete_without_guessing_seed_identity(): void
    {
        config(['store.admin.password' => 'BootstrapOnly123!']);
        Category::factory()->create(['name' => 'Managed category', 'slug' => 'custom']);
        Schema::drop('bootstrap_state');
        $migration = require database_path('migrations/2026_11_11_000004_create_bootstrap_state.php');
        $migration->up();
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_upgrade_preserves_an_existing_shop_with_an_intentionally_empty_catalog(): void
    {
        config(['store.admin.password' => 'BootstrapOnly123!']);
        User::factory()->admin()->create();
        Schema::drop('bootstrap_state');
        $migration = require database_path('migrations/2026_11_11_000004_create_bootstrap_state.php');
        $migration->up();
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('categories', 0);
        $this->assertDatabaseCount('products', 0);
    }
}
