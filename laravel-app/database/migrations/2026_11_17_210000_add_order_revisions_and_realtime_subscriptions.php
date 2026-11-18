<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->unsignedBigInteger('revision')->default(1));
        Schema::create('realtime_subscriptions', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('socket_id', 64);
            $table->string('channel', 120);
            $table->unsignedBigInteger('user_id')->index();
            $table->text('credential');
            $table->timestamp('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realtime_subscriptions');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('revision'));
    }
};
