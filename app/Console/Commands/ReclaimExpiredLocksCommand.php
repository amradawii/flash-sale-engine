<?php

namespace App\Console\Commands;

use App\Services\FlashSale\InventoryBroadcaster;
use App\Services\FlashSale\InventoryLockService;
use Illuminate\Console\Command;

class ReclaimExpiredLocksCommand extends Command
{
    protected $signature = 'flash-sale:reclaim-locks {--limit= : Max expired reservations reclaimed per sale}';

    protected $description = 'Return the stock of expired reservations to every active flash sale';

    public function handle(InventoryLockService $inventory, InventoryBroadcaster $broadcaster): int
    {
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        foreach ($inventory->activeSaleIds() as $saleId) {
            $result = $inventory->reclaim($saleId, $limit);

            if ($result->unitsReclaimed > 0) {
                $this->components->info(sprintf(
                    'Sale #%d: reclaimed %d unit(s) from %d expired reservation(s), %d now available.',
                    $saleId, $result->unitsReclaimed, $result->expiredReservations, $result->availableStock,
                ));
            }

            // Throttled broadcasts may have skipped the last change of a burst.
            $broadcaster->sync($saleId, $inventory->snapshot($saleId));
        }

        return self::SUCCESS;
    }
}
