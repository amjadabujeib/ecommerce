<?php

namespace Tests\Feature\Products;

use App\Modules\Products\Models\Category;
use App\Modules\Products\Services\CategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CategoryAllocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_name_is_rechecked_inside_the_allocation_transaction(): void
    {
        Category::factory()->create(['name' => 'Existing']);
        $this->expectException(ValidationException::class);
        app(CategoryService::class)->create('Existing');
    }

    public function test_rename_rechecks_uniqueness_and_preserves_the_original_on_failure(): void
    {
        Category::factory()->create(['name' => 'Existing']);
        $second = Category::factory()->create(['name' => 'Other']);
        try {
            app(CategoryService::class)->rename($second, 'Existing');
            $this->fail('Duplicate name must be rejected');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('name', $error->errors());
        }
        $this->assertSame('Other', $second->fresh()->name);
    }
}
