<?php

namespace App\Services\FlashSale\Strategies;

use App\DTOs\ReservationResultData;
use App\Enums\ReservationStatus;
use App\Enums\SaleStrategy;
use App\Models\FlashSale;
use App\Models\FlashSaleOrder;
use Illuminate\Support\Str;

/**
 * DELIBERATELY BROKEN. Read stock, check it, write it back.
 *
 * Between the read and the write, other requests read the same value, pass the
 * same check and overwrite each other's update ("lost update"): more orders
 * are created than there are units.
 */
class NaiveStrategy implements PurchaseStrategy
{
    use SimulatesOrderProcessing;

    public function key(): SaleStrategy
    {
        return SaleStrategy::Naive;
    }

    public function purchase(FlashSale $sale, int $userId, int $quantity = 1): ReservationResultData
    {
        $stock = FlashSale::query()->whereKey($sale->id)->value('stock_remaining');

        if ($stock < $quantity) {
            return new ReservationResultData(ReservationStatus::SoldOut, $stock);
        }

        $this->processOrder();

        FlashSale::query()->whereKey($sale->id)->update(['stock_remaining' => $stock - $quantity]);

        FlashSaleOrder::query()->create([
            'flash_sale_id' => $sale->id,
            'user_id' => $userId,
            'reservation_token' => (string) Str::uuid(),
            'quantity' => $quantity,
            'unit_price_cents' => $sale->sale_price_cents,
            'strategy' => $this->key(),
            'confirmed_at' => now(),
        ]);

        return new ReservationResultData(ReservationStatus::Confirmed, $stock - $quantity, quantity: $quantity);
    }
}
