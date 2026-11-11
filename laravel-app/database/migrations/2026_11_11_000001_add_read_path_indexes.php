<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['created_at','id'], 'orders_created_id_index');
            $table->index(['user_id','created_at','id'], 'orders_user_created_id_index');
        });
        Schema::table('order_items', function (Blueprint $table): void {
            $table->index('order_id', 'order_items_order_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', fn (Blueprint $table) => $table->dropIndex('order_items_order_id_index'));
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_created_id_index'); $table->dropIndex('orders_user_created_id_index');
        });
    }
};
