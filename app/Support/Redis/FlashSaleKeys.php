<?php

namespace App\Support\Redis;

/**
 * Redis key layout for a single flash sale.
 *
 * The "{id}" hash tag pins every key of one sale to the same cluster slot,
 * which multi-key Lua scripts require.
 */
final readonly class FlashSaleKeys
{
    public const ACTIVE_SALES = 'flash_sale:active';

    /** Demo switch used by the simulator to hold the order consumer. */
    public const CONSUMER_PAUSED = 'flash_sale:consumer:paused';

    public function __construct(public int $saleId) {}

    public static function for(int $saleId): self
    {
        return new self($saleId);
    }

    /** Available units (string integer). */
    public function stock(): string
    {
        return $this->key('stock');
    }

    /** user_id => reservation token (one active hold per user). */
    public function holds(): string
    {
        return $this->key('holds');
    }

    /** reservation token => "user_id|quantity". */
    public function reservations(): string
    {
        return $this->key('reservations');
    }

    /** ZSET of reservation tokens scored by expiry unix timestamp. */
    public function expirations(): string
    {
        return $this->key('expirations');
    }

    /** user_id => units already confirmed (enforces max_per_user across purchases). */
    public function purchased(): string
    {
        return $this->key('purchased');
    }

    /** Stream of confirmed orders waiting to be persisted to MySQL. */
    public function orders(): string
    {
        return $this->key('orders');
    }

    /** Short-lived flag limiting broadcasts per sale during bursts. */
    public function broadcastThrottle(): string
    {
        return $this->key('broadcast_throttle');
    }

    /** Last state pushed to WebSocket clients. */
    public function broadcastState(): string
    {
        return $this->key('broadcast_state');
    }

    /** @return list<string> */
    public function all(): array
    {
        return [
            $this->stock(),
            $this->holds(),
            $this->reservations(),
            $this->expirations(),
            $this->purchased(),
            $this->orders(),
            $this->broadcastThrottle(),
            $this->broadcastState(),
        ];
    }

    private function key(string $suffix): string
    {
        return "flash_sale:{{$this->saleId}}:{$suffix}";
    }
}
