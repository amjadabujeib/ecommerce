<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A product that only appears in finished orders (completed or cancelled) can
 * now be deleted. Those order lines must keep pointing at a real product row,
 * so such a product is soft-deleted: it leaves the catalogue but stays in the
 * table for the order history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
