<?php

use App\Enums\InventoryEventType;
use App\Events\InventoryUpdated;
use App\Models\FlashSale;
use App\Support\Redis\FlashSaleKeys;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;

it('reserves a unit and takes it out of the available stock', function () {
    $sale = $this->liveSale(stock: 5);

    $this->postJson("/api/flash-sales/{$sale->id}/reservations", [], $this->asShopper($this->shopper()))
        ->assertCreated()
        ->assertJsonPath('status', 'reserved')
        ->assertJsonPath('available_stock', 4)
        ->assertJsonStructure(['reservation_token', 'expires_at']);

    expect(redisStock($sale->id))->toBe(4)
        ->and($sale->fresh()->stock_remaining)->toBe(5);
});

it('rejects requests without a known shopper', function () {
    $sale = $this->liveSale();

    $this->postJson("/api/flash-sales/{$sale->id}/reservations", [], ['X-Idempotency-Key' => 'key-12345678'])
        ->assertUnauthorized();

    $this->postJson("/api/flash-sales/{$sale->id}/reservations", [], $this->asShopper(999_999))
        ->assertUnauthorized();
});

it('requires an idempotency key on unsafe requests', function () {
    $sale = $this->liveSale();

    $this->postJson("/api/flash-sales/{$sale->id}/reservations", [], ['X-User-Id' => $this->shopper()->id])
        ->assertStatus(400)
        ->assertJsonPath('status', 'invalid_idempotency_key');
});

it('allows one active reservation per shopper, even with a new idempotency key', function () {
    $sale = $this->liveSale(stock: 5);
    $shopper = $this->shopper();

    $first = $this->postJson("/api/flash-sales/{$sale->id}/reservations", [], $this->asShopper($shopper))->assertCreated();

    $this->postJson("/api/flash-sales/{$sale->id}/reservations", [], $this->asShopper($shopper))
        ->assertStatus(409)
        ->assertJsonPath('status', 'already_holding')
        ->assertJsonPath('reservation_token', $first->json('reservation_token'));

    expect(redisStock($sale->id))->toBe(4);
});

it('answers sold out once the stock is gone', function () {
    $sale = $this->liveSale(stock: 1);

    $this->postJson("/api/flash-sales/{$sale->id}/reservations", [], $this->asShopper($this->shopper()))->assertCreated();

    $this->postJson("/api/flash-sales/{$sale->id}/reservations", [], $this->asShopper($this->shopper()))
        ->assertStatus(409)
        ->assertJsonPath('status', 'sold_out')
        ->assertJsonPath('available_stock', 0);
});

it('enforces the per-shopper limit', function () {
    $sale = $this->liveSale(stock: 10, attributes: ['max_per_user' => 2]);

    $this->postJson("/api/flash-sales/{$sale->id}/reservations", ['quantity' => 3], $this->asShopper($this->shopper()))
        ->assertStatus(422)
        ->assertJsonPath('status', 'limit_reached');

    expect(redisStock($sale->id))->toBe(10);
});

it('keeps the per-shopper limit across confirmed orders', function () {
    $sale = $this->liveSale(stock: 5);
    $shopper = $this->shopper();

    $token = $this->postJson("/api/flash-sales/{$sale->id}/reservations", [], $this->asShopper($shopper))->json('reservation_token');
    $this->postJson("/api/flash-sales/{$sale->id}/reservations/{$token}/confirm", [], $this->asShopper($shopper))->assertOk();

    $this->postJson("/api/flash-sales/{$sale->id}/reservations", [], $this->asShopper($shopper))
        ->assertStatus(422)
        ->assertJsonPath('status', 'limit_reached');
});

it('refuses reservations when the sale is not running', function (string $state) {
    $sale = FlashSale::factory()->{$state}()->create();

    $this->postJson("/api/flash-sales/{$sale->id}/reservations", [], $this->asShopper($this->shopper()))
        ->assertStatus(422)
        ->assertJsonPath('status', 'sale_closed');
})->with(['upcoming', 'ended']);

it('confirms a reservation into the outbox stream in one step', function () {
    $sale = $this->liveSale(stock: 5);
    $shopper = $this->shopper();
    $token = $this->postJson("/api/flash-sales/{$sale->id}/reservations", [], $this->asShopper($shopper))->json('reservation_token');

    $this->postJson("/api/flash-sales/{$sale->id}/reservations/{$token}/confirm", [], $this->asShopper($shopper))
        ->assertOk()
        ->assertJsonPath('status', 'confirmed');

    $keys = FlashSaleKeys::for($sale->id);
    $entries = array_values(Redis::xrange($keys->orders(), '-', '+'));

    expect($entries)->toHaveCount(1)
        ->and($entries[0])->toMatchArray(['user_id' => (string) $shopper->id, 'token' => $token, 'quantity' => '1'])
        ->and(Redis::hexists($keys->holds(), $shopper->id))->toBeFalse()
        ->and(Redis::zcard($keys->expirations()))->toBe(0)
        ->and(redisStock($sale->id))->toBe(4);
});

it('does not let a shopper confirm someone else\'s reservation', function () {
    $sale = $this->liveSale();
    $token = $this->postJson("/api/flash-sales/{$sale->id}/reservations", [], $this->asShopper($this->shopper()))->json('reservation_token');

    $this->postJson("/api/flash-sales/{$sale->id}/reservations/{$token}/confirm", [], $this->asShopper($this->shopper()))
        ->assertNotFound();

    expect(outboxLength($sale->id))->toBe(0);
});

it('confirms a reservation only once', function () {
    $sale = $this->liveSale();
    $shopper = $this->shopper();
    $token = $this->postJson("/api/flash-sales/{$sale->id}/reservations", [], $this->asShopper($shopper))->json('reservation_token');

    $this->postJson("/api/flash-sales/{$sale->id}/reservations/{$token}/confirm", [], $this->asShopper($shopper))->assertOk();
    $this->postJson("/api/flash-sales/{$sale->id}/reservations/{$token}/confirm", [], $this->asShopper($shopper))->assertNotFound();

    expect(outboxLength($sale->id))->toBe(1);
});

it('returns the units when a shopper cancels', function () {
    $sale = $this->liveSale(stock: 5);
    $shopper = $this->shopper();
    $token = $this->postJson("/api/flash-sales/{$sale->id}/reservations", [], $this->asShopper($shopper))->json('reservation_token');

    $this->deleteJson("/api/flash-sales/{$sale->id}/reservations/{$token}", [], $this->asShopper($shopper))
        ->assertOk()
        ->assertJsonPath('status', 'released')
        ->assertJsonPath('available_stock', 5);

    $this->postJson("/api/flash-sales/{$sale->id}/reservations/{$token}/confirm", [], $this->asShopper($shopper))->assertNotFound();
});

it('broadcasts inventory changes', function () {
    Event::fake([InventoryUpdated::class]);
    $sale = $this->liveSale(stock: 1);

    $this->postJson("/api/flash-sales/{$sale->id}/reservations", [], $this->asShopper($this->shopper()))->assertCreated();

    Event::assertDispatched(InventoryUpdated::class, fn (InventoryUpdated $event) => $event->saleId === $sale->id
        && $event->type === InventoryEventType::SoldOut
        && $event->snapshot['available_stock'] === 0);
});
