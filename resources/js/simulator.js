import axios from 'axios';

const api = axios.create({
    baseURL: '/api',
    headers: { Accept: 'application/json' },
    validateStatus: () => true,
});

const newKey = (prefix = 'ui') =>
    `${prefix}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;

const asShopper = (userId, idempotent = true) => ({
    headers: {
        'X-User-Id': userId,
        ...(idempotent ? { 'X-Idempotency-Key': newKey() } : {}),
    },
});

const subscribe = (saleId, handler) => {
    const name = `flash-sale.${saleId}`;
    window.Echo.channel(name).listen('.inventory.updated', handler);

    return () => window.Echo.leave(name);
};

export const EVENT_STYLES = {
    reserved: 'border-blue-200 bg-blue-50 text-blue-700',
    confirmed: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    persisted: 'border-teal-200 bg-teal-50 text-teal-700',
    released: 'border-slate-300 bg-slate-50 text-slate-700',
    expired: 'border-amber-200 bg-amber-50 text-amber-700',
    sold_out: 'border-red-200 bg-red-50 text-red-700',
    sync: 'border-slate-200 bg-white text-slate-500',
};

export const STATUS_STYLES = {
    reserved: 'text-blue-700',
    confirmed: 'text-emerald-700',
    released: 'text-slate-700',
    sold_out: 'text-red-700',
    already_holding: 'text-amber-700',
    request_in_progress: 'text-amber-700',
    not_found: 'text-red-700',
    expired: 'text-amber-700',
    limit_reached: 'text-red-700',
    error: 'text-red-700',
};

export default function registerSimulator(Alpine) {
    Alpine.store('clock', {
        now: Date.now() / 1000,
        init() {
            setInterval(() => (this.now = Date.now() / 1000), 250);
        },
    });

    Alpine.store('styles', { event: EVENT_STYLES, status: STATUS_STYLES });

    Alpine.data('connectionStatus', () => ({
        state: 'connecting',
        init() {
            const connection = window.Echo?.connector?.pusher?.connection;
            if (!connection) return;
            this.state = connection.state;
            connection.bind('state_change', ({ current }) => (this.state = current));
        },
        get label() {
            return { connected: 'Live', connecting: 'Connecting…', unavailable: 'Offline', failed: 'Offline' }[this.state] ?? this.state;
        },
    }));

    /* ------------------------------------------------------------------ */
    /* Hero: "last item, two clicks" story                                */
    /* ------------------------------------------------------------------ */
    Alpine.data('lastItemStory', () => ({
        mode: 'naive',
        step: 0,
        timer: null,
        stories: {
            naive: {
                steps: [
                    { lane: 'a', text: 'Reads stock', detail: 'SELECT stock → 1', stock: 1 },
                    { lane: 'b', text: 'Reads stock', detail: 'SELECT stock → 1', stock: 1 },
                    { lane: 'a', text: 'Checks 1 ≥ 1 ✓', detail: 'charges the card…', stock: 1 },
                    { lane: 'b', text: 'Checks 1 ≥ 1 ✓', detail: 'charges the card…', stock: 1 },
                    { lane: 'a', text: 'Writes stock = 1 − 1', detail: 'order #1 created', stock: 0 },
                    { lane: 'b', text: 'Writes stock = 1 − 1', detail: 'order #2 created', stock: 0, bad: true },
                ],
                outcome: 'Two orders, one console. The stock column says 0, so nothing looks wrong until the warehouse cannot ship order #2.',
                good: false,
            },
            lua: {
                steps: [
                    { lane: 'a', text: 'Sends reserve.lua', detail: 'EVALSHA …', stock: 1 },
                    { lane: 'b', text: 'Sends reserve.lua', detail: 'EVALSHA … (queued)', stock: 1 },
                    { lane: 'mid', text: 'Runs A alone', detail: 'check 1 ≥ 1 → DECRBY → 0', stock: 0 },
                    { lane: 'a', text: 'Reserved ✓', detail: 'unit held for 5 minutes', stock: 0 },
                    { lane: 'mid', text: 'Runs B alone', detail: 'check 0 ≥ 1 ✗', stock: 0 },
                    { lane: 'b', text: 'Sold out', detail: 'told instantly, no charge', stock: 0, good: true },
                ],
                outcome: 'One order, one console. Redis executes one script at a time, so "check" and "decrement" can never be interleaved by another request.',
                good: true,
            },
        },
        get story() {
            return this.stories[this.mode];
        },
        get stock() {
            return this.step === 0 ? 1 : this.story.steps[this.step - 1].stock;
        },
        get done() {
            return this.step >= this.story.steps.length;
        },
        play(mode = this.mode) {
            clearInterval(this.timer);
            this.mode = mode;
            this.step = 0;
            this.timer = setInterval(() => {
                if (this.done) return clearInterval(this.timer);
                this.step++;
            }, 900);
        },
        init() {
            this.play('naive');
        },
    }));

    /* ------------------------------------------------------------------ */
    /* Scenario 1: strategy race                                          */
    /* ------------------------------------------------------------------ */
    Alpine.data('raceScenario', () => ({
        shoppers: 50,
        stock: 5,
        running: false,
        result: null,
        error: null,
        accents: {
            naive: { bar: 'bg-slate-400' },
            db_lock: { bar: 'bg-navy-400' },
            redis_lua: { bar: 'bg-navy-600' },
        },
        async run() {
            this.running = true;
            this.error = null;
            const { status, data } = await api.post('/simulations/race', { shoppers: +this.shoppers, stock: +this.stock });
            this.running = false;
            if (status !== 200) return (this.error = data.message ?? 'Simulation failed');
            this.result = data;
        },
        fastest() {
            const correct = this.result.results.filter((r) => r.oversold === 0);
            return Math.min(...correct.map((r) => r.wall_ms));
        },
        verdict(r) {
            if (r.oversold > 0) return { text: `Oversold by ${r.oversold}`, cls: 'text-red-700' };
            if (r.wall_ms === this.fastest()) return { text: 'Correct, fastest', cls: 'text-emerald-700' };
            return { text: 'Correct, slower', cls: 'text-amber-700' };
        },
    }));

    /* ------------------------------------------------------------------ */
    /* Scenarios 2 & 3: request lists                                     */
    /* ------------------------------------------------------------------ */
    const attemptsScenario = (endpoint) => () => ({
        running: false,
        result: null,
        async run() {
            this.running = true;
            const { data } = await api.post(endpoint);
            this.result = data;
            this.running = false;
        },
        phases() {
            if (!this.result) return [];
            const groups = [];
            for (const attempt of this.result.attempts) {
                let group = groups.find((g) => g.phase === attempt.phase);
                if (!group) groups.push((group = { phase: attempt.phase, attempts: [] }));
                group.attempts.push(attempt);
            }
            return groups;
        },
    });

    Alpine.data('doubleClickScenario', attemptsScenario('/simulations/double-click'));
    Alpine.data('twoTabsScenario', attemptsScenario('/simulations/two-tabs'));

    /* ------------------------------------------------------------------ */
    /* Scenario 4: abandoned cart                                         */
    /* ------------------------------------------------------------------ */
    Alpine.data('abandonedCartScenario', () => ({
        running: false,
        result: null,
        reservations: [],
        available: null,
        attempts: [],
        events: [],
        offset: 0,
        unsubscribe: null,
        async run() {
            this.running = true;
            this.unsubscribe?.();
            const { data } = await api.post('/simulations/abandoned-cart');
            this.result = data;
            this.reservations = data.reservations;
            this.available = data.snapshot.available_stock;
            this.attempts = [data.late_attempt];
            this.events = [];
            this.offset = data.server_time - Date.now() / 1000;
            this.unsubscribe = subscribe(data.sale_id, (e) => {
                this.events.unshift(e);
                this.available = e.available_stock;
                if (['expired', 'sync', 'reserved', 'sold_out'].includes(e.event_type)) this.refresh();
            });
            this.running = false;
        },
        async refresh() {
            const { data } = await api.get(`/flash-sales/${this.result.sale_id}`);
            this.reservations = data.data.reservations;
            this.available = data.data.redis.available_stock;
        },
        secondsLeft(r) {
            return Math.max(0, Math.ceil(r.expires_at - (this.$store.clock.now + this.offset)));
        },
        async tryAgain() {
            const { status, data } = await api.post(
                `/flash-sales/${this.result.sale_id}/reservations`,
                {},
                asShopper(this.result.late_shopper_id),
            );
            this.attempts.push({ label: `Attempt ${this.attempts.length + 1}`, http: status, status: data.status, message: data.message });
        },
        get unitsHeld() {
            return this.reservations.length;
        },
    }));

    /* ------------------------------------------------------------------ */
    /* Scenario 5: outbox / crash safety                                  */
    /* ------------------------------------------------------------------ */
    Alpine.data('outboxScenario', () => ({
        step: 0,
        saleId: null,
        result: null,
        state: null,
        poller: null,
        async start() {
            clearInterval(this.poller);
            this.step = 0;
            const { data } = await api.post('/simulations/outbox');
            this.saleId = data.sale_id;
            this.result = data;
            this.state = data.state;
            this.step = 1;
        },
        async resume() {
            const { data } = await api.post(`/simulations/outbox/${this.saleId}/resume`);
            this.state = data;
            this.step = 2;
            this.poller = setInterval(async () => {
                const { data } = await api.get(`/simulations/outbox/${this.saleId}`);
                this.state = data;
                if (data.db_orders.length > 0) {
                    clearInterval(this.poller);
                    this.step = 3;
                }
            }, 500);
        },
    }));

    /* ------------------------------------------------------------------ */
    /* Live sale dashboard                                                */
    /* ------------------------------------------------------------------ */
    Alpine.data('liveSale', () => ({
        sales: [],
        selectedId: null,
        sale: null,
        events: [],
        shopper: 1,
        token: null,
        lastResult: null,
        burstSize: 20,
        busy: false,
        offset: 0,
        unsubscribe: null,
        refreshTimer: null,
        async init() {
            const { data } = await api.get('/flash-sales');
            this.sales = data.data;
            if (this.sales.length) await this.select(this.sales[0].id);
        },
        async select(id) {
            this.unsubscribe?.();
            this.selectedId = +id;
            this.events = [];
            this.token = null;
            this.lastResult = null;
            await this.refresh();
            this.unsubscribe = subscribe(this.selectedId, (e) => this.onEvent(e));
        },
        async refresh() {
            const { data } = await api.get(`/flash-sales/${this.selectedId}`);
            this.sale = data.data;
            this.offset = data.server_time - Date.now() / 1000;
        },
        onEvent(e) {
            this.events.unshift({ ...e, key: `${e.timestamp}-${Math.random()}` });
            this.events = this.events.slice(0, 40);
            if (this.sale) {
                this.sale.redis.available_stock = e.available_stock;
                this.sale.redis.active_reservations = e.active_reservations;
                this.sale.redis.pending_orders = e.pending_orders;
            }
            clearTimeout(this.refreshTimer);
            this.refreshTimer = setTimeout(() => this.refresh(), 300);
        },
        get percent() {
            if (!this.sale) return 0;
            return Math.round((this.sale.redis.available_stock / this.sale.stock_total) * 100);
        },
        secondsLeft(r) {
            return Math.max(0, Math.ceil(r.expires_at - (this.$store.clock.now + this.offset)));
        },
        formatTime(seconds) {
            const m = Math.floor(seconds / 60);
            return `${m}:${String(seconds % 60).padStart(2, '0')}`;
        },
        async act(request) {
            this.busy = true;
            const { status, data } = await request();
            this.lastResult = { http: status, ...data };
            this.busy = false;
            return data;
        },
        async reserve() {
            const data = await this.act(() => api.post(`/flash-sales/${this.selectedId}/reservations`, { quantity: 1 }, asShopper(this.shopper)));
            if (data.reservation_token) this.token = data.reservation_token;
        },
        async confirm() {
            const data = await this.act(() => api.post(`/flash-sales/${this.selectedId}/reservations/${this.token}/confirm`, {}, asShopper(this.shopper)));
            if (['confirmed', 'not_found', 'expired'].includes(data.status)) this.token = null;
        },
        async release() {
            const data = await this.act(() => api.delete(`/flash-sales/${this.selectedId}/reservations/${this.token}`, asShopper(this.shopper, false)));
            if (['released', 'not_found'].includes(data.status)) this.token = null;
        },
        async burst() {
            await this.act(() => api.post(`/simulations/flash-sales/${this.selectedId}/burst`, { shoppers: +this.burstSize }));
        },
        async reset() {
            await this.act(() => api.post(`/simulations/flash-sales/${this.selectedId}/reset`));
            this.token = null;
            await this.refresh();
        },
        shopperName(id) {
            return `Shopper ${String(id).padStart(3, '0')}`;
        },
        money(cents) {
            return `$${(cents / 100).toLocaleString(undefined, { minimumFractionDigits: 2 })}`;
        },
        time(ms) {
            return new Date(ms).toLocaleTimeString(undefined, { hour12: false });
        },
    }));
}
