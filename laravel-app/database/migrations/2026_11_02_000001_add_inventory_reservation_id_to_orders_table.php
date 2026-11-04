<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each order remembers the inventory reservation that holds its stock, so a
 * cancellation can release exactly that reservation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->uuid('inventory_reservation_id')->nullable()->unique()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['inventory_reservation_id']);
            $table->dropColumn('inventory_reservation_id');
        });
    }
};
