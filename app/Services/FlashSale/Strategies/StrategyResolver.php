<?php

namespace App\Services\FlashSale\Strategies;

use App\Enums\SaleStrategy;
use Illuminate\Contracts\Container\Container;

class StrategyResolver
{
    public function __construct(private readonly Container $container) {}

    public function resolve(SaleStrategy $strategy): PurchaseStrategy
    {
        return $this->container->make(match ($strategy) {
            SaleStrategy::Naive => NaiveStrategy::class,
            SaleStrategy::DbLock => DatabaseLockStrategy::class,
            SaleStrategy::RedisLua => RedisLuaStrategy::class,
        });
    }
}
