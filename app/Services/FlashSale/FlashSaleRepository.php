<?php

namespace App\Services\FlashSale;

use App\Models\FlashSale;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;

/**
 * Sale settings rarely change during a sale, so the reservation hot path
 * reads them from cache instead of querying MySQL on every request.
 */
class FlashSaleRepository
{
    private const TTL_SECONDS = 30;

    public function find(int $saleId): FlashSale
    {
        $attributes = Cache::remember(
            "flash_sale:{$saleId}:settings",
            self::TTL_SECONDS,
            fn () => FlashSale::query()->find($saleId)?->getAttributes(),
        );

        if ($attributes === null) {
            Cache::forget("flash_sale:{$saleId}:settings");

            throw (new ModelNotFoundException)->setModel(FlashSale::class, [$saleId]);
        }

        return (new FlashSale)->newFromBuilder($attributes);
    }

    public function forget(int $saleId): void
    {
        Cache::forget("flash_sale:{$saleId}:settings");
    }
}
