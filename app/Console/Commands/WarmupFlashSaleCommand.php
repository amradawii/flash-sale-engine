<?php

namespace App\Console\Commands;

use App\Models\FlashSale;
use App\Services\FlashSale\InventoryLockService;
use Illuminate\Console\Command;
use RuntimeException;

class WarmupFlashSaleCommand extends Command
{
    protected $signature = 'flash-sale:warmup
                            {sale?* : IDs of the flash sales to load into Redis}
                            {--all : Load every sale that has not ended yet}
                            {--force : Reset a sale even if shoppers currently hold reservations}';

    protected $description = 'Load flash sale inventory from MySQL into Redis before the sale opens';

    public function handle(InventoryLockService $inventory): int
    {
        $sales = $this->option('all')
            ? FlashSale::query()->where('ends_at', '>', now())->get()
            : FlashSale::query()->findMany($this->argument('sale'));

        if ($sales->isEmpty()) {
            $this->components->error('No flash sales found. Pass sale IDs or use --all.');

            return self::FAILURE;
        }

        $rows = [];
        $skipped = 0;

        foreach ($sales as $sale) {
            try {
                $inventory->load($sale, (bool) $this->option('force'));
            } catch (RuntimeException $e) {
                $this->components->warn($e->getMessage().' Skipped; use --force to reset it.');
                $skipped++;

                continue;
            }

            $rows[] = [
                $sale->id,
                $sale->name,
                $sale->stock_remaining.' / '.$sale->stock_total,
                $sale->isLive() ? 'live' : ($sale->starts_at->isFuture() ? 'upcoming' : 'ended'),
            ];
        }

        if ($rows !== []) {
            $this->table(['Sale', 'Name', 'Stock loaded', 'Status'], $rows);
        }

        return $skipped > 0 ? self::FAILURE : self::SUCCESS;
    }
}
