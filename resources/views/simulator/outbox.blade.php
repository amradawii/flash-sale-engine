@php
$outboxSnippet = <<<'LUA'
-- confirm.lua (end): one atomic step
redis.call('HDEL', KEYS[2], user_id)        -- reservation gone
redis.call('HDEL', KEYS[3], token)
redis.call('ZREM', KEYS[4], token)          -- will not be reclaimed
redis.call('HINCRBY', KEYS[5], user_id, quantity)

redis.call('XADD', KEYS[6], '*',            -- order written to the outbox stream
    'sale_id', ARGV[3], 'user_id', user_id,
    'token', token, 'quantity', quantity, 'confirmed_at', now)
LUA;

$consumerSnippet = <<<'PHP'
// flash-sale:consume-orders
// 1. entries delivered before a crash but never acknowledged ("0")
// 2. then new entries (">")
$batch = $redis->xReadGroup($group, $consumer, [$stream => '0']);
...
$persister->persist($fields);          // unique reservation_token: replay-safe
$redis->xAck($stream, $group, [$id]);  // only after MySQL committed
PHP;
@endphp

<x-scenario id="outbox" title="A crash right after payment"
            tagline="Redis said “confirmed”, then the server died. Is the order lost?">
    <x-slot:problem>
        <p>
            A common design runs the atomic Redis step, <em>then</em> pushes a job to save the order in MySQL.
            If PHP crashes between those two lines, the stock is gone from Redis but no order exists anywhere:
            the customer paid for nothing.
        </p>
    </x-slot:problem>

    <x-slot:solution>
        <p>
            <span class="code-inline">confirm.lua</span> writes the order into a <strong>Redis Stream (an outbox) in the same
            atomic step</strong> that removes the reservation. Either both happen or neither does.
        </p>
        <p>
            A separate worker reads the stream through a <strong>consumer group</strong>, saves to MySQL and only then
            acknowledges. If the worker dies midway, the entry stays <em>pending</em> and is delivered again on restart;
            the unique reservation token turns the second insert into a no-op.
        </p>
    </x-slot:solution>

    <x-slot:code>
        <x-code-block title="confirm.lua (outbox write)" :code="$outboxSnippet" />
        <x-code-block title="ConsumeConfirmedOrdersCommand.php (excerpt)" :code="$consumerSnippet" />
    </x-slot:code>

    <div x-data="outboxScenario" class="space-y-4">
        <div class="card">
            <ol class="space-y-4 text-sm">
                <li class="flex items-start gap-3">
                    <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full border text-xs font-medium"
                          :class="step >= 1 ? 'border-navy-600 bg-navy-600 text-white' : 'border-slate-300 text-slate-500'">1</span>
                    <div class="flex-1">
                        <p class="text-slate-800">Stop the order worker (as if it crashed). Shopper 181 reserves and pays.</p>
                        <button type="button" class="btn-primary mt-2" @click="start">Stop worker and pay</button>
                    </div>
                </li>
                <li class="flex items-start gap-3" :class="step >= 1 ? '' : 'opacity-40'">
                    <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full border text-xs font-medium"
                          :class="step >= 2 ? 'border-navy-600 bg-navy-600 text-white' : 'border-slate-300 text-slate-500'">2</span>
                    <div class="flex-1">
                        <p class="text-slate-800">Nothing is in MySQL, yet nothing is lost: the order waits in the stream. Bring the worker back.</p>
                        <button type="button" class="btn-secondary mt-2" @click="resume" :disabled="step !== 1">Restart worker</button>
                    </div>
                </li>
                <li class="flex items-start gap-3" :class="step >= 2 ? '' : 'opacity-40'">
                    <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full border text-xs font-medium"
                          :class="step >= 3 ? 'border-navy-600 bg-navy-600 text-white' : 'border-slate-300 text-slate-500'">3</span>
                    <p class="flex-1 text-slate-800">The worker picks up the pending entry, saves it and acknowledges it.</p>
                </li>
            </ol>
        </div>

        <div class="grid gap-4 md:grid-cols-3" x-show="state" x-cloak>
            <div class="card">
                <p class="label">Redis stream (outbox)</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-slate-900" x-text="state?.stream_entries.length"></p>
                <template x-for="e in state?.stream_entries ?? []" :key="e.id">
                    <p class="mt-2 truncate font-mono text-[11px] text-slate-500" x-text="e.id + ' · user ' + e.user_id + ' × ' + e.quantity"></p>
                </template>
            </div>
            <div class="card">
                <p class="label">Order worker</p>
                <p class="mt-2 text-base font-medium" :class="state?.consumer_paused ? 'text-red-700' : 'text-emerald-700'"
                   x-text="state?.consumer_paused ? 'Stopped' : 'Running'"></p>
                <p class="mt-2 text-xs text-slate-500" x-text="state?.consumer_paused ? 'Simulated crash: entries stay pending.' : 'Reads pending entries first.'"></p>
            </div>
            <div class="card">
                <p class="label">MySQL orders</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-slate-900" x-text="state?.db_orders.length"></p>
                <template x-for="o in state?.db_orders ?? []" :key="o.id">
                    <p class="mt-2 truncate font-mono text-[11px] text-slate-500" x-text="'#' + o.id + ' · ' + o.reservation_token.slice(0, 8) + '…'"></p>
                </template>
            </div>
        </div>

        <p x-show="step === 3" x-cloak class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            The order survived the crash. Payment and order record were written together, and the worker delivered the order
            to MySQL as soon as it came back.
        </p>
    </div>
</x-scenario>
