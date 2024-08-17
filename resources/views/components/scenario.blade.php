@props(['id', 'title', 'tagline'])

<section id="{{ $id }}" x-data="{ showCode: false }" class="grid gap-8 lg:grid-cols-3">
    <div class="space-y-4 lg:col-span-1">
        <div class="mb-2">
            <h2 class="text-lg font-semibold text-slate-900">{{ $title }}</h2>
            <p class="mt-1 text-sm text-slate-500">{{ $tagline }}</p>
        </div>

        <div class="rounded-md border border-rose-100 bg-rose-50/60 p-4">
            <h3 class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-rose-700">
                <span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span>The problem
            </h3>
            <div class="mt-2 space-y-2 text-sm leading-relaxed text-slate-700">{{ $problem }}</div>
        </div>

        <div class="rounded-md border border-navy-100 bg-navy-50 p-4">
            <h3 class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-navy-700">
                <span class="h-1.5 w-1.5 rounded-full bg-navy-600"></span>How the code solves it
            </h3>
            <div class="mt-2 space-y-2 text-sm leading-relaxed text-slate-700">{{ $solution }}</div>
        </div>

        @isset($code)
            <button type="button" class="text-sm font-medium text-navy-600 hover:text-navy-800"
                    @click="showCode = !showCode; showCode && $nextTick(() => $refs.code.scrollIntoView({ behavior: 'smooth', block: 'start' }))"
                    x-text="showCode ? 'Hide the code' : 'Show the code'"></button>
        @endisset
    </div>

    <div class="lg:col-span-2">
        {{ $slot }}
    </div>

    @isset($code)
        <div x-ref="code" x-show="showCode" x-cloak class="scroll-mt-6 space-y-3 lg:col-span-3">{{ $code }}</div>
    @endisset
</section>
