<?php

use App\Models\FlashSale;
use App\Models\FlashSaleOrder;
use App\Services\FlashSale\InventoryLockService;
use App\Support\Redis\FlashSaleKeys;
use Illuminate\Support\Facades\Redis;

it('loads the remaining stock of a sale into Redis', function () {
    $sale = FlashSale::factory()->create(['stock_total' => 10, 'stock_remaining' => 7]);

    $this->artisan('flash-sale:warmup', ['sale' => [$sale->id]])->assertSuccessful();

    expect(redisStock($sale->id))->toBe(7)
        ->and(app(InventoryLockService::class)->activeSaleIds())->toContain($sale->id);
});

it('refuses to reload a sale while shoppers hold reservations', function () {
    $sale = $this->liveSale(stock: 5);
    app(InventoryLockService::class)->reserve($sale, $this->shopper()->id);

    $this->artisan('flash-sale:warmup', ['sale' => [$sale->id]])->assertFailed();

    expect(redisStock($sale->id))->toBe(4);
});

it('rebuilds the per-shopper limit from saved and not-yet-saved orders', function () {
    $sale = $this->liveSale(stock: 5);
    $saved = $this->shopper();
    $pending = $this->shopper();
    FlashSaleOrder::query()->create([
        'flash_sale_id' => $sale->id,
        'user_id' => $saved->id,
        'reservation_token' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
        'quantity' => 1,
        'unit_price_cents' => $sale->sale_price_cents,
        'strategy' => 'redis_lua',
        'confirmed_at' => now(),
    ]);
    Redis::xadd(FlashSaleKeys::for($sale->id)->orders(), '*', [
        'sale_id' => $sale->id, 'user_id' => $pending->id, 'token' => 'pending', 'quantity' => 1, 'confirmed_at' => time(),
    ]);

    $this->artisan('flash-sale:warmup', ['sale' => [$sale->id], '--force' => true])->assertSuccessful();

    $inventory = app(InventoryLockService::class);

    expect($inventory->reserve($sale, $saved->id)->status->value)->toBe('limit_reached')
        ->and($inventory->reserve($sale, $pending->id)->status->value)->toBe('limit_reached')
        ->and($inventory->reserve($sale, $this->shopper()->id)->status->value)->toBe('reserved')
        ->and(outboxLength($sale->id))->toBe(1);
});
