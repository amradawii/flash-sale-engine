<?php

namespace App\Services\FlashSale\Strategies;

use App\DTOs\ReservationResultData;
use App\Enums\SaleStrategy;
use App\Models\FlashSale;
use App\Services\FlashSale\InventoryLockService;

/**
 * CORRECT and parallel.
 *
 * The stock check + decrement is one atomic Lua call (microseconds). The slow
 * per-order work happens outside any lock while the unit is held by a
 * TTL reservation, then a second atomic call confirms it into the outbox
 * stream. MySQL is written later by the order consumer.
 */
class RedisLuaStrategy implements PurchaseStrategy
{
    use SimulatesOrderProcessing;

    public function __construct(private readonly InventoryLockService $inventory) {}

    public function key(): SaleStrategy
    {
        return SaleStrategy::RedisLua;
    }

    public function purchase(FlashSale $sale, int $userId, int $quantity = 1): ReservationResultData
    {
        $reservation = $this->inventory->reserve($sale, $userId, $quantity);

        if (! $reservation->succeeded()) {
            return $reservation;
        }

        $this->processOrder();

        return $this->inventory->confirm($sale, $userId, $reservation->token);
    }
}
