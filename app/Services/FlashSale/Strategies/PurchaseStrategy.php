<?php

namespace App\Services\FlashSale\Strategies;

use App\DTOs\ReservationResultData;
use App\Enums\SaleStrategy;
use App\Models\FlashSale;

/**
 * One complete purchase: take a unit from stock, do the slow per-order work
 * (payment, fraud check...) and record the order.
 */
interface PurchaseStrategy
{
    public function key(): SaleStrategy;

    public function purchase(FlashSale $sale, int $userId, int $quantity = 1): ReservationResultData;
}
