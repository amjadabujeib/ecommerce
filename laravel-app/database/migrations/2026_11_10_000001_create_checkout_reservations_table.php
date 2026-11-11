<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('state')->default('pending');
            $table->timestamps();
            $table->index(['state', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_reservations');
    }
};
