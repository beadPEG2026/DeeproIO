<template>
<app-layout>
    <p v-if="$page.props.depositLocalPreview" role="status" style="padding:12px;border:1px solid #c99c39;border-radius:8px;margin-bottom:16px">{{ $t('Local test environment. Do not transfer real funds.') }}</p>
    <Head :title="$t('Deposit channels')" />
    <h1 class="mb-4 text-2xl font-bold">{{ $t('Deposit channels') }}</h1>
    <p class="mb-6">{{ $t('Only accepted channels with a healthy scanner are available for deposits.') }}</p>
    <p class="mb-6">{{ $t('Pilot deposits last 24 hours for listed accounts. Collection follows the custody automation scope. Production limits remain unchanged.') }}</p>
    <div class="dp-channel-fields mb-4"><label>{{ $t('Search') }}<input v-model="channelSearch" :placeholder="$t('Asset') + ' / ' + $t('Network')"></label><label>{{ $t('Status') }}<themed-select v-model="channelState"><option value="">{{ $t('All') }}</option><option v-for="s in states" :key="s" :value="s">{{ $t(s) }}</option></themed-select></label></div>
    <section class="dp-channel-form" aria-labelledby="automation-heading">
        <h2 id="automation-heading">{{ $t('Asset automation checklist') }}</h2>
        <p>{{ $t('New assets inherit the network custody policy. Draft channels require node verification and a confirmed test before public deposits.') }}</p>
        <div class="dp-automation-actions"><button class="dp-button" :disabled="saving" @click="createDrafts">{{ $t('Create missing channel drafts') }}</button><Link class="dp-button" href="/exchange-control-panel/custody">{{ $t('Wallet custody') }} ↗</Link><Link class="dp-button" href="/exchange-control-panel/settings">{{ $t('Settings') }} ↗</Link></div>
        <div class="dp-automation-table"><table><thead><tr><th>{{ $t('Asset') }} / {{ $t('Network') }}</th><th>{{ $t('Deposit channels') }}</th><th>{{ $t('Automatic collection') }}</th><th>{{ $t('Withdrawals') }}</th><th>{{ $t('Actions') }}</th></tr></thead><tbody>
        <tr v-for="a in filteredAutomation" :key="a.currency_id+'-'+a.network_id"><td><b>{{ a.symbol }}</b><small>{{ a.network_name }}</small></td>
        <td>{{ $t(a.deposit_issue || 'Available') }}<small>{{ $t(a.state || 'No verified channel') }}</small></td>
        <td><span v-if="!a.collection_issues.length">{{ $t('Network automation configured; execution requires fees and verified receipts') }}</span><small v-for="e in a.collection_issues" :key="e">{{ $t(e) }}</small><small v-for="w in a.warnings" :key="w">{{ $t(w) }}</small></td>
        <td>{{ $t(a.withdrawal_issue || 'Withdrawal policy configured; execution is checked separately') }}</td>
        <td><button v-if="a.channel_id" class="dp-button" @click="edit(channels.find(c=>c.id===a.channel_id))">{{ $t('Edit') }}</button><Link :href="'/exchange-control-panel/currencies/'+a.currency_id+'/edit'">{{ $t('Asset') }} ↗</Link><Link v-if="a.chain" :href="'/exchange-control-panel/custody#network-'+a.chain">{{ $t('Wallet custody') }} ↗</Link></td></tr>
        </tbody></table></div>
    </section>
    <section class="dp-channel-form" ref="editor" tabindex="-1">
        <h2>{{ $t('Asset network configuration') }}</h2>
        <form @submit.prevent="save" class="dp-channel-fields">
            <label>{{ $t('Asset') }}<themed-select v-model="form.currency_id" required @change="changeAsset"><option v-for="a in assets" :key="a.id" :value="a.id">{{ a.symbol }}</option></themed-select></label>
            <label>{{ $t('Network') }}<themed-select network v-model="form.network_id" required :disabled="!form.currency_id || !selectableNetworks.length" :placeholder="$t(form.currency_id ? 'Select network' : 'Select an asset first')" @change="changeNetwork"><option v-for="n in selectableNetworks" :key="n.id" :value="n.id">{{ n.name }}</option></themed-select><small v-if="form.currency_id && !selectableNetworks.length">{{ $t('No configurable network for this asset') }}</small></label>
            <label>{{ $t('Contract') }}<input v-model="form.contract" maxlength="100" :disabled="selectedNetwork && selectedNetwork.kind === 'native'"></label>
            <label>{{ $t('Token decimals') }}<input type="number" v-model="form.decimals" min="0" max="18" required></label>
            <label>{{ $t('Confirmations') }}<input type="number" v-model="form.confirmations" min="1" required></label>
            <label>{{ $t('Minimum deposit') }}<input v-model="form.minimum" inputmode="decimal" required></label>
            <label>{{ $t('Fixed deposit fee') }}<input v-model="form.fee_fixed" inputmode="decimal" required></label>
            <label>{{ $t('Deposit fee percent') }}<input v-model="form.fee_percent" inputmode="decimal" required></label>
            <label>{{ $t('Scanner start block') }}<input v-model="form.start_block" type="number" min="0"></label>
            <label>{{ $t('Status') }}<themed-select v-model="form.state"><option v-for="s in states" :key="s" :value="s">{{ $t(s) }}</option></themed-select></label>
            <label>{{ $t('Test account IDs') }}<input v-model="testAccounts" inputmode="numeric" placeholder="101,102"></label>
            <label>{{ $t('Test minimum') }}<input v-model="form.pilot_minimum" inputmode="decimal"></label>
            <label>{{ $t('Test cumulative limit per account') }}<input v-model="form.pilot_limit" inputmode="decimal"></label>
            <div class="dp-channel-evidence" v-if="selectedId">
                <button class="dp-button" type="button" :disabled="probing" @click="preflight">{{ $t('Check node and scan start') }}</button>
                <p v-if="probeError" role="alert">{{ probeError }}</p>
                <div v-if="probe" role="status">
                    <p>{{ $t('Token decimals') }}: {{ probe.actual_decimals }} · {{ $t('Suggested scan start') }}: {{ probe.suggested_start_block === null ? '—' : probe.suggested_start_block }}</p>
                    <p>{{ $t('Legacy deposits requiring reconciliation') }}: {{ probe.legacy_deposit_count }}</p>
                    <button v-if="probe.suggested_start_block !== null && probe.precision_matches" class="dp-button" type="button" @click="form.start_block=probe.suggested_start_block;form.state='draft'">{{ $t('Use suggested scan start') }}</button>
                    <p v-if="!probe.precision_matches" role="alert">{{ $t('Chain identity or token precision verification failed') }}</p>
                </div>
            </div>
            <label class="dp-channel-evidence">{{ $t('Acceptance evidence') }}<textarea v-model="form.acceptance_reference" maxlength="1000"></textarea></label>
            <p v-for="(e,k) in $page.props.errors" :key="k" role="alert">{{ e }}</p>
            <button class="dp-button dp-button--gold" :disabled="saving || !selectedNetwork" type="submit">{{ $t('Save') }}</button>
        </form>
    </section>
    <section class="dp-channel-list" v-for="c in filteredChannels" :key="c.id">
        <header><strong>{{ c.symbol }} · {{ c.network_name }}</strong><button class="dp-button" @click="edit(c)">{{ $t('Edit') }}</button></header>
        <dl><div><dt>{{ $t('Status') }}</dt><dd>{{ $t(c.state) }}</dd></div><div><dt>{{ $t('Contract') }}</dt><dd>{{ c.contract || '—' }}</dd></div>
        <div><dt>{{ $t('Token decimals') }}</dt><dd>{{ c.decimals }}</dd></div><div><dt>{{ $t('Last successful scan') }}</dt><dd>{{ c.scanner && c.scanner.last_success_at || '—' }}</dd></div>
        <div><dt>{{ $t('Checkpoint') }}</dt><dd>{{ c.scanner && c.scanner.scanned_through || '—' }}</dd></div></dl>
        <p v-if="c.pilot_expires_at">{{ $t('Pilot expires') }}: {{ c.pilot_expires_at }} · {{ $t('Verified pilot deposit') }}: {{ c.has_pilot_receipt ? $t('Yes') : $t('No') }}</p>
        <p>{{ c.issue ? $t(c.issue) : $t('Available') }}</p>
    </section>
    <h2 class="mt-8 text-xl">{{ $t('Unrecognized transfers') }}</h2>
    <p>{{ $t('These events do not credit user balances automatically.') }}</p>
    <div class="overflow-x-auto"><table class="w-full"><thead><tr><th>{{ $t('Network') }}</th><th>TX</th><th>{{ $t('Contract') }}</th><th>{{ $t('Status') }}</th></tr></thead>
    <tbody><tr v-for="e in unrecognized" :key="e.id"><td>{{ e.chain }}</td><td>{{ e.txn }} / {{ e.event_index }}</td><td>{{ e.contract }}</td><td>{{ $t(e.reason) }}</td></tr></tbody></table></div>
