<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FlashSale>
 */
class FlashSaleFactory extends Factory
{
    public function definition(): array
    {
        $stock = fake()->numberBetween(5, 50);

        return [
            'product_id' => Product::factory(),
            'name' => 'Flash Sale: '.fake()->words(2, true),
            'sale_price_cents' => fake()->numberBetween(500, 100_000),
            'stock_total' => $stock,
            'stock_remaining' => $stock,
            'max_per_user' => 1,
            'reservation_ttl_seconds' => 300,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
        ];
    }

    public function withStock(int $stock): static
    {
        return $this->state(fn () => [
            'stock_total' => $stock,
            'stock_remaining' => $stock,
        ]);
    }

    public function upcoming(): static
    {
        return $this->state(fn () => [
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addDay(),
        ]);
    }

    public function ended(): static
    {
        return $this->state(fn () => [
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDay(),
        ]);
    }
}
