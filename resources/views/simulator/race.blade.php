@php
$naiveSnippet = <<<'PHP'
$stock = FlashSale::whereKey($id)->value('stock_remaining');   // 1. read

if ($stock < $quantity) {
    return SoldOut;
}

$this->processOrder();          // 2. slow work: other requests read the same $stock meanwhile

FlashSale::whereKey($id)->update([
    'stock_remaining' => $stock - $quantity,   // 3. overwrite with a stale value
]);
FlashSaleOrder::create([...]);                 // the order is created anyway
PHP;

$dbLockSnippet = <<<'PHP'
DB::transaction(function () {
    $sale = FlashSale::lockForUpdate()->find($id);   // every buyer queues here
    if ($sale->stock_remaining < $quantity) return SoldOut;

    $this->processOrder();                           // lock held during the slow work
    $sale->decrement('stock_remaining', $quantity);
    FlashSaleOrder::create([...]);
});
PHP;
@endphp

<x-scenario id="race" title="Overselling under a traffic spike"
            tagline="Many shoppers, few units, the same second. Three implementations under identical load.">
    <x-slot:problem>
        <p>
            The shop reads the stock, checks it, does some work (charging the card, a fraud check: simulated here as
            <strong>{{ $processingMs }} ms</strong>) and writes the new stock. While request A is busy, request B reads the
            <em>same old value</em>, passes the same check and overwrites A's update.
        </p>
        <p>This is a <strong>lost update</strong>: the stock column looks fine, but there are more orders than units.</p>
        <p>A row lock fixes correctness, but buyers then wait in a single line for the lock, each one holding a database connection.</p>
    </x-slot:problem>

    <x-slot:solution>
        <p>
            <span class="code-inline">reserve.lua</span> checks and decrements the stock in <strong>one atomic step inside Redis</strong>.
            Redis runs one script at a time, so no request can slip in between the check and the write, and it takes microseconds,
            so nobody waits in line.
        </p>
        <p>The slow work happens <em>after</em> the unit is held, outside any lock. Then <span class="code-inline">confirm.lua</span> records the order.</p>
    </x-slot:solution>

    <x-slot:code>
        <x-code-block title="NaiveStrategy.php (deliberately broken)" :code="$naiveSnippet" />
        <x-code-block title="DatabaseLockStrategy.php" :code="$dbLockSnippet" />
        <x-code-block title="app/Support/Redis/LuaScripts/reserve.lua" :code="$lua['reserve']" />
    </x-slot:code>

    <div x-data="raceScenario" class="space-y-4">
        <div class="card">
            <div class="grid gap-6 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                <label class="block">
                    <span class="label">Shoppers at once: <span class="text-slate-900" x-text="shoppers"></span></span>
                    <input type="range" min="10" max="200" step="10" x-model="shoppers" class="mt-3 w-full accent-navy-600">
                </label>
                <label class="block">
                    <span class="label">Units in stock: <span class="text-slate-900" x-text="stock"></span></span>
                    <input type="range" min="1" max="50" x-model="stock" class="mt-3 w-full accent-navy-600">
                </label>
                <button type="button" class="btn-primary" @click="run" :disabled="running" x-text="running ? 'Running…' : 'Run'"></button>
            </div>
            <p class="mt-4 text-xs text-slate-500">
                Each strategy gets a fresh, isolated sale and the same burst of <span x-text="shoppers"></span> simultaneous requests.
            </p>
            <p x-show="error" x-text="error" class="mt-3 text-sm text-red-700"></p>
        </div>

        <template x-if="!result && !running">
            <div class="card py-16 text-center text-sm text-slate-500">
                Press <strong class="text-slate-800">Run</strong> to send real concurrent requests to all three implementations.
            </div>
        </template>

        <div class="grid gap-4 md:grid-cols-3" x-show="result" x-cloak>
            <template x-for="r in result?.results ?? []" :key="r.strategy">
                <div class="card flex flex-col">
                    <h4 class="text-sm font-medium text-slate-900" x-text="r.label"></h4>
                    <span class="mt-1 text-xs font-semibold" :class="verdict(r).cls" x-text="verdict(r).text"></span>

                    <p class="mt-5 text-3xl font-semibold tabular-nums text-slate-900">
                        <span x-text="r.units_sold"></span><span class="text-base font-normal text-slate-400"> / <span x-text="r.stock"></span></span>
                    </p>
                    <p class="text-xs text-slate-500">units sold / units that exist</p>

                    <div class="mt-4 flex flex-wrap gap-[3px]">
                        <template x-for="i in Math.max(r.stock, r.units_sold)" :key="i">
                            <span class="h-2.5 w-2.5 rounded-[2px]"
                                  :class="i > r.stock ? 'bg-red-600' : (i <= r.units_sold ? accents[r.strategy].bar : 'border border-slate-300')"
                                  :title="i > r.stock ? 'Sold but does not exist' : (i <= r.units_sold ? 'Sold' : 'Unsold')"></span>
                        </template>
                    </div>
                    <p class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-slate-500">
                        <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-[2px]" :class="accents[r.strategy].bar"></span>real unit</span>
                        <span class="inline-flex items-center gap-1" x-show="r.oversold > 0"><span class="h-2 w-2 rounded-[2px] bg-red-600"></span>does not exist</span>
                        <span class="inline-flex items-center gap-1" x-show="r.units_sold < r.stock"><span class="h-2 w-2 rounded-[2px] border border-slate-300"></span>unsold</span>
                    </p>

                    <dl class="mt-5 divide-y divide-slate-100 border-t border-slate-200 text-sm">
                        <div class="flex justify-between py-1.5"><dt class="text-slate-500">Told "you got it"</dt><dd class="font-mono tabular-nums" x-text="r.accepted"></dd></div>
                        <div class="flex justify-between py-1.5"><dt class="text-slate-500">Told "sold out"</dt><dd class="font-mono tabular-nums" x-text="r.rejected"></dd></div>
                        <div class="flex justify-between py-1.5"><dt class="text-slate-500">Orders in MySQL</dt><dd class="font-mono tabular-nums" x-text="r.orders_in_db"></dd></div>
                        <div class="flex justify-between py-1.5"><dt class="text-slate-500">Oversold units</dt><dd class="font-mono tabular-nums" :class="r.oversold ? 'font-semibold text-red-700' : ''" x-text="r.oversold"></dd></div>
                        <div class="flex justify-between py-1.5"><dt class="text-slate-500">Whole burst</dt><dd class="font-mono tabular-nums" x-text="r.wall_ms + ' ms'"></dd></div>
                        <div class="flex justify-between py-1.5"><dt class="text-slate-500">p50 · p95</dt><dd class="font-mono tabular-nums" x-text="r.p50_ms + ' · ' + r.p95_ms + ' ms'"></dd></div>
                    </dl>

                    <div class="mt-4 border-t border-slate-200 pt-4" x-show="r.oversold > 0">
                        <p class="text-xs font-medium text-slate-900">Stock each buyer was told was left</p>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <template x-for="s in r.stock_seen_by_buyers" :key="s.stock_left">
                                <span class="pill border-red-200 bg-red-50 text-red-700"
                                      x-text="s.buyers + ' × &quot;' + s.stock_left + ' left&quot;'"></span>
                            </template>
                        </div>
                        <p class="mt-2 text-xs text-slate-500">Many buyers were told the same number: they all read the same stale stock.</p>
                    </div>
                </div>
            </template>
        </div>

        <div class="card text-sm leading-relaxed text-slate-700" x-show="result" x-cloak>
            <p class="font-medium text-slate-900">Reading the result</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                <li><strong>Naive</strong> accepted far more buyers than there are units. Every one of them was charged.</li>
                <li><strong>Row lock</strong> is correct, but buyers are served one by one while the lock is held for {{ $processingMs }} ms of work. Raise the stock and its time grows linearly.</li>
                <li><strong>Redis Lua</strong> is correct and does the same {{ $processingMs }} ms of work for every buyer in parallel, because the lock lasts only one script execution.</li>
            </ul>
            <p class="mt-3 text-xs text-slate-500">Timings come from your machine through Docker. Compare them with each other, not as absolute benchmarks.</p>
        </div>
    </div>
</x-scenario>
