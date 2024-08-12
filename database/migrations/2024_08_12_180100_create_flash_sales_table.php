<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flash_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sale_price_cents');
            $table->unsignedInteger('stock_total');
            $table->unsignedInteger('stock_remaining');
            $table->unsignedTinyInteger('max_per_user')->default(1);
            $table->unsignedInteger('reservation_ttl_seconds')->default(300);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamps();

            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flash_sales');
    }
};
