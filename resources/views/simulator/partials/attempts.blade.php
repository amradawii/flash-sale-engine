<div class="space-y-4" x-show="result" x-cloak>
    <template x-for="group in phases()" :key="group.phase">
        <div class="card p-0">
            <p class="border-b border-slate-200 px-5 py-3 text-sm font-medium text-slate-900" x-text="group.phase"></p>
            <div class="divide-y divide-slate-100">
                <template x-for="a in group.attempts" :key="group.phase + a.label">
                    <div class="grid grid-cols-[5rem_3rem_1fr_auto] items-center gap-3 px-5 py-2.5 text-sm">
                        <span class="text-slate-500" x-text="a.label"></span>
                        <span class="font-mono text-xs text-slate-700" x-text="a.http"></span>
                        <div class="min-w-0">
                            <p class="font-mono text-xs font-medium" :class="$store.styles.status[a.status] ?? 'text-slate-700'" x-text="a.status"></p>
                            <p class="truncate text-xs text-slate-500" x-text="a.message"></p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span x-show="a.replayed" class="pill border-blue-200 bg-blue-50 text-blue-700">replayed</span>
                            <span class="font-mono text-[11px] tabular-nums text-slate-400" x-text="a.ms + ' ms'"></span>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </template>
</div>

<template x-if="!result">
    <div class="card py-12 text-center text-sm text-slate-500">
        Run the scenario to see every request and the server's answer.
    </div>
</template>
