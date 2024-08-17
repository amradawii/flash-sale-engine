@props(['title', 'code'])

<div {{ $attributes->class('overflow-hidden rounded-md border border-slate-800 bg-slate-900 shadow-sm') }}>
    <div class="border-b border-slate-800 px-4 py-2 font-mono text-xs text-slate-400">{{ $title }}</div>
    <pre class="max-h-[32rem] overflow-auto p-4 font-mono text-[12.5px] leading-relaxed text-slate-200"><code>{{ trim($code) }}</code></pre>
</div>
