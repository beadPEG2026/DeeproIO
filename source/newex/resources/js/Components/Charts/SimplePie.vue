<template>
<div class="simple-pie" :style="{display: 'flex', alignItems: 'center', justifyContent: 'center'}">
  <svg :width="size" :height="size" :viewBox="`0 0 ${size} ${size}`" role="img" :aria-label="$t(&quot;Asset Allocation Pie Chart&quot;)">
    <g :transform="`rotate(-90 ${center} ${center})`">
      <circle
        v-for="(s, i) in segments"
        :key="i"
        :cx="center"
        :cy="center"
        :r="radius"
        fill="transparent"
        :stroke="s.color"
        :stroke-width="thickness"
        :stroke-dasharray="`${s.length} ${circumference - s.length}`"
        :stroke-dashoffset="s.offset"
        stroke-linecap="butt"
      />
    </g>
    <text
      v-if="showCenter"
      :x="center"
      :y="center - 6"
      text-anchor="middle"
      class="pie-center-amount"
      fill="currentColor"
      style="font-weight: 600; font-size: 14px"
    >
      ≈ {{ formatCurrency(total) }}
    </text>
    <text
      v-if="showCenter"
      :x="center"
      :y="center + 14"
      text-anchor="middle"
      class="pie-center-sub"
      fill="#6b7280"
      style="font-size: 11px"
    >
      {{ $t('Total') }}
    </text>
  </svg>
</div>
</template>

<script>
export default {
  name: 'SimplePie',
  props: {
    data: { type: Array, required: true }, // [{label, value, color}]
    size: { type: Number, default: 220 },
    thickness: { type: Number, default: 28 },
    showCenter: { type: Boolean, default: true },
  },
  computed: {
    total() {
      return (this.data || []).reduce((s, d) => s + (parseFloat(d.value) || 0), 0)
    },
    center() { return this.size / 2 },
    radius() { return (this.size - this.thickness) / 2 },
    circumference() { return 2 * Math.PI * this.radius },
    segments() {
      let offset = 0
      return (this.data || []).map(d => {
        const val = Math.max(0, parseFloat(d.value) || 0)
        const length = this.total > 0 ? (val / this.total) * this.circumference : 0
        const seg = { color: d.color || '#999', length, offset }
        offset -= length
        return seg
      })
    },
  },
  methods: {
    formatCurrency(v) {
      const num = Number(v || 0)
      return num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '$'
    },
  }
}
</script>
