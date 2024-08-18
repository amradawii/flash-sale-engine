<?php

use App\Support\Redis\FlashSaleKeys;
use App\Support\Redis\LuaScriptRunner;
use Illuminate\Support\Facades\Redis;

it('reloads a script when Redis lost its script cache', function () {
    $keys = FlashSaleKeys::for(7);
    Redis::set($keys->stock(), 3);
    Redis::connection()->client()->script('flush');

    $result = app(LuaScriptRunner::class)->run('reserve', [
        $keys->stock(), $keys->holds(), $keys->reservations(), $keys->expirations(), $keys->purchased(),
    ], [1, 1, 60, 'token-1', 1]);

    expect((int) $result[0])->toBe(1)
        ->and((int) $result[1])->toBe(2);
});

it('reports that a sale is not loaded instead of creating stock', function () {
    $keys = FlashSaleKeys::for(8);

    $result = app(LuaScriptRunner::class)->run('reserve', [
        $keys->stock(), $keys->holds(), $keys->reservations(), $keys->expirations(), $keys->purchased(),
    ], [1, 1, 60, 'token-1', 1]);

    expect((int) $result[0])->toBe(-4)
        ->and(Redis::exists($keys->stock()))->toBe(0);
});
