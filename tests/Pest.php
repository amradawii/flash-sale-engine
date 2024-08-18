<?php

use App\Support\Redis\FlashSaleKeys;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(Tests\TestCase::class)->in('Unit');

uses(Tests\TestCase::class, RefreshDatabase::class)->in('Feature');

// Worker processes must see the rows the test creates, so no wrapping transaction.
uses(Tests\TestCase::class, DatabaseTruncation::class)->in('Concurrency');

function redisStock(int $saleId): int
{
    return (int) Redis::get(FlashSaleKeys::for($saleId)->stock());
}

function outboxLength(int $saleId): int
{
    return (int) Redis::xlen(FlashSaleKeys::for($saleId)->orders());
}