</app-layout>
</template>
<script>
import AppLayout from '@/Layouts/AdminLayout';
export default {components:{AppLayout},props:{automation:{type:Array,default:()=>[]},channels:Array,assets:Array,unrecognized:Array},
data(){return {channelSearch:'',channelState:'',saving:false,probing:false,probe:null,probeError:'',selectedId:null,testAccounts:'',states:['draft','validated','pilot','tested','active','maintenance'],form:{currency_id:null,network_id:null,contract:'',decimals:18,confirmations:20,minimum:'0',fee_fixed:'0',fee_percent:'0',start_block:null,state:'draft',acceptance_reference:'',pilot_user_ids:[],pilot_minimum:null,pilot_limit:null}}},
watch:{'form.currency_id'(){this.clearProbe()},'form.network_id'(){this.clearProbe()}},
computed:{selectableNetworks(){return (this.assets.find(a=>Number(a.id)===Number(this.form.currency_id))||{}).network_options||[]},selectedNetwork(){return this.selectableNetworks.find(n=>Number(n.id)===Number(this.form.network_id))},filteredAutomation(){return this.automation.filter(this.matchesChannel)},filteredChannels(){return this.channels.filter(this.matchesChannel)}},
mounted(){const query=new URLSearchParams(window.location.search);const asset=this.assets.find(a=>String(a.id)===query.get('asset'));if(asset)this.channelSearch=asset.symbol;const channel=this.channels.find(c=>String(c.id)===query.get('channel'));if(channel)this.edit(channel)},
methods:{
changeAsset(){this.form.network_id=null;this.form.contract='';this.clearProbe()},
changeNetwork(){if(this.selectedNetwork?.kind==='native')this.form.contract='';this.clearProbe()},
matchesChannel(c){return (!this.channelState||c.state===this.channelState)&&[c.symbol,c.network_name,c.contract].join(' ').toLowerCase().includes(this.channelSearch.trim().toLowerCase())},
createDrafts(){this.$inertia.post(this.route('admin.deposit-channels.drafts'),{},{preserveScroll:true,onStart:()=>this.saving=true,onFinish:()=>this.saving=false})},
clearProbe(){this.probe=null;this.probeError='';this.selectedId=(this.channels.find(c=>Number(c.currency_id)===Number(this.form.currency_id)&&Number(c.network_id)===Number(this.form.network_id))||{}).id||null},
edit(c){if(!c)return;Object.keys(this.form).forEach(k=>this.$set(this.form,k,c[k]));this.selectedId=c.id;this.testAccounts=(c.pilot_user_ids||[]).join(',');this.probe=null;this.probeError='';this.$nextTick(()=>{this.$refs.editor.scrollIntoView({behavior:'smooth',block:'start'});this.$refs.editor.focus({preventScroll:true})})},
async preflight(){this.probing=true;this.probe=null;this.probeError='';try{const r=await axios.get(this.route('admin.deposit-channels.preflight',{depositChannel:this.selectedId}));this.probe=r.data}catch(e){this.probeError=this.$t('Chain identity or token precision verification failed')}finally{this.probing=false}},
save(){const ids=this.testAccounts.trim()?this.testAccounts.split(/[,，\s]+/).filter(Boolean):[];this.form.pilot_user_ids=ids;this.$inertia.post(this.route('admin.deposit-channels.store'),this.form,{preserveScroll:true,onStart:()=>this.saving=true,onFinish:()=>this.saving=false})}
}};
</script>
<style scoped>
.dp-automation-actions{display:flex;gap:12px;flex-wrap:wrap;margin:16px 0}.dp-automation-table{overflow-x:auto}.dp-automation-table table{min-width:900px;width:100%;font-size:13px}.dp-automation-table td{max-width:280px;vertical-align:top;border-bottom:1px solid var(--ui-line)}.dp-automation-table small,.dp-automation-table td a{display:block;margin-top:6px;line-height:1.6}.dp-automation-table small{color:var(--ui-muted)}

.dp-channel-form,.dp-channel-list{border:1px solid var(--ui-line);background:var(--ui-surface);padding:20px;border-radius:12px;margin-bottom:16px;color:inherit}
.dp-channel-fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(220px,100%),1fr));gap:16px;margin-top:16px}.dp-channel-fields label{display:flex;flex-direction:column;gap:6px;min-width:0}
.dp-channel-fields input,.dp-channel-fields select,.dp-channel-fields textarea{width:100%;background:var(--ui-field);color:inherit;border:1px solid var(--ui-line);border-radius:6px;padding:8px}.dp-channel-evidence{grid-column:1/-1}
.dp-channel-list header{display:flex;justify-content:space-between;align-items:center;gap:12px}.dp-channel-list dl{display:flex;flex-wrap:wrap;gap:20px;margin:14px 0}.dp-channel-list dd{overflow-wrap:anywhere}.dp-channel-list dl>div{min-width:0;max-width:100%}.dp-channel-list dt{opacity:.7;overflow-wrap:anywhere}.dp-channel-form,.dp-channel-list{overflow-wrap:anywhere}.dp-channel-fields button{max-width:100%;white-space:normal}td,th{padding:10px;text-align:start}
</style>
