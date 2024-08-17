<?php

namespace App\Services\Simulation;

use App\Enums\SaleStrategy;
use App\Models\FlashSale;
use App\Models\FlashSaleOrder;
use App\Models\Product;
use App\Services\FlashSale\FlashSaleRepository;
use App\Services\FlashSale\InventoryLockService;
use App\Support\Redis\FlashSaleKeys;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

/**
 * Drives the scenarios shown in the simulator.
 *
 * Every scenario sends real, concurrent HTTP requests through nginx to the
 * php-fpm pool, so the races are genuine, not simulated in a loop. Browsers
 * cap parallel connections per host (~6), which is why bursts run here.
 */
class SimulationRunner
{
    public function __construct(
        private readonly InventoryLockService $inventory,
        private readonly FlashSaleRepository $sales,
    ) {}

    /** Scenario 1: N shoppers race for S units under each strategy. */
    public function race(int $shoppers, int $stock): array
    {
        $results = [];

        foreach (SaleStrategy::cases() as $strategy) {
            $sale = $this->labSale("race-{$strategy->value}", $stock);
            $users = range(1, $shoppers);

            $started = hrtime(true);
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (int $user) => $this->request($pool, $user)->post($this->url("lab/flash-sales/{$sale->id}/purchase/{$strategy->value}")),
                $users,
            ));
            $wallMs = (hrtime(true) - $started) / 1e6;

            $responses = collect($responses);
            $accepted = $responses->filter(fn ($r) => $r instanceof Response && $r->json('status') === 'confirmed');

            if ($strategy === SaleStrategy::RedisLua) {
                $this->waitForOrders($sale, $accepted->count());
            }

            $sale->refresh();
            $unitsSold = (int) $sale->orders()->sum('quantity');
            $latencies = $this->latencies($responses);

