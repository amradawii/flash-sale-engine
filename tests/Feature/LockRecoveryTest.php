<?php

use App\Support\Redis\FlashSaleKeys;
use Illuminate\Support\Facades\Redis;

/** Move a reservation's expiry into the past instead of sleeping through its TTL. */
function expireReservation(int $saleId, string $token): void
{
    Redis::zadd(FlashSaleKeys::for($saleId)->expirations(), time() - 1, $token);
}

it('returns the stock of abandoned reservations', function () {
    $sale = $this->liveSale(stock: 3);
    $tokens = collect(range(1, 3))->map(fn () => $this->postJson(
        "/api/flash-sales/{$sale->id}/reservations", [], $this->asShopper($this->shopper()),
    )->json('reservation_token'));

    expireReservation($sale->id, $tokens[0]);
    expireReservation($sale->id, $tokens[1]);

    $this->artisan('flash-sale:reclaim-locks')->assertSuccessful();

    $keys = FlashSaleKeys::for($sale->id);

    expect(redisStock($sale->id))->toBe(2)
        ->and(Redis::zcard($keys->expirations()))->toBe(1)
        ->and(Redis::hlen($keys->holds()))->toBe(1)
        ->and(Redis::hlen($keys->reservations()))->toBe(1);
});

it('leaves reservations that have not expired alone', function () {
    $sale = $this->liveSale(stock: 2);
    $this->postJson("/api/flash-sales/{$sale->id}/reservations", [], $this->asShopper($this->shopper()))->assertCreated();

    $this->artisan('flash-sale:reclaim-locks')->assertSuccessful();

    expect(redisStock($sale->id))->toBe(1);
});

it('refuses to confirm an expired reservation, so a unit is never both returned and sold', function () {
    $sale = $this->liveSale(stock: 1);
    $shopper = $this->shopper();
    $token = $this->postJson("/api/flash-sales/{$sale->id}/reservations", [], $this->asShopper($shopper))->json('reservation_token');

    expireReservation($sale->id, $token);

    $this->postJson("/api/flash-sales/{$sale->id}/reservations/{$token}/confirm", [], $this->asShopper($shopper))
        ->assertStatus(410)
        ->assertJsonPath('status', 'expired');

    $this->artisan('flash-sale:reclaim-locks')->assertSuccessful();

    expect(outboxLength($sale->id))->toBe(0)
        ->and(redisStock($sale->id))->toBe(1);
});

it('lets another shopper buy the reclaimed unit', function () {
    $sale = $this->liveSale(stock: 1);
    $token = $this->postJson("/api/flash-sales/{$sale->id}/reservations", [], $this->asShopper($this->shopper()))->json('reservation_token');
    $lateShopper = $this->shopper();

    $this->postJson("/api/flash-sales/{$sale->id}/reservations", [], $this->asShopper($lateShopper))->assertJsonPath('status', 'sold_out');

    expireReservation($sale->id, $token);
    $this->artisan('flash-sale:reclaim-locks')->assertSuccessful();

    $this->postJson("/api/flash-sales/{$sale->id}/reservations", [], $this->asShopper($lateShopper))->assertCreated();
});

it('reclaims in bounded batches so Redis is never blocked for long', function () {
    $sale = $this->liveSale(stock: 5);
    collect(range(1, 5))->each(function () use ($sale) {
        $token = $this->postJson("/api/flash-sales/{$sale->id}/reservations", [], $this->asShopper($this->shopper()))->json('reservation_token');
        expireReservation($sale->id, $token);
    });

    $this->artisan('flash-sale:reclaim-locks', ['--limit' => 2])->assertSuccessful();
    expect(redisStock($sale->id))->toBe(2);

    $this->artisan('flash-sale:reclaim-locks', ['--limit' => 2])->assertSuccessful();
    $this->artisan('flash-sale:reclaim-locks', ['--limit' => 2])->assertSuccessful();
    expect(redisStock($sale->id))->toBe(5);
});
