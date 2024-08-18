<?php

use App\Http\Middleware\IdempotencyMiddleware;
use Illuminate\Support\Facades\Redis;

it('replays the original response for a retried request', function () {
    $sale = $this->liveSale(stock: 5);
    $headers = $this->asShopper($this->shopper(), 'retry-key-0001');

    $first = $this->postJson("/api/flash-sales/{$sale->id}/reservations", [], $headers)
        ->assertCreated()
        ->assertHeader(IdempotencyMiddleware::REPLAYED_HEADER, 'false');

    $retry = $this->postJson("/api/flash-sales/{$sale->id}/reservations", [], $headers)
        ->assertCreated()
        ->assertHeader(IdempotencyMiddleware::REPLAYED_HEADER, 'true');

    expect($retry->json())->toBe($first->json())
        ->and(redisStock($sale->id))->toBe(4);
});

it('confirms once when the payment request is retried', function () {
    $sale = $this->liveSale(stock: 5);
    $shopper = $this->shopper();
    $token = $this->postJson("/api/flash-sales/{$sale->id}/reservations", [], $this->asShopper($shopper))->json('reservation_token');
    $headers = $this->asShopper($shopper, 'pay-key-0001');

    $this->postJson("/api/flash-sales/{$sale->id}/reservations/{$token}/confirm", [], $headers)->assertOk();
    $this->postJson("/api/flash-sales/{$sale->id}/reservations/{$token}/confirm", [], $headers)
        ->assertOk()
        ->assertHeader(IdempotencyMiddleware::REPLAYED_HEADER, 'true');

    expect(outboxLength($sale->id))->toBe(1);
});

it('answers 409 while the first request with the key is still running', function () {
    $sale = $this->liveSale(stock: 5);
    $shopper = $this->shopper();
    $path = "api/flash-sales/{$sale->id}/reservations";

    // The state the first request leaves while it is still being processed.
    Redis::set('idempotency:'.sha1("{$shopper->id}|POST|{$path}|click-key-0001"), json_encode([
        'state' => 'processing',
        'fingerprint' => sha1('[]'),
    ]));

    $this->postJson("/{$path}", [], $this->asShopper($shopper, 'click-key-0001'))
        ->assertStatus(409)
        ->assertJsonPath('status', 'request_in_progress')
        ->assertHeader('Retry-After', 1);

    expect(redisStock($sale->id))->toBe(5);
});

it('rejects a key reused with a different payload', function () {
    $sale = $this->liveSale(stock: 5, attributes: ['max_per_user' => 3]);
    $headers = $this->asShopper($this->shopper(), 'reuse-key-0001');

    $this->postJson("/api/flash-sales/{$sale->id}/reservations", ['quantity' => 1], $headers)->assertCreated();

    $this->postJson("/api/flash-sales/{$sale->id}/reservations", ['quantity' => 2], $headers)
        ->assertStatus(422)
        ->assertJsonPath('status', 'idempotency_key_reused');
});

it('scopes keys per shopper', function () {
    $sale = $this->liveSale(stock: 5);

    $this->postJson("/api/flash-sales/{$sale->id}/reservations", [], $this->asShopper($this->shopper(), 'shared-key-0001'))->assertCreated();
    $this->postJson("/api/flash-sales/{$sale->id}/reservations", [], $this->asShopper($this->shopper(), 'shared-key-0001'))
        ->assertCreated()
        ->assertHeader(IdempotencyMiddleware::REPLAYED_HEADER, 'false');

    expect(redisStock($sale->id))->toBe(3);
});
