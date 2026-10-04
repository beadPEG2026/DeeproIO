<template>
<div class="dp-chart-shell" :class="{'is-fullscreen': expanded}" @keydown.esc="expanded=false; toolsOpen=false">
  <p v-if="hongKong" class="dp-chart-source">{{ zh?'USDT 参考走势 · 按历史每日汇率折算，港股延时行情':'USDT reference · historical daily FX conversion, delayed HK prices' }}</p>
  <nav v-show="controls" key="periods" class="dp-chart-periods" :aria-label="$t('Chart interval')">
    <button v-for="period in quickPeriods" :key="period" type="button" :aria-pressed="String(resolution===period)" @click="command('resolution',period)">{{ periodLabel(period) }}</button>
    <themed-select v-if="extraPeriods.length" :value="extraPeriods.includes(resolution) ? resolution : ''" :aria-label="$t('More intervals')" @change="command('resolution',$event.target.value)"><option disabled value="">{{ $t('More') }}</option><option v-for="period in extraPeriods" :key="period" :value="period">{{ periodLabel(period) }}</option></themed-select>
    <button type="button" class="dp-chart-tools-toggle" :aria-label="$t('Chart tools')" :aria-expanded="String(toolsOpen)" @click="toolsOpen=!toolsOpen">⚙</button>
    <button type="button" class="dp-chart-expand" :aria-label="$t('Fullscreen')" :aria-pressed="String(expanded)" @click="fullscreen">⛶</button>
  </nav>
  <div v-if="controls && toolsOpen" class="dp-chart-tools">
    <label>{{ $t('Chart type') }}<themed-select :aria-label="$t('Chart type')" :value="chartType" @change="command('chartType',Number($event.target.value))"><option v-for="type in chartTypes" :key="type.id" :value="type.id">{{ $t(type.label) }}</option></themed-select></label>
    <button type="button" @click="command('allIndicators'); toolsOpen=false">{{ $t('All indicators') }}</button>
    <button type="button" @click="command('snapshot'); toolsOpen=false">{{ $t('Save chart image') }}</button>
    <button type="button" :aria-pressed="String(details)" @click="details=!details; command('details',details)">{{ $t('Candle details') }}</button>
  </div>
  <div key="chart" class="dp-market-chart" :style="{minHeight: height + 'px'}" :aria-busy="!ready && !failed">
    <iframe ref="frame" id="market-chart" :key="attempt" :src="localizedSrc" :height="height" :style="{height: height + 'px'}" width="100%" scrolling="no" :title="$t('Chart')" allowfullscreen @load="onLoad" @error="failed = true"></iframe>
    <div v-if="!ready" class="dp-market-chart__state" :class="{'has-error': failed}" role="status"><span>{{ $t(failed ? 'Chart unavailable. Please retry.' : 'Loading chart…') }}</span><button v-if="failed" type="button" @click="retry">{{ $t('Retry') }}</button></div>
  </div>
  <nav v-show="controls && indicators.length" key="indicators" class="dp-chart-indicators" :aria-label="$t('Indicators')"><button v-for="indicator in indicators" :key="indicator" type="button" :disabled="pending" :aria-pressed="String(active.includes(indicator))" @click="command('indicator',indicator)">{{ indicator }}</button></nav>
  <p v-if="controlError" class="dp-chart-feedback" role="status">{{ $t('Chart control unavailable. Please retry.') }}</p>
