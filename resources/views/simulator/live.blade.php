<section id="live" x-data="liveSale">
    <div class="flex flex-wrap items-end justify-between gap-6">
        <div>
            <h2 class="text-lg font-semibold text-slate-900">Run a sale yourself</h2>
            <p class="mt-1 max-w-2xl text-sm text-slate-500">
                Act as any of the 200 seeded shoppers, or send a burst. Open this page in a second window:
                both update in real time through Laravel Reverb.
            </p>
        </div>
        <div class="flex flex-wrap rounded-lg bg-slate-200/60 p-1">
            <template x-for="s in sales" :key="s.id">
                <button type="button" @click="select(s.id)" class="rounded-md px-3 py-1.5 text-sm font-medium"
                        :class="s.id === selectedId ? 'bg-white text-navy-700 shadow-sm' : 'text-slate-600 hover:text-slate-900'"
                        x-text="s.product.name"></button>
            </template>
        </div>
    </div>

    <div class="mt-6 grid gap-4 lg:grid-cols-3" x-show="sale" x-cloak>
        {{-- Stock & controls --}}
        <div class="card space-y-5">
            <div class="flex items-center gap-5">
                <div class="relative h-24 w-24 shrink-0">
                    <svg viewBox="0 0 36 36" class="h-24 w-24 -rotate-90">
                        <circle cx="18" cy="18" r="15.9" fill="none" stroke-width="2.5" class="stroke-slate-200"/>
                        <circle cx="18" cy="18" r="15.9" fill="none" stroke-width="2.5" stroke-linecap="round"
                                class="transition-all duration-500" :opacity="percent === 0 ? 0 : 1"
                                :class="percent < 30 ? 'stroke-amber-500' : 'stroke-navy-600'"
                                :stroke-dasharray="`${percent} 100`"/>
                    </svg>
                    <div class="absolute inset-0 grid place-items-center text-center">
                        <div>
                            <p class="text-2xl font-semibold tabular-nums text-slate-900" x-text="sale?.redis.available_stock"></p>
                            <p class="text-[10px] uppercase tracking-wide text-slate-500" x-text="'of ' + sale?.stock_total"></p>
                        </div>
                    </div>
                </div>
                <div class="min-w-0">
                    <p class="font-medium text-slate-900" x-text="sale?.product.name"></p>
                    <p class="mt-1 text-xs text-slate-500" x-text="sale?.product.description"></p>
                    <p class="mt-2 text-sm">
                        <span class="font-medium text-slate-900" x-text="sale && money(sale.sale_price_cents)"></span>
                        <span class="text-slate-400 line-through" x-text="sale && money(sale.product.price_cents)"></span>
                    </p>
                    <p class="mt-1 text-xs text-slate-500" x-text="sale && ('Max ' + sale.max_per_user + ' per shopper · hold ' + formatTime(sale.reservation_ttl_seconds))"></p>
                </div>
            </div>

            <dl class="grid grid-cols-3 divide-x divide-slate-200 rounded-md border border-slate-200 text-center">
                <div class="p-2"><dt class="text-[10px] uppercase tracking-wide text-slate-500">Held</dt><dd class="font-mono text-lg tabular-nums" x-text="sale?.redis.active_reservations"></dd></div>
                <div class="p-2"><dt class="text-[10px] uppercase tracking-wide text-slate-500">In outbox</dt><dd class="font-mono text-lg tabular-nums" x-text="sale?.redis.pending_orders"></dd></div>
                <div class="p-2"><dt class="text-[10px] uppercase tracking-wide text-slate-500">Sold</dt><dd class="font-mono text-lg tabular-nums" x-text="sale?.database.units_sold"></dd></div>
            </dl>

            <div class="space-y-3">
                <label class="block">
                    <span class="label">Act as shopper</span>
                    <div class="mt-1 flex gap-2">
                        <input type="number" min="1" max="200" x-model.number="shopper" @change="token = null" class="input">
                        <button type="button" class="btn-secondary" @click="shopper = Math.ceil(Math.random() * 200); token = null">Random</button>
                    </div>
                </label>
                <div class="grid grid-cols-3 gap-2">
                    <button type="button" class="btn-primary" @click="reserve" :disabled="busy">Reserve</button>
                    <button type="button" class="btn-secondary" @click="confirm" :disabled="busy || !token">Pay</button>
                    <button type="button" class="btn-secondary" @click="release" :disabled="busy || !token">Cancel</button>
                </div>
                <p class="text-xs text-slate-500" x-show="token" x-text="'Holding reservation ' + token?.slice(0, 8) + '…'"></p>
            </div>

            <div class="rounded-md border border-slate-200 bg-slate-50 p-3 font-mono text-xs" x-show="lastResult">
                <p><span class="text-slate-500">HTTP</span> <span x-text="lastResult?.http"></span>
                   <span class="ml-2 font-medium" :class="$store.styles.status[lastResult?.status] ?? 'text-slate-700'" x-text="lastResult?.status"></span></p>
                <p class="mt-1 text-slate-500" x-text="lastResult?.message"></p>
                <template x-if="lastResult?.outcomes">
                    <p class="mt-1 text-slate-700" x-text="Object.entries(lastResult.outcomes).map(([k, v]) => v + ' ' + k).join(' · ')"></p>
                </template>
            </div>

            <div class="flex items-end gap-2 border-t border-slate-200 pt-5">
                <label class="block flex-1">
                    <span class="label">Burst: <span class="text-slate-900" x-text="burstSize"></span> shoppers at once</span>
                    <input type="range" min="5" max="100" step="5" x-model="burstSize" class="mt-3 w-full accent-navy-600">
                </label>
                <button type="button" class="btn-primary" @click="burst" :disabled="busy">Burst</button>
            </div>
            <button type="button" class="w-full text-xs text-slate-500 hover:text-red-700" @click="reset" :disabled="busy">Reset this sale (delete orders, restore stock)</button>
        </div>

        {{-- Active reservations --}}
        <div class="card p-0">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
                <p class="label">Active reservations</p>
                <span class="font-mono text-xs text-slate-400" x-text="(sale?.reservations.length ?? 0) + ' shown'"></span>
            </div>
            <div class="max-h-[30rem] divide-y divide-slate-100 overflow-auto">
                <template x-for="r in sale?.reservations ?? []" :key="r.token">
                    <div class="flex items-center justify-between px-5 py-2.5" :class="r.user_id === shopper ? 'bg-slate-50' : ''">
                        <div>
                            <p class="text-sm text-slate-900" x-text="shopperName(r.user_id)"></p>
                            <p class="font-mono text-[11px] text-slate-400" x-text="r.token.slice(0, 8) + '… × ' + r.quantity"></p>
                        </div>
                        <span class="font-mono text-sm tabular-nums" :class="secondsLeft(r) < 30 ? 'text-red-700' : 'text-slate-700'" x-text="formatTime(secondsLeft(r))"></span>
                    </div>
                </template>
                <p x-show="!(sale?.reservations.length)" class="py-12 text-center text-sm text-slate-400">No one is holding stock right now.</p>
            </div>
        </div>

        {{-- Event feed --}}
        <div class="card p-0">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
                <p class="label">WebSocket events</p>
                <span class="font-mono text-xs text-slate-400" x-text="'flash-sale.' + selectedId"></span>
            </div>
            <div class="max-h-[30rem] divide-y divide-slate-100 overflow-auto">
                <template x-for="e in events" :key="e.key">
                    <div class="px-5 py-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <span class="pill" :class="$store.styles.event[e.event_type]" x-text="e.event_type"></span>
                            <span class="font-mono text-[11px] text-slate-400" x-text="time(e.timestamp)"></span>
                        </div>
                        <p class="mt-1 text-xs text-slate-700" x-text="e.message ?? 'State synchronised.'"></p>
                        <p class="mt-0.5 font-mono text-[11px] text-slate-400" x-text="'stock ' + e.available_stock + ' · held ' + e.active_reservations + ' · outbox ' + e.pending_orders"></p>
                    </div>
                </template>
                <p x-show="events.length === 0" class="py-12 text-center text-sm text-slate-400">Waiting for activity. Press Reserve or Burst.</p>
            </div>
            <p class="border-t border-slate-200 px-5 py-3 text-[11px] leading-relaxed text-slate-500">
                During bursts, frequent events are throttled to one per 200 ms per sale; the 1-second reclaim tick always sends the final state.
            </p>
        </div>
    </div>
</section>
