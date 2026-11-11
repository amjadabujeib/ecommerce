<?php

namespace Tests\Feature\Products;

use App\Modules\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CategorySlugLengthTest extends TestCase
{
    use RefreshDatabase;

    public function test_expanding_unicode_slugs_fit_storage_and_keep_collision_suffixes(): void
    {
        $admin = User::factory()->admin()->create();
        $name = str_repeat('ß', 70);
        $first = $this->actingAs($admin)->postJson(route('admin.categories.store'), ['name' => $name])->assertCreated();
        $second = $this->postJson(route('admin.categories.store'), ['name' => $name.'!'])->assertCreated();
        $this->assertLessThanOrEqual(120, strlen($first->json('data.slug')));
        $this->assertLessThanOrEqual(120, strlen($second->json('data.slug')));
        $this->assertNotSame($first->json('data.slug'), $second->json('data.slug'));
        $this->assertStringEndsWith('-2', $second->json('data.slug'));
    }
}
