<?php

namespace Database\Seeders;

use App\Modules\Products\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        Category::query()->updateOrCreate(
            ['name' => 'Electronics'],
            ['slug' => 'electronics'],
        );
    }
}