</div>
</template>
<script>
export default {
  props: {src: String, hongKong: Boolean, height: {type: [Number, String], default: 400}},
  data: () => ({feed:'reference', ready: false, failed: false, attempt: 0, timer: null, started: 0, controls: false, resolutions: [], resolution: '', indicators: [], active: [], pending: false, controlError: false, commandTimer: null, toolsOpen: false, chartType: 1, chartTypes: [], details: false, expanded: false, compact: window.matchMedia('(max-width: 767px)').matches}),
  computed: {
    zh(){return /^zh/i.test(this.$i18n.locale)},
    localizedSrc() {const url = new URL(this.src, window.location.origin); url.searchParams.set('lang', this.$i18n.locale); url.searchParams.set('controls','external'); url.searchParams.set('compact', this.compact ? '1' : '0'); if(this.hongKong)url.searchParams.set('feed',this.feed); return url.href;},
    quickPeriods() {return ['1','15','60','240','1D'].filter(r => this.resolutions.includes(r));},
    extraPeriods() {return this.resolutions.filter(r => !this.quickPeriods.includes(r));}
  },
  watch: {localizedSrc() {this.arm();}},
  mounted() {window.addEventListener('message', this.message); this.media=window.matchMedia('(max-width: 767px)'); this.media.addEventListener('change', this.resize); this.arm();},
  beforeDestroy() {clearTimeout(this.timer); clearTimeout(this.commandTimer); window.removeEventListener('message', this.message); this.media.removeEventListener('change', this.resize);},
  methods: {
    resize(event) {this.compact=event.matches;},
    arm() {clearTimeout(this.timer); this.controls=false; this.toolsOpen=false; this.details=!this.compact; this.pending=false; clearTimeout(this.commandTimer); this.ready=false; this.failed=false; this.started=performance.now(); this.timer=setTimeout(() => {if (!this.ready) this.failed=true;}, 15000);},
    retry() {this.arm(); this.attempt++;},
    onLoad() {if (new URL(this.src, location.origin).origin !== location.origin) this.complete();},
    complete() {if (this.ready) return; this.ready=true; this.failed=false; clearTimeout(this.timer); window.dispatchEvent(new CustomEvent('deepro:chart-ready', {detail: {durationMs: Math.round(performance.now()-this.started)}}));},
    periodLabel(period) {
      if (/^zh/i.test(this.$i18n.locale)) {
        const traditional=/tw|hk/i.test(this.$i18n.locale);
        if (period==='1D') return traditional?'日線':'日线';
        if (period==='1W') return traditional?'週線':'周线';
        if (period==='1M') return traditional?'月線':'月线';
        const n=Number(period);if (Number.isFinite(n)) return n<60?n+'分':n/60+(traditional?'小時':'小时');
      }
      return ({'1':'1m','3':'3m','5':'5m','15':'15m','30':'30m','60':'1h','120':'2h','240':'4h','360':'6h','480':'8h','720':'12h','1D':'1D','1W':'1W','1M':'1M'})[period] || period;
    },
    command(action,value) {
      if (!this.controls || this.pending) return;
      this.pending=true; this.controlError=false;
      this.$refs.frame.contentWindow.postMessage({type:'deepro-chart-command',action,value}, location.origin);
      clearTimeout(this.commandTimer); this.commandTimer=setTimeout(() => {this.pending=false; this.controlError=true;}, 8000);
    },
    fullscreen() {this.expanded=!this.expanded; this.toolsOpen=false;},
    message(event) {
      if (event.origin !== location.origin || !this.$refs.frame || event.source !== this.$refs.frame.contentWindow || !event.data) return;
      const data=event.data;
      if (data.type === 'deepro-chart-ready') this.complete();
      if (data.type === 'deepro-chart-error') {this.ready=false; this.failed=true;}
      if (data.type === 'deepro-chart-controls') {
        this.controls=true; this.resolutions=data.resolutions; this.resolution=data.resolution; this.indicators=data.indicators; this.active=data.active; this.chartType=data.chartType===undefined?1:data.chartType; this.chartTypes=data.chartTypes || []; this.controlError=data.error; this.pending=false; clearTimeout(this.commandTimer);
      }
    }
  }
};
</script>

<style scoped>
.dp-chart-source{display:flex;align-items:center;gap:5px;padding:10px 12px;border-bottom:1px solid var(--ui-divider,#edf0f4);flex-wrap:wrap}
.dp-chart-source button{background:transparent;border:0;border-radius:6px;color:var(--ui-muted,#808793);padding:7px 10px;font-size:12px;cursor:pointer}
.dp-chart-source button[aria-pressed=true]{background:#ffdc48;color:#29250d;font-weight:700}
.dp-chart-source span{margin-left:auto;font-size:10px;color:var(--ui-muted,#808793)}
</style>
