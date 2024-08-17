<x-scenario id="abandoned-cart" title="Abandoned carts give stock back"
            tagline="Reserved is not sold. Units must return if the shopper walks away.">
    <x-slot:problem>
        <p>
            Holding a unit while the shopper pays is necessary, otherwise the item can sell out mid-checkout. But shoppers
            close the tab. If held units never return, the sale shows <em>sold out</em> while nobody actually bought anything.
        </p>
        <p>A cron job scanning the orders table every few minutes is far too slow for a sale that lasts 60 seconds.</p>
    </x-slot:problem>

    <x-slot:solution>
        <p>
            Each reservation is added to a <strong>sorted set scored by its expiry time</strong>.
            <span class="code-inline">flash-sale:reclaim-locks</span> runs <strong>every second</strong> and calls
            <span class="code-inline">reclaim.lua</span>, which removes everything with score ≤ now and returns the units to
            stock atomically, then broadcasts the change over the WebSocket.
        </p>
        <p>A late <em>Pay</em> on an expired reservation is refused, so a unit can never be both returned and sold.</p>
    </x-slot:solution>

    <x-slot:code>
        <x-code-block title="app/Support/Redis/LuaScripts/reclaim.lua" :code="$lua['reclaim']" />
    </x-slot:code>

    <div x-data="abandonedCartScenario" class="space-y-4">
        <div class="card flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-sm text-slate-800">2 units. Reservations expire after <strong>10 seconds</strong>.</p>
                <p class="mt-1 text-xs text-slate-500">Shoppers 171 and 172 reserve and leave. Shopper 173 arrives too late, then tries again.</p>
            </div>
            <button type="button" class="btn-primary" @click="run" :disabled="running" x-text="running ? 'Starting…' : (result ? 'Run again' : 'Run')"></button>
        </div>

        <template x-if="!result">
            <div class="card py-12 text-center text-sm text-slate-500">Run the scenario and watch the reservations expire live.</div>
        </template>

        <div x-show="result" x-cloak class="grid gap-4 md:grid-cols-2">
            <div class="card">
                <p class="label">The 2 units</p>
                <div class="mt-4 grid grid-cols-2 gap-3">
                    <template x-for="r in reservations" :key="r.token">
                        <div class="rounded-md border border-slate-200 p-4">
                            <p class="text-xs text-slate-500">Held by</p>
                            <p class="text-sm font-medium text-slate-900" x-text="'Shopper ' + String(r.user_id).padStart(3, '0')"></p>
                            <p class="mt-3 font-mono text-2xl tabular-nums" :class="secondsLeft(r) <= 3 ? 'text-red-700' : 'text-slate-900'" x-text="secondsLeft(r) + 's'"></p>
                            <div class="mt-2 h-1 overflow-hidden rounded-full bg-slate-100">
                                <div class="h-full bg-slate-800 transition-all duration-300" :style="`width: ${secondsLeft(r) / result.ttl * 100}%`"></div>
                            </div>
                        </div>
                    </template>
                    <template x-for="i in Math.max(0, available ?? 0)" :key="'free' + i">
                        <div class="grid place-items-center rounded-md border border-dashed border-emerald-300 bg-emerald-50 p-4 text-center">
                            <p class="text-sm font-medium text-emerald-700">Available</p>
                        </div>
                    </template>
                </div>
                <p class="mt-4 text-xs text-slate-500" x-show="available > 0">Returned by the reclaim tick and pushed over the WebSocket, with no page refresh.</p>
            </div>

            <div class="card">
                <p class="label" x-text="(result?.late_shopper ?? '') + ' tries to buy'"></p>
                <div class="mt-3 divide-y divide-slate-100 rounded-md border border-slate-200">
                    <template x-for="a in attempts" :key="a.label">
                        <div class="flex items-center justify-between px-3 py-2 text-sm">
                            <span class="text-slate-500" x-text="a.label"></span>
                            <span class="font-mono text-xs font-medium" :class="$store.styles.status[a.status] ?? 'text-slate-700'" x-text="a.status"></span>
                        </div>
                    </template>
                </div>
                <button type="button" class="btn-secondary mt-3 w-full" @click="tryAgain"
                        x-text="available > 0 ? 'Units are back: try again' : 'Try again (still held)'"></button>

                <p class="label mt-6">WebSocket events</p>
                <div class="mt-2 max-h-40 space-y-1.5 overflow-auto">
                    <template x-for="(e, i) in events" :key="e.timestamp + '-' + i">
                        <div class="flex items-start gap-2 text-xs">
                            <span class="pill shrink-0" :class="$store.styles.event[e.event_type]" x-text="e.event_type"></span>
                            <span class="text-slate-600" x-text="e.message ?? ('stock ' + e.available_stock)"></span>
                        </div>
                    </template>
                    <p x-show="events.length === 0" class="text-xs text-slate-400">Waiting for the reservations to expire…</p>
                </div>
            </div>
        </div>
    </div>
</x-scenario>
