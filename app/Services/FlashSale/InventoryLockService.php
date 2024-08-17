<?php

namespace App\Services\FlashSale;

use App\DTOs\ReclaimResultData;
use App\DTOs\ReservationResultData;
use App\Enums\InventoryEventType;
use App\Enums\ReservationStatus;
use App\Models\FlashSale;
use App\Models\FlashSaleOrder;
use App\Support\Redis\FlashSaleKeys;
use App\Support\Redis\LuaScriptRunner;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RuntimeException;

class InventoryLockService
{
    public function __construct(
        private readonly LuaScriptRunner $lua,
        private readonly InventoryBroadcaster $broadcaster,
    ) {}

    /**
     * Copy a sale's inventory from MySQL into Redis.
     *
     * @throws RuntimeException when the sale is live in Redis and $force is false
     */
    public function load(FlashSale $sale, bool $force = false): void
    {
        $keys = FlashSaleKeys::for($sale->id);
        $activeHolds = (int) Redis::hlen($keys->holds());
        $pendingOrders = (int) Redis::xlen($keys->orders());

        if (! $force && ($activeHolds > 0 || $pendingOrders > 0)) {
            throw new RuntimeException(
                "Sale #{$sale->id} is live: {$activeHolds} active reservation(s), {$pendingOrders} order(s) not yet persisted."
            );
        }

        $purchased = FlashSaleOrder::query()
            ->where('flash_sale_id', $sale->id)
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(quantity) as units')
            ->pluck('units', 'user_id')
            ->map(fn ($units) => (int) $units)
            ->all();

        foreach (Redis::xrange($keys->orders(), '-', '+') ?: [] as $entry) {
            $purchased[$entry['user_id']] = ($purchased[$entry['user_id']] ?? 0) + (int) $entry['quantity'];
        }

        // The orders stream is never cleared: it may hold confirmed orders
        // that the consumer has not persisted yet.
        Redis::transaction(function ($redis) use ($keys, $sale, $purchased) {
            $redis->del($keys->stock(), $keys->holds(), $keys->reservations(), $keys->expirations(), $keys->purchased());
            $redis->set($keys->stock(), $sale->stock_remaining);

            if ($purchased !== []) {
                $redis->hmset($keys->purchased(), $purchased);
            }

            $redis->sadd(FlashSaleKeys::ACTIVE_SALES, $sale->id);
        });

        $this->broadcaster->publish($sale->id, InventoryEventType::Sync, fn () => $this->snapshot($sale->id), 'Sale inventory loaded.');
    }

    /** Remove every Redis key of a sale, including unprocessed orders. */
    public function forget(int $saleId): void
    {
        Redis::del(...FlashSaleKeys::for($saleId)->all());
        Redis::srem(FlashSaleKeys::ACTIVE_SALES, $saleId);
    }

    public function reserve(FlashSale $sale, int $userId, int $quantity = 1): ReservationResultData
    {
        $keys = FlashSaleKeys::for($sale->id);

        $result = $this->lua->run('reserve', [
            $keys->stock(), $keys->holds(), $keys->reservations(), $keys->expirations(), $keys->purchased(),
        ], [
            $userId, $quantity, $sale->reservation_ttl_seconds, (string) Str::uuid(), $sale->max_per_user,
        ]);

        $data = match ((int) $result[0]) {
            1 => new ReservationResultData(ReservationStatus::Reserved, (int) $result[1], $result[2], (int) $result[3], $quantity),
            -1 => new ReservationResultData(ReservationStatus::AlreadyHolding, (int) $result[1], $result[2], (int) $result[3]),
            0 => new ReservationResultData(ReservationStatus::SoldOut, (int) $result[1]),
            -3 => new ReservationResultData(ReservationStatus::LimitReached, (int) $result[1]),
            -4 => new ReservationResultData(ReservationStatus::SaleNotLoaded, 0),
        };

        if ($data->succeeded()) {
            $this->broadcaster->publish(
                $sale->id,
                $data->availableStock === 0 ? InventoryEventType::SoldOut : InventoryEventType::Reserved,
                fn () => $this->snapshot($sale->id),
                sprintf('%s reserved %d unit(s).', self::shopperName($userId), $quantity),
                $userId,
            );
        }

        return $data;
    }

