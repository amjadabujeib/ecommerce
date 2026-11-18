<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_outbox', function (Blueprint $table): void {
            $table->uuid('event_id')->primary();
            $table->string('type', 80);
            $table->json('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('leased_until')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('last_error', 180)->nullable();
            $table->timestamps();
            $table->index(['published_at', 'next_attempt_at']);
        });
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id');
            $table->string('handler', 30);
            $table->unsignedBigInteger('recipient_id');
            $table->string('state', 20)->default('pending');
            $table->unsignedInteger('generation')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('leased_until')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->string('last_error', 180)->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'handler', 'recipient_id'], 'notification_delivery_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('message_outbox');
    }
};
