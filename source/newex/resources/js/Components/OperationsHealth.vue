<template>
  <section class="ops-health" aria-labelledby="ops-health-title">
    <div class="ops-health__heading"><h2 id="ops-health-title">{{ $t('Operations health') }}</h2><span>{{ $t(statusLabel(report.status)) }}</span></div>
    <p v-if="!report.fresh" role="status">{{ $t('Monitoring data is unavailable or older than 3 minutes.') }}</p>
    <template v-else>
      <p>{{ $t('Last updated') }}: <time :datetime="report.generated_at">{{ report.generated_at }}</time></p>
      <ul><li v-for="check in report.checks" :key="check.name"><div><span>{{ $t(labels[check.name] || check.name) }}</span><strong :data-status="check.status">{{ $t(statusLabel(check.status)) }}</strong></div>
        <p v-for="chain in check.chains || []" :key="chain.name" class="ops-health__chain">{{ chainNames[chain.name] || chain.name }} · {{ $t(check.name === 'deposit_backfill' ? 'Pending scopes' : 'Needs attention') }}: {{ chain.attention }} / {{ chain.scopes }}</p>
      </li></ul>
    </template>
    <p class="ops-health__note">{{ $t('Read-only checks. Refresh this page for the latest sample.') }}</p>
    <p class="ops-health__note">{{ $t('Backup restore checks do not verify point-in-time recovery or financial reconciliation.') }}</p>
  </section>
</template>
<script>
export default {
  props:{report:{type:Object,default:()=>({status:'unknown',fresh:false,checks:[]})}},
  data:()=>({labels:{containers:'Container runtime',data_disk:'Disk space',postgres:'Database',pitr_evidence:'Point-in-time recovery',failed_jobs:'Failed jobs',deposit_scans:'Live deposit scanning',deposit_backfill:'Historical deposit backfill',trx_scans:'TRX scanning',backup_freshness:'Backup freshness',backup_restore:'Database backup restore',queue_wait:'Queue waiting time'},chainNames:{ethereum:'Ethereum',bsc:'BSC',polygon:'Polygon',arbitrum:'Arbitrum',optimism:'Optimism',avalanche:'Avalanche',base:'Base',tron:'TRON',solana:'Solana'}}),
  methods:{statusLabel(value){return {ok:'Normal',warning:'Needs attention',critical:'Critical',unknown:'Unknown'}[value] || 'Unknown';}}
};
</script>
<style scoped>
.ops-health{margin:0 0 24px;padding:20px;background:var(--ui-surface,#fff);color:var(--ui-text,#181d25);border:1px solid var(--ui-line,#c7ac66);border-radius:12px;overflow-wrap:anywhere}
.ops-health__heading{display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px}.ops-health h2{font-size:18px;font-weight:600}.ops-health p{margin:8px 0;color:var(--ui-muted,#586474);font-size:13px}.ops-health ul{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(280px,100%),1fr));gap:8px 24px}.ops-health li{min-width:0;padding:8px 0;border-bottom:1px solid var(--ui-divider,#e8dfc9)}.ops-health li>div{display:flex;flex-wrap:wrap;justify-content:space-between;gap:6px 12px}.ops-health li>div>span{flex:1 1 auto}.ops-health strong{flex-shrink:0}.ops-health strong[data-status=ok]{color:var(--ui-success,#087b55)}.ops-health strong[data-status=critical]{color:var(--ui-danger,#bd2942)}.ops-health strong[data-status=warning]{color:var(--ui-accent-text,#755600)}
</style>
