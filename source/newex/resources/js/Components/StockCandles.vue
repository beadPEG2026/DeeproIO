<template>
<div class="stock-candles">
    <div class="stock-ohlc" aria-live="polite">
        <template v-if="active"
            ><span>{{ date(active.time) }}</span
            ><span
                >{{ $t("开") }} <b>{{ price(active.open) }}</b></span
            ><span
                >{{ $t("高") }} <b>{{ price(active.high) }}</b></span
            ><span
                >{{ $t("低") }} <b>{{ price(active.low) }}</b></span
            ><span
                >{{ $t("收") }} <b>{{ price(active.close) }}</b></span
            ></template
        >
    </div>
    <svg
        v-if="rows.length"
        viewBox="0 0 1000 380"
        role="img"
        :aria-label="symbol + ' 美元价格 K 线'"
        @mousemove="hover"
        @mouseleave="selected = -1"
        @touchmove.prevent="touch"
        tabindex="0"
        @keydown.left.prevent="step(-1)"
        @keydown.right.prevent="step(1)"
    >
        <g v-for="tick in ticks" :key="tick.y">
            <line
                x1="0"
                :y1="tick.y"
                x2="915"
                :y2="tick.y"
                stroke="currentColor"
                opacity=".08"
            />
            <text
                x="925"
                :y="tick.y + 4"
                fill="currentColor"
                opacity=".55"
                font-size="12"
            >
                {{ price(tick.value) }}
            </text>
        </g>
        <g
            v-for="(row, i) in rows"
            :key="row.time"
            :fill="row.close >= row.open ? '#079b78' : '#e35357'"
            :stroke="row.close >= row.open ? '#079b78' : '#e35357'"
        >
            <line
                :x1="x(i)"
                :x2="x(i)"
                :y1="y(row.high)"
                :y2="y(row.low)"
            />
            <rect
                :x="x(i) - bar / 2"
                :y="y(Math.max(row.open, row.close))"
                :width="bar"
                :height="Math.max(1, Math.abs(y(row.open) - y(row.close)))"
                stroke="none"
            />
        </g>
        <line
            v-if="selected >= 0"
            :x1="x(selected)"
            :x2="x(selected)"
            y1="10"
            y2="338"
            stroke="currentColor"
            opacity=".3"
            stroke-dasharray="3 4"
        />
        <text
            v-for="i in labels"
            :key="'t' + i"
            :x="x(i)"
            y="370"
            fill="currentColor"
            opacity=".5"
            font-size="12"
            text-anchor="middle"
        >
            {{ shortDate(rows[i].time) }}
        </text>
    </svg>
    <div v-else class="stock-chart-empty">
        {{ loading ? $t("正在获取行情…") : $t("暂无可用 K 线") }}
    </div>
</div>
</template>
<script>
export default {
    props: {
        rows: { type: Array, default: () => [] },
        symbol: String,
        loading: Boolean,
    },
    data: () => ({ selected: -1 }),
    watch: {
        rows() {
            this.selected = -1;
        },
    },
    computed: {
        active() {
            return this.rows[
                this.selected < 0 ? this.rows.length - 1 : this.selected
            ];
        },
        bounds() {
            const low = Math.min(...this.rows.map((r) => r.low)),
                high = Math.max(...this.rows.map((r) => r.high)),
                pad = Math.max((high - low) * 0.08, high * 0.0001);
            return { low: low - pad, high: high + pad };
        },
        bar() {
            return Math.max(1, (900 / Math.max(1, this.rows.length)) * 0.62);
        },
        ticks() {
            return Array.from({ length: 5 }, (_, i) => ({
                y: 20 + i * 78,
                value:
                    this.bounds.high -
                    (i * (this.bounds.high - this.bounds.low)) / 4,
            }));
        },
        labels() {
            return [
                ...new Set([
                    Math.min(4, this.rows.length - 1),
                    Math.floor(this.rows.length / 2),
                    Math.max(0, this.rows.length - 5),
                ]),
            ];
        },
    },
    methods: {
        x(i) {
            return 8 + ((i + 0.5) * 900) / this.rows.length;
        },
        y(v) {
            return (
                20 +
                (312 * (this.bounds.high - v)) /
                    (this.bounds.high - this.bounds.low)
            );
        },
        price(n) {
            return Number(n).toLocaleString("en-US", {
                minimumFractionDigits: 2,
                maximumFractionDigits: 3,
            });
        },
        date(t) {
            return new Date(t).toLocaleString("zh-CN", { hour12: false });
        },
        shortDate(t) {
            return new Date(t).toLocaleString("zh-CN", {
                month: "2-digit",
                day: "2-digit",
                hour: "2-digit",
                minute: "2-digit",
                hour12: false,
            });
        },
        select(clientX, el) {
            const r = el.getBoundingClientRect();
            this.selected = Math.max(
                0,
                Math.min(
                    this.rows.length - 1,
                    Math.floor(
                        ((((clientX - r.left) / r.width) * 1000 - 8) / 900) *
                            this.rows.length
                    )
                )
            );
        },
        hover(e) {
            this.select(e.clientX, e.currentTarget);
        },
        touch(e) {
            if (e.touches[0])
                this.select(e.touches[0].clientX, e.currentTarget);
        },
        step(n) {
            this.selected = Math.max(
                0,
                Math.min(
                    this.rows.length - 1,
                    (this.selected < 0 ? this.rows.length - 1 : this.selected) +
                        n
                )
            );
        },
    },
};
</script>
