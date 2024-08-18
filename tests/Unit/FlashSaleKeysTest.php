<?php

use App\Support\Redis\FlashSaleKeys;

it('puts every key of a sale in the same cluster slot', function () {
    $keys = FlashSaleKeys::for(42)->all();

    expect($keys)->toHaveCount(8)
        ->each->toStartWith('flash_sale:{42}:');
});

it('keeps sales apart', function () {
    expect(FlashSaleKeys::for(1)->stock())->not->toBe(FlashSaleKeys::for(2)->stock());
});
