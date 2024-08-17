@php
$idempotencySnippet = <<<'PHP'
// IdempotencyMiddleware: the first request claims the key atomically
$claimed = Redis::set($storageKey, $processing, 'EX', 60, 'NX');

if (! $claimed) {
    // still running  -> 409 request_in_progress
    // finished       -> replay the stored response (Idempotent-Replayed: true)
    // other payload  -> 422 idempotency_key_reused
    return $this->duplicate($storageKey, $fingerprint);
}

$response = $next($request);
Redis::setex($storageKey, 86400, json_encode([...$response]));  // kept for replays
PHP;
@endphp

<x-scenario id="double-click" title="Double-clicks and network retries"
            tagline="One shopper, one intention, several identical requests.">
    <x-slot:problem>
        <p>
            Shoppers double-click <em>Buy</em>. Mobile networks time out and the app retries. Without protection every copy of
            the request is processed: two reservations, or two charges, for one intention.
        </p>
    </x-slot:problem>

    <x-slot:solution>
        <p>
            The client sends an <span class="code-inline">X-Idempotency-Key</span> per intention.
            <span class="code-inline">IdempotencyMiddleware</span> claims it with <span class="code-inline">SET NX</span>:
            copies that arrive while the first one runs get <strong>409</strong>; copies that arrive later get the
            <strong>original response replayed</strong>.
        </p>
        <p>
            Second line of defence: a buggy client that sends a new key per click still cannot get a second unit,
            because <span class="code-inline">reserve.lua</span> allows one active reservation per shopper.
        </p>
    </x-slot:solution>

    <x-slot:code>
        <x-code-block title="app/Http/Middleware/IdempotencyMiddleware.php (excerpt)" :code="$idempotencySnippet" />
    </x-slot:code>

    <div x-data="doubleClickScenario" class="space-y-4">
        <div class="card flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-sm text-slate-800">Shopper 151 triple-clicks, retries, then a buggy client sends new keys.</p>
                <p class="mt-1 text-xs text-slate-500">Sale with 5 units, max 1 per shopper.</p>
            </div>
            <button type="button" class="btn-primary" @click="run" :disabled="running" x-text="running ? 'Running…' : 'Run'"></button>
        </div>

        <div x-show="result" x-cloak class="grid grid-cols-2 gap-4">
            <div class="card">
                <p class="label">Reservations held by the shopper</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-slate-900" x-text="result?.reservations_held"></p>
            </div>
            <div class="card">
                <p class="label">Stock left</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-slate-900"><span x-text="result?.stock.available"></span><span class="text-base font-normal text-slate-400"> / <span x-text="result?.stock.total"></span></span></p>
            </div>
        </div>

        @include('simulator.partials.attempts')
    </div>
</x-scenario>
