<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AdminUserSeeder::class);
        DB::transaction(function (): void {
            $state = DB::table('bootstrap_state')->where('name', 'catalog')->lockForUpdate()->first();
            if ($state->completed_at !== null) {
                return;
            }
            $this->call([CategorySeeder::class, ProductSeeder::class]);
            DB::table('bootstrap_state')->where('name', 'catalog')->update(['completed_at' => now()]);
        });
    }
}
