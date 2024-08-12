<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flash_sale_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flash_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Unique token makes order persistence idempotent: replaying the same
            // confirmed reservation can never create a second order.
            $table->uuid('reservation_token')->unique();
            $table->unsignedTinyInteger('quantity');
            $table->unsignedInteger('unit_price_cents');
            $table->string('strategy', 16);
            $table->timestamp('confirmed_at');
            $table->timestamps();

            $table->index(['flash_sale_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flash_sale_orders');
    }
};
