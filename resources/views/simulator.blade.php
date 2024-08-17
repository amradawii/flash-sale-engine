@php
$tabs = [
    'problem' => 'The problem',
    'race' => '1. Overselling',
    'double-click' => '2. Double-click',
    'two-tabs' => '3. Two tabs',
    'abandoned-cart' => '4. Abandoned cart',
    'outbox' => '5. Crash safety',
    'live' => 'Live sale',
];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Flash Sale Engine — concurrency simulator</title>
    <meta name="description" content="Interactive proof of how Redis Lua scripts stop overselling during flash sales.">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600|jetbrains-mono:400,500" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-800 antialiased"
      x-data="{ tab: @js(array_keys($tabs)).includes(location.hash.slice(1)) ? location.hash.slice(1) : 'problem' }"
      x-init="$watch('tab', t => history.replaceState(null, '', '#' + t))">

    <main class="mx-auto max-w-[88rem] px-6 pt-10 lg:px-10">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold text-slate-900">Flash sale concurrency simulator</h1>
                <p class="mt-1 text-sm text-slate-500">
                    Real concurrent HTTP requests against nginx and 20 PHP workers. Laravel 11, Redis Lua, Redis Streams, Reverb.
                </p>
            </div>
            <div x-data="connectionStatus" class="flex items-center gap-2 rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-600 shadow-sm">
                <span class="h-2 w-2 rounded-full"
                      :class="state === 'connected' ? 'bg-emerald-500' : (state === 'connecting' ? 'bg-amber-400' : 'bg-red-500')"></span>
                <span x-text="'WebSocket: ' + label"></span>
            </div>
        </div>

        @if ($simulatorEnabled)
            <nav class="mt-8 flex gap-1 overflow-x-auto rounded-xl border border-slate-200/80 bg-white p-1 shadow-sm shadow-slate-200/50" role="tablist">
                @foreach ($tabs as $key => $label)
                    <button type="button" role="tab" @click="tab = '{{ $key }}'"
                            :aria-selected="tab === '{{ $key }}'"
                            class="whitespace-nowrap rounded-lg px-3.5 py-2 text-sm font-medium transition-colors"
                            :class="tab === '{{ $key }}' ? 'bg-navy-50 text-navy-700' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800'">
                        {{ $label }}
                    </button>
                @endforeach
            </nav>

            <div class="py-8">
                @foreach (array_keys($tabs) as $key)
                    <div x-show="tab === '{{ $key }}'" @if ($key !== 'problem') x-cloak @endif role="tabpanel">
                        @include("simulator.{$key}")
                    </div>
                @endforeach
            </div>
        @else
            <div class="mt-8 border-t border-slate-200 py-8">
                @include('simulator.problem')
            </div>
        @endif
    </main>

    <footer class="border-t border-slate-200 py-8 text-center text-xs text-slate-500">
        Laravel {{ app()->version() }} · Redis Lua · Redis Streams · Laravel Reverb · Alpine.js · Tailwind CSS
    </footer>
</body>
</html>
