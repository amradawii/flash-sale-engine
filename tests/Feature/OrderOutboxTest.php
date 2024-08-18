<?php

use App\Models\FlashSale;
use App\Models\FlashSaleOrder;
use App\Services\FlashSale\InventoryLockService;
use App\Services\FlashSale\OrderPersister;
use App\Support\Redis\FlashSaleKeys;
use Illuminate\Support\Facades\Redis;

function confirmedOrder(FlashSale $sale, int $userId): string
{
    $inventory = app(InventoryLockService::class);
    $reservation = $inventory->reserve($sale, $userId);

    return $inventory->confirm($sale, $userId, $reservation->token)->token;
}

it('persists confirmed orders from the stream to MySQL and acknowledges them', function () {
    $sale = $this->liveSale(stock: 5);
    $tokens = [confirmedOrder($sale, $this->shopper()->id), confirmedOrder($sale, $this->shopper()->id)];

    $this->artisan('flash-sale:consume-orders', ['--once' => true])->assertSuccessful();

    expect(FlashSaleOrder::query()->pluck('reservation_token')->sort()->values()->all())->toBe(collect($tokens)->sort()->values()->all())
        ->and($sale->fresh()->stock_remaining)->toBe(3)
        ->and(outboxLength($sale->id))->toBe(0);
});

it('recovers orders a crashed worker received but never acknowledged', function () {
    $sale = $this->liveSale(stock: 5);
    $token = confirmedOrder($sale, $this->shopper()->id);
    $stream = FlashSaleKeys::for($sale->id)->orders();
    $group = config('flash_sale.consumer.group');

    // A worker reads the entry, then dies before writing to MySQL.
    Redis::xgroup('CREATE', $stream, $group, '0', true);
    $delivered = Redis::xreadgroup($group, 'persister-1', [$stream => '>'], 10);
    expect($delivered)->not->toBeEmpty()
        ->and(FlashSaleOrder::query()->count())->toBe(0);

    // The restarted worker reads its pending entries first.
    $this->artisan('flash-sale:consume-orders', ['--once' => true, '--consumer' => 'persister-1'])->assertSuccessful();

    expect(FlashSaleOrder::query()->where('reservation_token', $token)->exists())->toBeTrue()
        ->and(Redis::xpending($stream, $group))->toMatchArray([0 => 0])
        ->and(outboxLength($sale->id))->toBe(0);
});

it('ignores a redelivered order instead of saving it twice', function () {
    $sale = $this->liveSale(stock: 5);
    $entry = [
        'sale_id' => (string) $sale->id,
        'user_id' => (string) $this->shopper()->id,
        'token' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d',
        'quantity' => '1',
        'confirmed_at' => (string) time(),
    ];
    $persister = app(OrderPersister::class);

    expect($persister->persist($entry))->toBeTrue()
        ->and($persister->persist($entry))->toBeFalse()
        ->and(FlashSaleOrder::query()->count())->toBe(1)
        ->and($sale->fresh()->stock_remaining)->toBe(4);
});

it('skips orders whose sale was deleted', function () {
    $sale = $this->liveSale(stock: 5);
    confirmedOrder($sale, $this->shopper()->id);
    $sale->delete();

    $this->artisan('flash-sale:consume-orders', ['--once' => true])->assertSuccessful();

    expect(FlashSaleOrder::query()->count())->toBe(0)
        ->and(outboxLength($sale->id))->toBe(0);
});
