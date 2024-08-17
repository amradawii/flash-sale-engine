<x-scenario id="two-tabs" title="Same shopper, two tabs"
            tagline="Different requests, different idempotency keys, one person.">
    <x-slot:problem>
        <p>
            A shopper opens the sale in two tabs (or on a phone and a laptop) and presses <em>Reserve</em> in both, then
            <em>Pay</em> in both. The requests are genuinely different, so idempotency keys do not help. A per-user limit
            checked with a normal <span class="code-inline">SELECT</span> has the same race as the stock check.
        </p>
    </x-slot:problem>

    <x-slot:solution>
        <p>
            The per-shopper rule lives <strong>inside the same atomic script</strong> as the stock decrement:
            <span class="code-inline">reserve.lua</span> refuses when the <span class="code-inline">holds</span> hash already contains the shopper.
        </p>
        <p>
            <span class="code-inline">confirm.lua</span> deletes the reservation as it confirms it, so the second <em>Pay</em>
            finds nothing to confirm. One order, one charge.
        </p>
    </x-slot:solution>

    <x-slot:code>
        <x-code-block title="app/Support/Redis/LuaScripts/confirm.lua" :code="$lua['confirm']" />
    </x-slot:code>

    <div x-data="twoTabsScenario" class="space-y-4">
        <div class="card flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-sm text-slate-800">Shopper 161 reserves from two tabs at once, then pays from both at once.</p>
                <p class="mt-1 text-xs text-slate-500">Every request uses its own idempotency key.</p>
            </div>
            <button type="button" class="btn-primary" @click="run" :disabled="running" x-text="running ? 'Running…' : 'Run'"></button>
        </div>

        <div x-show="result" x-cloak class="grid grid-cols-2 gap-4">
            <div class="card">
                <p class="label">Orders saved for the shopper</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-slate-900" x-text="result?.orders_in_db"></p>
            </div>
            <div class="card">
                <p class="label">Stock left</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-slate-900"><span x-text="result?.stock.available"></span><span class="text-base font-normal text-slate-400"> / <span x-text="result?.stock.total"></span></span></p>
            </div>
        </div>

        @include('simulator.partials.attempts')
    </div>
</x-scenario>
