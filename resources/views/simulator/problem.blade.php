<section id="problem" class="space-y-10">
    <div class="grid gap-10 lg:grid-cols-5">
        <div class="space-y-4 text-sm leading-relaxed text-slate-700 lg:col-span-2">
            <h2 class="text-lg font-semibold text-slate-900">Selling the last unit twice</h2>
            <p>
                When many shoppers buy the same product at the same moment, the usual
                <em>read stock → check → write stock</em> code sells units that do not exist.
                Each request reads a value that another request is about to change.
            </p>
            <p>
                This engine reserves inventory with <strong>atomic Redis Lua scripts</strong>, returns abandoned
                reservations automatically, and saves orders through a <strong>crash-safe outbox</strong>.
            </p>
            <p>
                Each tab reproduces one real failure with concurrent HTTP requests, shows the evidence,
                and explains the code that prevents it.
            </p>
        </div>

        <div x-data="lastItemStory" class="card lg:col-span-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-slate-700">Stock = 1. Two shoppers press <em>Buy</em> in the same millisecond.</p>
                <div class="flex rounded-lg bg-slate-100 p-1 text-xs font-medium">
                    <button type="button" @click="play('naive')" class="rounded-md px-3 py-1"
                            :class="mode === 'naive' ? 'bg-white text-navy-700 shadow-sm' : 'text-slate-600 hover:text-slate-900'">Naive code</button>
                    <button type="button" @click="play('lua')" class="rounded-md px-3 py-1"
                            :class="mode === 'lua' ? 'bg-white text-navy-700 shadow-sm' : 'text-slate-600 hover:text-slate-900'">Atomic Lua</button>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-[1fr_7rem_1fr] gap-3 text-center text-xs font-medium text-slate-500">
                <span>Shopper A</span>
                <span x-text="mode === 'naive' ? 'MySQL stock' : 'Redis'"></span>
                <span>Shopper B</span>
            </div>

            <div class="mt-2 min-h-[18rem] space-y-2">
                <template x-for="(s, i) in story.steps" :key="mode + i">
                    <div class="grid grid-cols-[1fr_7rem_1fr] items-center gap-3 transition-opacity duration-300"
                         :class="i < step ? 'opacity-100' : 'opacity-0'">
                        <div>
                            <template x-if="s.lane === 'a'">
                                <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                                    <p class="text-sm text-slate-900" x-text="s.text"></p>
                                    <p class="font-mono text-[11px] text-slate-500" x-text="s.detail"></p>
                                </div>
                            </template>
                        </div>
                        <div class="text-center">
                            <template x-if="s.lane === 'mid'">
                                <div class="rounded-md border border-slate-300 bg-white px-2 py-2">
                                    <p class="text-xs font-medium text-slate-900" x-text="s.text"></p>
                                    <p class="font-mono text-[10px] text-slate-500" x-text="s.detail"></p>
                                </div>
                            </template>
                            <template x-if="s.lane !== 'mid'">
                                <span class="inline-grid h-7 w-7 place-items-center rounded-full border border-slate-300 font-mono text-sm text-slate-900" x-text="s.stock"></span>
                            </template>
                        </div>
                        <div>
                            <template x-if="s.lane === 'b'">
                                <div class="rounded-md border px-3 py-2"
                                     :class="s.bad ? 'border-red-200 bg-red-50' : (s.good ? 'border-emerald-200 bg-emerald-50' : 'border-slate-200 bg-slate-50')">
                                    <p class="text-sm" :class="s.bad ? 'text-red-700' : (s.good ? 'text-emerald-700' : 'text-slate-900')" x-text="s.text"></p>
                                    <p class="font-mono text-[11px] text-slate-500" x-text="s.detail"></p>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>

            <div class="mt-4 flex items-start justify-between gap-4 border-t border-slate-200 pt-4">
                <p class="text-sm" x-show="done">
                    <span class="font-semibold" :class="story.good ? 'text-emerald-700' : 'text-red-700'" x-text="story.good ? 'Correct.' : 'Oversold.'"></span>
                    <span class="text-slate-600" x-text="story.outcome"></span>
                </p>
                <button type="button" class="ml-auto shrink-0 text-xs text-slate-500 hover:text-slate-900" @click="play()">Replay</button>
            </div>
        </div>
    </div>

    <div>
        <h3 class="text-sm font-semibold text-slate-900">Do you need this?</h3>
        <div class="mt-3 overflow-hidden rounded-lg border border-slate-200 bg-white">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs text-slate-500">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Approach</th>
                        <th class="px-4 py-2.5 font-medium">Correct</th>
                        <th class="px-4 py-2.5 font-medium">Under a traffic spike</th>
                        <th class="px-4 py-2.5 font-medium">Use it for</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 text-slate-700">
                    <tr>
                        <td class="px-4 py-3 font-medium text-slate-900">Read → check → write</td>
                        <td class="px-4 py-3 text-red-700">No</td>
                        <td class="px-4 py-3">Oversells. Two visitors are enough.</td>
                        <td class="px-4 py-3">Never</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-3 font-medium text-slate-900">MySQL row lock / atomic <span class="code-inline">UPDATE … WHERE stock &gt;= 1</span></td>
                        <td class="px-4 py-3 text-emerald-700">Yes</td>
                        <td class="px-4 py-3">Buyers queue on one row, each holding a DB connection.</td>
                        <td class="px-4 py-3">Everyday e-commerce</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-3 font-medium text-slate-900">Redis atomic Lua (this engine)</td>
                        <td class="px-4 py-3 text-emerald-700">Yes</td>
                        <td class="px-4 py-3">Check and decrement in microseconds; MySQL written later from an outbox.</td>
                        <td class="px-4 py-3">Flash sales, ticket drops, hot products</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</section>
