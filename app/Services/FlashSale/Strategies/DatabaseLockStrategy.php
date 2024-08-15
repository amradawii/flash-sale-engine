<?php

namespace App\Services\FlashSale\Strategies;

use App\DTOs\ReservationResultData;
use App\Enums\ReservationStatus;
use App\Enums\SaleStrategy;
use App\Models\FlashSale;
use App\Models\FlashSaleOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CORRECT, but every buyer queues on the same row.
 *
 * SELECT ... FOR UPDATE holds the row lock for the whole transaction,
 * including the slow per-order work, so purchases run one at a time and each
 * waiting request keeps a database connection busy.
 */
class DatabaseLockStrategy implements PurchaseStrategy
{
    use SimulatesOrderProcessing;

    public function key(): SaleStrategy
    {
        return SaleStrategy::DbLock;
    }

    public function purchase(FlashSale $sale, int $userId, int $quantity = 1): ReservationResultData
    {
        return DB::transaction(function () use ($sale, $userId, $quantity) {
            $locked = FlashSale::query()->lockForUpdate()->findOrFail($sale->id);

            if ($locked->stock_remaining < $quantity) {
                return new ReservationResultData(ReservationStatus::SoldOut, $locked->stock_remaining);
            }

            $this->processOrder();

            $locked->decrement('stock_remaining', $quantity);

            FlashSaleOrder::query()->create([
                'flash_sale_id' => $sale->id,
                'user_id' => $userId,
                'reservation_token' => (string) Str::uuid(),
                'quantity' => $quantity,
                'unit_price_cents' => $sale->sale_price_cents,
                'strategy' => $this->key(),
                'confirmed_at' => now(),
            ]);

            return new ReservationResultData(ReservationStatus::Confirmed, $locked->stock_remaining, quantity: $quantity);
        });
    }
}
