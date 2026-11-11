<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bootstrap_state', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->timestamp('completed_at')->nullable();
        });
        // Upgrading a managed catalog must not reinsert renamed/deleted defaults.
        $managed = DB::table('users')->exists() || DB::table('categories')->exists() || DB::table('products')->exists();
        DB::table('bootstrap_state')->insert(['name' => 'catalog', 'completed_at' => $managed ? now() : null]);
    }

    public function down(): void
    {
        Schema::dropIfExists('bootstrap_state');
    }
};
