<?php

namespace App\Services\FlashSale;

use App\Enums\InventoryEventType;
use App\Events\InventoryUpdated;
use App\Support\Redis\FlashSaleKeys;
use Closure;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Pushes inventory changes to Reverb.
 *
 * A burst of 1 000 reservations must not mean 1 000 WebSocket broadcasts, so
 * high-frequency events are limited to one per sale per throttle window; the
 * reclaim tick then calls sync() every second so clients always converge on
 * the final state. A broadcast failure never fails the purchase itself.
 */
class InventoryBroadcaster
{
    /**
     * @param  Closure(): array{available_stock: int, active_reservations: int, pending_orders: int}  $snapshot
     */
    public function publish(
        int $saleId,
        InventoryEventType $type,
        Closure $snapshot,
        ?string $message = null,
        ?int $userId = null,
    ): void {
        $keys = FlashSaleKeys::for($saleId);

        if ($type->throttled() && ! Redis::set($keys->broadcastThrottle(), 1, 'PX', config('flash_sale.broadcast_throttle_ms'), 'NX')) {
            return;
        }

        $this->send($keys, new InventoryUpdated($saleId, $type, $snapshot(), $message, $userId));
    }

    /**
     * Broadcast the current state only if it differs from the last one sent.
     *
     * @param  array{available_stock: int, active_reservations: int, pending_orders: int}  $snapshot
     */
    public function sync(int $saleId, array $snapshot): void
    {
        $keys = FlashSaleKeys::for($saleId);

        if (Redis::get($keys->broadcastState()) === $this->fingerprint($snapshot)) {
            return;
        }

        $this->send($keys, new InventoryUpdated($saleId, InventoryEventType::Sync, $snapshot));
    }

    private function send(FlashSaleKeys $keys, InventoryUpdated $event): void
    {
        try {
            broadcast($event);
            Redis::setex($keys->broadcastState(), 3600, $this->fingerprint($event->snapshot));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function fingerprint(array $snapshot): string
    {
        return implode(':', [
            $snapshot['available_stock'],
            $snapshot['active_reservations'],
            $snapshot['pending_orders'],
        ]);
    }
}
