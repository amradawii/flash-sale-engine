<?php

namespace App\Console\Commands;

use App\Enums\InventoryEventType;
use App\Services\FlashSale\InventoryBroadcaster;
use App\Services\FlashSale\InventoryLockService;
use App\Services\FlashSale\OrderPersister;
use App\Support\Redis\FlashSaleKeys;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Throwable;

class ConsumeConfirmedOrdersCommand extends Command
{
    protected $signature = 'flash-sale:consume-orders
                            {--consumer=persister-1 : Stable consumer name, so pending entries survive restarts}
                            {--once : Process what is available, then exit}
                            {--block=2000 : Milliseconds to wait for new orders}';

    protected $description = 'Persist confirmed orders from the Redis Stream outbox to MySQL';

    private bool $running = true;

    /** @var array<string, true> */
    private array $groups = [];

    public function handle(InventoryLockService $inventory, OrderPersister $persister, InventoryBroadcaster $broadcaster): int
    {
        $this->trap([SIGTERM, SIGINT], fn () => $this->running = false);

        /** @var \Redis $client */
        $client = Redis::connection()->client();
        $prefix = (string) $client->getOption(\Redis::OPT_PREFIX);
        $group = config('flash_sale.consumer.group');
        $consumer = (string) $this->option('consumer');

        $this->components->info("Consuming confirmed orders as [{$group}/{$consumer}].");

        while ($this->running) {
            if ($this->paused()) {
                usleep(500_000);

                continue;
            }

            $streams = $this->streams($inventory, $client, $group);

            if ($streams === []) {
                if ($this->option('once')) {
                    break;
                }
                sleep(1);

                continue;
            }

            // Entries delivered before a crash but never acknowledged come
            // first ("0"); only then wait for new ones (">").
            $batch = $client->xReadGroup($group, $consumer, array_fill_keys($streams, '0'), config('flash_sale.consumer.batch'));

            if ($batch === false) {
                // NOGROUP: a stream was deleted (sale reset); recreate groups next pass.
                $client->clearLastError();
                $this->groups = [];
                usleep(200_000);

                continue;
            }

            if (! $this->hasEntries($batch)) {
                $batch = $client->xReadGroup(
                    $group,
                    $consumer,
                    array_fill_keys($streams, '>'),
                    config('flash_sale.consumer.batch'),
                    $this->option('once') ? null : (int) $this->option('block'),
                );
            }

            if (! $this->hasEntries($batch)) {
                if ($this->option('once')) {
                    break;
                }

                continue;
            }

            // Paused while blocked: leave the entries pending, exactly as a
            // crashed worker would; the "0" read picks them up on resume.
            if ($this->paused()) {
                continue;
            }

            foreach ($batch as $stream => $entries) {
                $stream = str_starts_with($stream, $prefix) ? substr($stream, strlen($prefix)) : $stream;

                foreach ($entries as $id => $fields) {
                    try {
                        $created = $persister->persist($fields);
                    } catch (Throwable $e) {
                        report($e);
                        $this->components->error("Order {$id} failed, will retry: {$e->getMessage()}");
                        sleep(1);

                        continue 3;
                    }

                    $client->xAck($stream, $group, [$id]);
                    $client->xDel($stream, [$id]);

                    $this->line(sprintf(
                        '  %s sale #%s user #%s ×%s %s',
                        $id, $fields['sale_id'], $fields['user_id'], $fields['quantity'],
                        $created ? '<info>persisted</info>' : '<comment>skipped (duplicate or deleted sale)</comment>',
                    ));

                    if ($created) {
                        $saleId = (int) $fields['sale_id'];
                        $broadcaster->publish(
                            $saleId,
                            InventoryEventType::Persisted,
                            fn () => $inventory->snapshot($saleId),
                            sprintf('Order of %s saved to MySQL.', InventoryLockService::shopperName((int) $fields['user_id'])),
                            (int) $fields['user_id'],
                        );
                    }
                }
            }
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function streams(InventoryLockService $inventory, \Redis $client, string $group): array
    {
        $streams = [];

        foreach ($inventory->activeSaleIds() as $saleId) {
            $stream = FlashSaleKeys::for($saleId)->orders();

            if (! isset($this->groups[$stream])) {
                // MKSTREAM creates the stream if needed; BUSYGROUP means it already exists.
                if (! $client->xGroup('CREATE', $stream, $group, '0', true)) {
                    $client->clearLastError();
                }
                $this->groups[$stream] = true;
            }

            $streams[] = $stream;
        }

        return $streams;
    }

    private function paused(): bool
    {
        return (bool) Redis::exists(FlashSaleKeys::CONSUMER_PAUSED);
    }

    private function hasEntries(mixed $batch): bool
    {
        if (! is_array($batch)) {
            return false;
        }

        foreach ($batch as $entries) {
            if ($entries !== []) {
                return true;
            }
        }

        return false;
    }
}