            $results[] = [
                'strategy' => $strategy->value,
                'label' => $strategy->label(),
                'stock' => $stock,
                'shoppers' => $shoppers,
                'accepted' => $accepted->count(),
                'rejected' => $responses->filter(fn ($r) => $r instanceof Response && $r->json('status') === 'sold_out')->count(),
                'errors' => $responses->filter(fn ($r) => ! $r instanceof Response || $r->serverError())->count(),
                'orders_in_db' => $sale->orders()->count(),
                'units_sold' => $unitsSold,
                'oversold' => max(0, $unitsSold - $stock),
                'db_stock_remaining' => $sale->stock_remaining,
                'redis_stock' => $strategy === SaleStrategy::RedisLua ? $this->inventory->snapshot($sale->id)['available_stock'] : null,
                // What each accepted buyer was told was left after their purchase.
                // Duplicated values are the lost updates.
                'stock_seen_by_buyers' => $accepted->map(fn (Response $r) => (int) $r->json('available_stock'))
                    ->countBy()->sortKeysDesc()->map(fn ($count, $value) => ['stock_left' => $value, 'buyers' => $count])->values()->all(),
                'wall_ms' => round($wallMs),
                'p50_ms' => $this->percentile($latencies, 50),
                'p95_ms' => $this->percentile($latencies, 95),
                'max_ms' => $latencies === [] ? 0 : round(max($latencies)),
            ];
        }

        return [
            'shoppers' => $shoppers,
            'stock' => $stock,
            'processing_ms' => config('flash_sale.lab_processing_ms'),
            'results' => $results,
        ];
    }

    /** Scenario 2: the same request is sent three times at once, then retried. */
    public function doubleClick(): array
    {
        $sale = $this->labSale('double-click', 5);
        $user = 151;
        $key = 'checkout-'.Str::lower(Str::random(12));
        $path = $this->url("flash-sales/{$sale->id}/reservations");

        $burst = Http::pool(fn (Pool $pool) => array_map(
            fn () => $this->request($pool, $user, $key)->post($path),
            range(1, 3),
        ));
        $retry = $this->request(Http::getFacadeRoot(), $user, $key)->post($path);

        $buggyClient = Http::pool(fn (Pool $pool) => array_map(
            fn (int $i) => $this->request($pool, $user, "{$key}-new-{$i}")->post($path),
            range(1, 2),
        ));

        $attempts = [];
        foreach ($burst as $i => $response) {
            $attempts[] = $this->attempt($response, 'Triple click (same key, same moment)', 'Click '.($i + 1), $key);
        }
        $attempts[] = $this->attempt($retry, 'Network retry after the first answer (same key)', 'Retry', $key);
        foreach ($buggyClient as $i => $response) {
            $attempts[] = $this->attempt($response, 'Buggy client: a new key per click', 'Click '.($i + 1), "{$key}-new-".($i + 1));
        }

        return [
            'sale_id' => $sale->id,
            'shopper' => InventoryLockService::shopperName($user),
            'attempts' => $attempts,
            'reservations_held' => count(array_filter($this->inventory->reservations($sale->id), fn ($r) => $r['user_id'] === $user)),
            'stock' => ['total' => 5, 'available' => $this->inventory->snapshot($sale->id)['available_stock']],
        ];
    }

    /** Scenario 3: the same shopper reserves and pays from two tabs at once. */
    public function twoTabs(): array
    {
        $sale = $this->labSale('two-tabs', 5);
        $user = 161;
        $reservePath = $this->url("flash-sales/{$sale->id}/reservations");

        $reserves = Http::pool(fn (Pool $pool) => [
            $this->request($pool, $user, 'tab-a-'.Str::random(10))->post($reservePath),
            $this->request($pool, $user, 'tab-b-'.Str::random(10))->post($reservePath),
        ]);

        $token = collect($reserves)->map(fn ($r) => $r instanceof Response ? $r->json('reservation_token') : null)->filter()->first();
        $confirmPath = $this->url("flash-sales/{$sale->id}/reservations/{$token}/confirm");

        $confirms = Http::pool(fn (Pool $pool) => [
            $this->request($pool, $user, 'tab-a-pay-'.Str::random(10))->post($confirmPath),
            $this->request($pool, $user, 'tab-b-pay-'.Str::random(10))->post($confirmPath),
        ]);

        $this->waitForOrders($sale, 1);

        return [
            'sale_id' => $sale->id,
            'shopper' => InventoryLockService::shopperName($user),
            'attempts' => [
                $this->attempt($reserves[0], 'Both tabs press "Reserve"', 'Tab A'),
                $this->attempt($reserves[1], 'Both tabs press "Reserve"', 'Tab B'),
                $this->attempt($confirms[0], 'Both tabs press "Pay"', 'Tab A'),
                $this->attempt($confirms[1], 'Both tabs press "Pay"', 'Tab B'),
            ],
            'orders_in_db' => FlashSaleOrder::query()->where('flash_sale_id', $sale->id)->where('user_id', $user)->count(),
            'stock' => ['total' => 5, 'available' => $this->inventory->snapshot($sale->id)['available_stock']],
        ];
    }

    /** Scenario 4: two shoppers hold the last units and walk away. */
    public function abandonedCart(int $ttl = 10): array
    {
        $sale = $this->labSale('abandoned-cart', 2, $ttl);
        $path = $this->url("flash-sales/{$sale->id}/reservations");

        foreach ([171, 172] as $user) {
            $this->request(Http::getFacadeRoot(), $user, 'cart-'.Str::random(12))->post($path);
        }

        $late = $this->request(Http::getFacadeRoot(), 173, 'cart-'.Str::random(12))->post($path);

        return [
            'sale_id' => $sale->id,
            'ttl' => $ttl,
            'late_shopper_id' => 173,
            'late_shopper' => InventoryLockService::shopperName(173),
            'late_attempt' => $this->attempt($late, 'Shopper 173 tries to buy', 'Attempt 1'),
            'reservations' => $this->inventory->reservations($sale->id),
            'snapshot' => $this->inventory->snapshot($sale->id),
            'server_time' => now()->timestamp,
        ];
    }

    /** Scenario 5, step 1: pause the consumer, then buy. */
    public function outboxStart(): array
    {
        $sale = $this->labSale('outbox', 3);
        Redis::setex(FlashSaleKeys::CONSUMER_PAUSED, 120, 1);

        $user = 181;
        $reserve = $this->request(Http::getFacadeRoot(), $user, 'outbox-'.Str::random(12))
            ->post($this->url("flash-sales/{$sale->id}/reservations"));
        $confirm = $this->request(Http::getFacadeRoot(), $user, 'outbox-pay-'.Str::random(12))
            ->post($this->url("flash-sales/{$sale->id}/reservations/{$reserve->json('reservation_token')}/confirm"));

        return [
            'sale_id' => $sale->id,
            'shopper' => InventoryLockService::shopperName($user),
            'confirm' => $this->attempt($confirm, 'Shopper 181 pays', 'Confirm'),
            'state' => $this->outboxState($sale->id),
        ];
    }

    /** Scenario 5, step 2: bring the consumer back. */
    public function outboxResume(int $saleId): array
    {
        Redis::del(FlashSaleKeys::CONSUMER_PAUSED);

        return $this->outboxState($saleId);
    }

    public function outboxState(int $saleId): array
    {
        $stream = FlashSaleKeys::for($saleId)->orders();

        return [
            'consumer_paused' => (bool) Redis::exists(FlashSaleKeys::CONSUMER_PAUSED),
            'stream_entries' => collect(Redis::xrange($stream, '-', '+') ?: [])
                ->map(fn (array $fields, string $id) => ['id' => $id] + $fields)->values()->all(),
            'db_orders' => FlashSaleOrder::query()->where('flash_sale_id', $saleId)
                ->get(['id', 'user_id', 'reservation_token', 'quantity', 'confirmed_at'])->all(),
            'available_stock' => $this->inventory->snapshot($saleId)['available_stock'],
        ];
    }

    /** Live panel: N random shoppers try to reserve a real sale at once. */
    public function burst(FlashSale $sale, int $shoppers): array
    {
        $users = collect(range(1, 200))->shuffle()->take($shoppers)->values();
        $path = $this->url("flash-sales/{$sale->id}/reservations");

        $responses = collect(Http::pool(fn (Pool $pool) => $users->map(
            fn (int $user) => $this->request($pool, $user, 'burst-'.Str::random(16))->post($path),
        )->all()));

        return [
            'sent' => $shoppers,
            'outcomes' => $responses->map(fn ($r) => $r instanceof Response ? ($r->json('status') ?? 'error') : 'error')->countBy()->all(),
            'snapshot' => $this->inventory->snapshot($sale->id),
        ];
    }

    /** Live panel: put a real sale back to its initial state. */
    public function reset(FlashSale $sale): array
    {
        $sale->orders()->delete();
        $sale->update(['stock_remaining' => $sale->stock_total]);
        $this->sales->forget($sale->id);
        Redis::del(FlashSaleKeys::for($sale->id)->orders());
        $this->inventory->load($sale, force: true);

        return $this->inventory->snapshot($sale->id);
    }

    private function labSale(string $scenario, int $stock, int $ttl = 300): FlashSale
    {
        $product = Product::query()->firstOrCreate(['slug' => 'lab-ps5'], [
            'name' => 'PlayStation 5 Slim (lab)',
            'description' => 'Isolated product used by the simulator.',
            'price_cents' => 49_999,
        ]);

        FlashSale::query()
            ->where('product_id', $product->id)
            ->where('name', "Lab: {$scenario}")
            ->each(function (FlashSale $old) {
                $this->inventory->forget($old->id);
                $this->sales->forget($old->id);
                $old->delete();
            });

        $sale = FlashSale::query()->create([
            'product_id' => $product->id,
            'name' => "Lab: {$scenario}",
            'sale_price_cents' => 29_999,
            'stock_total' => $stock,
            'stock_remaining' => $stock,
            'max_per_user' => 1,
            'reservation_ttl_seconds' => $ttl,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
        ]);

        $this->inventory->load($sale);

        return $sale;
    }

    private function request(object $factory, int $user, ?string $idempotencyKey = null)
    {
        return $factory->acceptJson()->timeout(60)->withHeaders(array_filter([
            'X-User-Id' => $user,
            'X-Idempotency-Key' => $idempotencyKey,
        ]));
    }

    private function url(string $path): string
    {
        return rtrim(config('flash_sale.simulation.base_url'), '/').'/api/'.$path;
    }

    private function attempt(mixed $response, string $phase, string $label, ?string $key = null): array
    {
        if (! $response instanceof Response) {
            return ['phase' => $phase, 'label' => $label, 'http' => 0, 'status' => 'error', 'message' => $response instanceof Throwable ? $response->getMessage() : 'No response'];
        }

        return [
            'phase' => $phase,
            'label' => $label,
            'idempotency_key' => $key,
            'http' => $response->status(),
            'status' => $response->json('status'),
            'message' => $response->json('message'),
            'replayed' => $response->header('Idempotent-Replayed') === 'true',
            'token' => $response->json('reservation_token'),
            'ms' => round(($response->handlerStats()['total_time'] ?? 0) * 1000),
        ];
    }

    /** @return list<float> */
    private function latencies(Collection $responses): array
    {
        return $responses
            ->filter(fn ($r) => $r instanceof Response)
            ->map(fn (Response $r) => ($r->handlerStats()['total_time'] ?? 0) * 1000)
            ->sort()
            ->values()
            ->all();
    }

    private function percentile(array $sorted, int $percentile): float
    {
        if ($sorted === []) {
            return 0;
        }

        return round($sorted[(int) ceil($percentile / 100 * count($sorted)) - 1]);
    }

    private function waitForOrders(FlashSale $sale, int $expected, float $timeoutSeconds = 5): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while ($sale->orders()->count() < $expected && microtime(true) < $deadline) {
            usleep(100_000);
        }
    }
}
