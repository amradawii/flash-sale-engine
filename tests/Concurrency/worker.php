<?php

/*
 * One shopper, one PHP process. The concurrency tests start many of these at
 * once; each boots the app, connects, then waits for the shared start time so
 * every attempt hits Redis/MySQL in the same few milliseconds.
 *
 * Usage: php worker.php <reserve|naive|db_lock|redis_lua> <sale_id> <user_id> <start_at_unix_float>
 */

use App\Enums\SaleStrategy;
use App\Models\FlashSale;
use App\Services\FlashSale\InventoryLockService;
use App\Services\FlashSale\Strategies\StrategyResolver;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Redis;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $action, $saleId, $userId, $startAt] = $argv;

$sale = FlashSale::query()->findOrFail((int) $saleId);
Redis::ping();

$wait = (float) $startAt - microtime(true);

if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}

$result = $action === 'reserve'
    ? app(InventoryLockService::class)->reserve($sale, (int) $userId)
    : app(StrategyResolver::class)->resolve(SaleStrategy::from($action))->purchase($sale, (int) $userId);

echo json_encode([
    'status' => $result->status->value,
    'late_ms' => $wait < 0 ? (int) round(-$wait * 1000) : 0,
]);
