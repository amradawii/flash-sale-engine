<?php

namespace App\Console\Commands;

use App\Models\FlashSale;
use App\Support\Redis\FlashSaleKeys;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class WarmupFlashSaleCommand extends Command
{
    protected $signature = 'flash-sale:warmup
                            {sale?* : IDs of the flash sales to load into Redis}
                            {--all : Load every sale that has not ended yet}
                            {--force : Reset a sale even if shoppers currently hold reservations}';

    protected $description = 'Load flash sale inventory from MySQL into Redis before the sale opens';

    public function handle(): int
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
            $keys = FlashSaleKeys::for($sale->id);
            $activeHolds = (int) Redis::hlen($keys->holds());

            if ($activeHolds > 0 && ! $this->option('force')) {
                $this->components->warn(
                    "Sale #{$sale->id} has {$activeHolds} active reservation(s); skipped. Use --force to reset it."
                );
                $skipped++;

                continue;
            }

            // The orders stream is never cleared: it may hold confirmed orders
            // that the consumer has not persisted yet.
            Redis::transaction(function ($redis) use ($keys, $sale) {
                $redis->del($keys->stock(), $keys->holds(), $keys->reservations(), $keys->expirations());
                $redis->set($keys->stock(), $sale->stock_remaining);
                $redis->sadd(FlashSaleKeys::ACTIVE_SALES, $sale->id);
            });

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
