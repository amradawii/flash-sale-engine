<?php

namespace App\Services\FlashSale;

use App\Enums\SaleStrategy;
use App\Models\FlashSale;
use App\Models\FlashSaleOrder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OrderPersister
{
    /**
     * Persist one confirmed order from the outbox stream.
     *
     * Safe to call more than once for the same entry: the unique reservation
     * token turns a redelivery into a no-op.
     *
     * @param  array{sale_id: string, user_id: string, token: string, quantity: string, confirmed_at: string}  $entry
     * @return bool false when the order was already persisted or its sale no longer exists
     */
    public function persist(array $entry): bool
    {
        $sale = FlashSale::query()->find((int) $entry['sale_id']);

        if ($sale === null) {
            return false;
        }

        $quantity = (int) $entry['quantity'];

        try {
            DB::transaction(function () use ($entry, $sale, $quantity) {
                FlashSaleOrder::query()->create([
                    'flash_sale_id' => $sale->id,
                    'user_id' => (int) $entry['user_id'],
                    'reservation_token' => $entry['token'],
                    'quantity' => $quantity,
                    'unit_price_cents' => $sale->sale_price_cents,
                    'strategy' => SaleStrategy::RedisLua,
                    'confirmed_at' => Carbon::createFromTimestamp((int) $entry['confirmed_at']),
                ]);

                FlashSale::query()
                    ->whereKey($sale->id)
                    ->where('stock_remaining', '>=', $quantity)
                    ->decrement('stock_remaining', $quantity);
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
