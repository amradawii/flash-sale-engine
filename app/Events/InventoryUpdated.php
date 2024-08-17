<?php

namespace App\Events;

use App\Enums\InventoryEventType;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class InventoryUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array{available_stock: int, active_reservations: int, pending_orders: int}  $snapshot
     */
    public function __construct(
        public readonly int $saleId,
        public readonly InventoryEventType $type,
        public readonly array $snapshot,
        public readonly ?string $message = null,
        public readonly ?int $userId = null,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel("flash-sale.{$this->saleId}");
    }

    public function broadcastAs(): string
    {
        return 'inventory.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'sale_id' => $this->saleId,
            'event_type' => $this->type->value,
            'available_stock' => $this->snapshot['available_stock'],
            'active_reservations' => $this->snapshot['active_reservations'],
            'pending_orders' => $this->snapshot['pending_orders'],
            'user_id' => $this->userId,
            'message' => $this->message,
            'timestamp' => (int) floor(microtime(true) * 1000),
        ];
    }
}
