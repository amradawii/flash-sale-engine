<?php

namespace Tests;

use App\Models\FlashSale;
use App\Models\User;
use App\Services\FlashSale\InventoryLockService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->flushTestRedis();
    }

    /** Every test talks to a real Redis, on a database the demo never uses. */
    protected function flushTestRedis(): void
    {
        $database = (int) config('database.redis.default.database');

        if ($database !== 15 || config('database.redis.options.prefix') !== 'fse_test_') {
            throw new RuntimeException('Refusing to flush Redis: tests must run on database 15 with the fse_test_ prefix.');
        }

        Redis::connection()->flushdb();
    }

    /** Create a sale and load its stock into Redis, as `flash-sale:warmup` does. */
    protected function liveSale(int $stock = 5, array $attributes = []): FlashSale
    {
        $sale = FlashSale::factory()->withStock($stock)->create($attributes);

        app(InventoryLockService::class)->load($sale);

        return $sale;
    }

    protected function shopper(): User
    {
        return User::factory()->create();
    }

    /** @return array<string, string> */
    protected function asShopper(User|int $user, ?string $idempotencyKey = null): array
    {
        return array_filter([
            'X-User-Id' => (string) ($user instanceof User ? $user->id : $user),
            'X-Idempotency-Key' => $idempotencyKey ?? 'key-'.bin2hex(random_bytes(8)),
        ]);
    }
}
