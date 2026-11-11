<?php

namespace Database\Seeders;

use App\Modules\Products\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('bootstrap_state')->where('name', 'catalog')->whereNotNull('completed_at')->exists()) {
            return;
        }

        Category::query()->firstOrCreate(
            ['name' => 'Electronics'],
            ['slug' => 'electronics'],
        );
    }
}
