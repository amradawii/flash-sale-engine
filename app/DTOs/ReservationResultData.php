<?php

namespace App\DTOs;

use App\Enums\ReservationStatus;

final readonly class ReservationResultData
{
    public function __construct(
        public ReservationStatus $status,
        public int $availableStock,
        public ?string $token = null,
        public ?int $expiresAt = null,
        public ?int $quantity = null,
        public ?string $orderStreamId = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->status->succeeded();
    }

    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status->value,
            'message' => $this->status->message(),
            'available_stock' => $this->availableStock,
            'reservation_token' => $this->token,
            'expires_at' => $this->expiresAt,
            'quantity' => $this->quantity,
            'order_stream_id' => $this->orderStreamId,
        ], fn ($value) => $value !== null);
    }
}
