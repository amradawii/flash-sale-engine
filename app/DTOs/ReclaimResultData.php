<?php

namespace App\DTOs;

final readonly class ReclaimResultData
{
    public function __construct(
        public int $saleId,
        public int $expiredReservations,
        public int $unitsReclaimed,
        public int $availableStock,
        public int $activeReservations,
    ) {}
}