    public function confirm(FlashSale $sale, int $userId, string $token): ReservationResultData
    {
        $keys = FlashSaleKeys::for($sale->id);

        $result = $this->lua->run('confirm', [
            $keys->stock(), $keys->holds(), $keys->reservations(), $keys->expirations(), $keys->purchased(), $keys->orders(),
        ], [$userId, $token, $sale->id]);

        $data = match ((int) $result[0]) {
            1 => new ReservationResultData(
                ReservationStatus::Confirmed, (int) $result[3], $token, quantity: (int) $result[1], orderStreamId: $result[2],
            ),
            -2 => new ReservationResultData(ReservationStatus::NotFound, (int) $result[1], $token),
            -5 => new ReservationResultData(ReservationStatus::Expired, (int) $result[1], $token),
        };

        if ($data->succeeded()) {
            $this->broadcaster->publish(
                $sale->id,
                InventoryEventType::Confirmed,
                fn () => $this->snapshot($sale->id),
                sprintf('%s paid; order queued in the outbox stream.', self::shopperName($userId)),
                $userId,
            );
        }

        return $data;
    }

    public function release(FlashSale $sale, int $userId, string $token): ReservationResultData
    {
        $keys = FlashSaleKeys::for($sale->id);

        $result = $this->lua->run('release', [
            $keys->stock(), $keys->holds(), $keys->reservations(), $keys->expirations(),
        ], [$userId, $token]);

        $data = match ((int) $result[0]) {
            1 => new ReservationResultData(ReservationStatus::Released, (int) $result[2], $token, quantity: (int) $result[1]),
            -2 => new ReservationResultData(ReservationStatus::NotFound, (int) $result[1], $token),
        };

        if ($data->succeeded()) {
            $this->broadcaster->publish(
                $sale->id,
                InventoryEventType::Released,
                fn () => $this->snapshot($sale->id),
                sprintf('%s cancelled; %d unit(s) back in stock.', self::shopperName($userId), $data->quantity),
                $userId,
            );
        }

        return $data;
    }

    public function reclaim(int $saleId, ?int $limit = null): ReclaimResultData
    {
        $keys = FlashSaleKeys::for($saleId);

        $result = $this->lua->run('reclaim', [
            $keys->stock(), $keys->holds(), $keys->reservations(), $keys->expirations(),
        ], [$limit ?? config('flash_sale.reclaim_batch')]);

        $data = new ReclaimResultData($saleId, (int) $result[0], (int) $result[1], (int) $result[2], (int) $result[3]);

        if ($data->unitsReclaimed > 0) {
            $this->broadcaster->publish(
                $saleId,
                InventoryEventType::Expired,
                fn () => $this->snapshot($saleId),
                sprintf('%d reservation(s) expired; %d unit(s) returned to stock.', $data->expiredReservations, $data->unitsReclaimed),
            );
        }

        return $data;
    }

    public static function shopperName(int $userId): string
    {
        return sprintf('Shopper %03d', $userId);
    }

    /** @return list<int> */
    public function activeSaleIds(): array
    {
        return array_map('intval', Redis::smembers(FlashSaleKeys::ACTIVE_SALES) ?: []);
    }

    /**
     * @return array{loaded: bool, available_stock: int, active_reservations: int, pending_orders: int}
     */
    public function snapshot(int $saleId): array
    {
        $keys = FlashSaleKeys::for($saleId);

        [$stock, $active, $pending] = Redis::pipeline(function ($pipe) use ($keys) {
            $pipe->get($keys->stock());
            $pipe->zcard($keys->expirations());
            $pipe->xlen($keys->orders());
        });

        return [
            'loaded' => $stock !== false && $stock !== null,
            'available_stock' => (int) $stock,
            'active_reservations' => (int) $active,
            'pending_orders' => (int) $pending,
        ];
    }

    /**
     * Soonest-expiring reservations first.
     *
     * @return list<array{token: string, user_id: int, quantity: int, expires_at: int}>
     */
    public function reservations(int $saleId, int $limit = 50): array
    {
        $keys = FlashSaleKeys::for($saleId);
        $tokens = Redis::zrange($keys->expirations(), 0, $limit - 1, ['withscores' => true]) ?: [];

        if ($tokens === []) {
            return [];
        }

        $meta = Redis::hmget($keys->reservations(), array_keys($tokens));

        return collect(array_keys($tokens))
            ->map(function (string $token, int $i) use ($tokens, $meta) {
                [$userId, $quantity] = array_pad(explode('|', (string) ($meta[$i] ?? '')), 2, 0);

                return [
                    'token' => $token,
                    'user_id' => (int) $userId,
                    'quantity' => (int) $quantity,
                    'expires_at' => (int) $tokens[$token],
                ];
            })
            ->values()
            ->all();
    }
}
