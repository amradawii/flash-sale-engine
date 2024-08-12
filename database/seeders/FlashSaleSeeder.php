<?php

namespace Database\Seeders;

use App\Models\FlashSale;
use App\Models\Product;
use Illuminate\Database\Seeder;

class FlashSaleSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = [
            ['PlayStation 5 Slim', 'playstation-5-slim', 49_999, 29_999, 5, 'Console drop — only 5 units at this price.'],
            ['iPhone 15 Pro 256GB', 'iphone-15-pro-256gb', 119_900, 79_900, 10, 'Limited batch, one per customer.'],
            ['AirPods Pro (2nd gen)', 'airpods-pro-2', 24_900, 12_900, 25, 'Midnight deal on noise cancelling earbuds.'],
            ['Nintendo Switch OLED', 'nintendo-switch-oled', 34_999, 22_999, 1, 'The very last unit — two shoppers, one console.'],
        ];

        foreach ($catalog as [$name, $slug, $price, $salePrice, $stock, $description]) {
            $product = Product::updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'description' => $description, 'price_cents' => $price],
            );

            FlashSale::updateOrCreate(
                ['product_id' => $product->id],
                [
                    'name' => "$name Flash Sale",
                    'sale_price_cents' => $salePrice,
                    'stock_total' => $stock,
                    'stock_remaining' => $stock,
                    'max_per_user' => 1,
                    'reservation_ttl_seconds' => (int) config('flash_sale.reservation_ttl'),
                    'starts_at' => now()->subMinute(),
                    'ends_at' => now()->addDays(30),
                ],
            );
        }
    }
}
