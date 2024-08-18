<?php

use App\Enums\SaleStrategy;
use App\Models\FlashSale;
use App\Models\FlashSaleOrder;
use App\Models\User;
use App\Support\Redis\FlashSaleKeys;
use Illuminate\Process\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;

/**
 * Start one worker process per shopper and release them all at the same instant.
 *
 * @return Collection<int, string> status of every attempt
 */
function race(string $action, FlashSale $sale, int $shoppers): Collection
{
    $users = User::factory()->count($shoppers)->create();
    $startAt = sprintf('%.6f', microtime(true) + 4);
    $env = collect(['APP_ENV', 'BROADCAST_CONNECTION', 'CACHE_STORE', 'DB_DATABASE', 'REDIS_DB', 'REDIS_CACHE_DB', 'REDIS_PREFIX'])
        ->mapWithKeys(fn (string $name) => [$name => getenv($name)])
        ->all();

    $results = Process::pool(function (Pool $pool) use ($users, $action, $sale, $startAt, $env) {
        foreach ($users as $user) {
            $pool->path(base_path())->env($env)->timeout(60)
                ->command([PHP_BINARY, 'tests/Concurrency/worker.php', $action, $sale->id, $user->id, $startAt]);
        }
    })->start()->wait();

    return collect($results->collect())->map(function ($result) {
        expect($result->successful())->toBeTrue($result->errorOutput());

        $output = json_decode($result->output(), true);
        expect($output['late_ms'])->toBeLessThan(1000, 'A worker booted too late to take part in the race.');

        return $output['status'];
    })->values();
}

it('reserves exactly the available units when 20 shoppers race for 5', function () {
    $sale = $this->liveSale(stock: 5);

    $statuses = race('reserve', $sale, shoppers: 20);

    $keys = FlashSaleKeys::for($sale->id);

    expect($statuses->countBy()->all())->toEqual(['reserved' => 5, 'sold_out' => 15])
        ->and(redisStock($sale->id))->toBe(0)
        ->and(Redis::hlen($keys->holds()))->toBe(5)
        ->and(Redis::zcard($keys->expirations()))->toBe(5);
});

it('sells exactly the stock with the Redis Lua strategy, end to end', function () {
    $sale = $this->liveSale(stock: 5);

    $statuses = race(SaleStrategy::RedisLua->value, $sale, shoppers: 20);

    expect($statuses->countBy()->all())->toEqual(['confirmed' => 5, 'sold_out' => 15])
        ->and(outboxLength($sale->id))->toBe(5);

    $this->artisan('flash-sale:consume-orders', ['--once' => true])->assertSuccessful();

    expect(FlashSaleOrder::query()->where('flash_sale_id', $sale->id)->count())->toBe(5)
        ->and(FlashSaleOrder::query()->distinct()->count('user_id'))->toBe(5)
        ->and($sale->fresh()->stock_remaining)->toBe(0)
        ->and(redisStock($sale->id))->toBe(0);
});

it('sells exactly the stock with a MySQL row lock', function () {
    $sale = FlashSale::factory()->withStock(5)->create();

    $statuses = race(SaleStrategy::DbLock->value, $sale, shoppers: 20);

    expect($statuses->countBy()->all())->toEqual(['confirmed' => 5, 'sold_out' => 15])
        ->and(FlashSaleOrder::query()->where('flash_sale_id', $sale->id)->count())->toBe(5)
        ->and($sale->fresh()->stock_remaining)->toBe(0);
});

it('proves the naive read-check-write oversells under the same load', function () {
    $sale = FlashSale::factory()->withStock(5)->create();

    $statuses = race(SaleStrategy::Naive->value, $sale, shoppers: 20);

    $orders = FlashSaleOrder::query()->where('flash_sale_id', $sale->id)->count();

    expect($orders)->toBeGreaterThan(5)
        ->and($statuses->filter(fn ($s) => $s === 'confirmed')->count())->toBe($orders);
});
