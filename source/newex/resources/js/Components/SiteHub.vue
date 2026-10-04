<template>
    <dialog ref="dialog" class="dp-site-hub" :aria-label="title" @cancel.prevent="cancel">
        <div class="dp-site-hub__inner">
            <brand-caption/>
            <header class="dp-site-hub__header"><button type="button" @click="back" :aria-label="t('返回','Back')">‹</button><h1>{{ title }}</h1><button type="button" @click="$emit('close')" :aria-label="t('关闭','Close')">×</button></header>
            <template v-if="section === 'profile'">
                <div class="dp-hub-account dp-hub-account--identity">
                    <Link :href="accountLink" :aria-label="t('个人资料','Profile')"><img src="/images/deepro-icon.png" alt=""></Link>
                    <div class="dp-hub-account__details">
                        <Link :href="accountLink"><strong>{{ $page.props.user ? ($page.props.user.name || $page.props.user.email) : t('登录 / 注册','Sign in / Register') }}</strong></Link>
                        <div v-if="$page.props.user" class="dp-hub-uid"><small>UID {{ accountUid }}</small><button type="button" class="dp-hub-copy-icon" :disabled="accountUid === '—'" :aria-label="t('复制 UID','Copy UID')" @click="copyUid"><svg viewBox="0 0 20 20" aria-hidden="true"><rect x="7" y="7" width="10" height="10" rx="2"/><path d="M12 4V3H3v9h1"/></svg></button></div>
                        <small v-else>{{ t('开启你的数字资产生活','Your digital asset journey starts here') }}</small>
                        <button v-if="internalRecipientCode" ref="recipientCodeLink" type="button" class="dp-hub-recipient-link" @click="openRecipientCode">{{ t('内部收款码','Internal recipient code') }} <b aria-hidden="true">›</b></button>
                    </div>
                    <Link :href="accountLink" class="dp-hub-account__arrow" aria-hidden="true" tabindex="-1">›</Link>
                </div>
                <nav class="dp-hub-card"><button v-for="item in profileItems" :key="item.label" type="button" @click="activate(item)"><action-icon :name="item.icon"/><span>{{ item.label }}</span><b>›</b></button></nav>
                <button v-if="$page.props.user" type="button" class="dp-hub-signout" @click="$inertia.post(route('logout'))">{{ t('退出登录','Log out') }}</button>
            </template>
            <template v-else-if="section === 'about'">
                <div class="dp-hub-brand"><img src="/images/deepro-icon.png" alt=""><h2>Deepro</h2><p>{{ t('数字资产交易平台','Digital asset exchange') }}</p></div>
                <section v-for="group in aboutGroups" :key="group.title" class="dp-hub-group"><h2>{{ group.title }}</h2><nav class="dp-hub-card"><button v-for="item in group.links" :key="item.path" type="button" @click="activate(item)"><span>{{ item.label }}</span><b>›</b></button></nav></section>
                <p class="dp-hub-copyright">Deepro © {{ new Date().getFullYear() }}</p>
            </template>
            <template v-else-if="section === 'preferences'">
                <section class="dp-hub-preferences"><h2>{{ t('外观','Appearance') }}</h2><theme-mode/>
                    <div class="dp-hub-preference-rows">
                        <button ref="currencyPreference" type="button" class="dp-hub-preference-row" @click="openPreference('currency')"><span>{{ t('显示币种','Display currency') }}</span><strong>{{ currencyLabel(displayPreferences.currency) }}</strong><b aria-hidden="true">›</b></button>
                        <button ref="colorsPreference" type="button" class="dp-hub-preference-row" @click="openPreference('colors')"><span>{{ t('涨跌颜色','Market colors') }}</span><strong>{{ marketColorsLabel }}</strong><b aria-hidden="true">›</b></button>
                    </div>
                    <h2>{{ t('语言','Language') }}</h2><language-switcher v-if="$page.props.lang_mode_enabled"/>
                </section>
            </template>
            <template v-else-if="preferenceKind">
                <section ref="preferenceChoices" class="dp-hub-choice-list" :aria-label="title">
                    <button v-for="option in preferenceOptions" :key="option.value" type="button" :aria-pressed="displayPreferences[preferenceKind] === option.value ? 'true' : 'false'" @click="choosePreference(option.value)"><span>{{ option.label }}</span><b v-if="displayPreferences[preferenceKind] === option.value" aria-hidden="true">✓</b></button>
                </section>
            </template>
            <template v-else-if="section === 'recipient-code'">
                <section class="dp-hub-recipient-code">
                    <p>{{ t('将此码提供给付款人，用于 Deepro 内部转账。此码不是 UMI 邀请码。','Share this code with the sender for a Deepro internal transfer. This is not a UMI invitation code.') }}</p>
                    <code>{{ internalRecipientCode || '—' }}</code>
                    <button ref="recipientCopy" type="button" :disabled="!internalRecipientCode" @click="copyRecipientCode">{{ t('复制内部收款码','Copy internal recipient code') }}</button>
                </section>
            </template>
            <template v-else>
                <discovery-panel @close="$emit('close')"/>
            </template>
            <div v-if="manualCopy" class="dp-manual-copy"><label>{{ t('请长按复制','Press and hold to copy') }}<input readonly :value="manualCopy" @focus="$event.target.select()"></label><button type="button" @click="manualCopy=''">{{ t('关闭','Close') }}</button></div>
            <p v-if="message" class="dp-service-toast" role="status">{{ message }}</p>
        </div>
        <bottom-menu class="dp-hub-bottom" @click.native="$emit('close')" />
    </dialog>
</template>
<script>
import {copyText} from "@/Functions/Clipboard.mjs";
import DisplayPreferences from '@/Mixins/DisplayPreferences';
import ActionIcon from './ActionIcon.vue';
import ThemeMode from './ThemeMode';
import LanguageSwitcher from './LanguageSwitcher';
import BottomMenu from './BottomMenu';
import {contentText} from '@/Functions/ContentText.mjs';
import {formatAccountUid} from '@/Functions/AccountUid.mjs';
import DiscoveryPanel from './DiscoveryPanel.vue';
import BrandCaption from './BrandCaption.vue';
import './site-hub-refinement.css';
export default {
    mixins:[DisplayPreferences],components:{ActionIcon,ThemeMode,LanguageSwitcher,BottomMenu,DiscoveryPanel,BrandCaption},props:{initial:{type:String,default:'profile'}},data(){return {section:this.initial,message:'',manualCopy:'',clearingCache:false}},
    computed:{
        title(){return this.section==='preference-currency'?this.t('显示币种','Display currency'):this.section==='preference-colors'?this.t('涨跌颜色','Market colors'):this.section==='recipient-code'?this.t('内部收款码','Internal recipient code'):this.section==='about'?this.t('关于 Deepro','About Deepro'):this.section==='discover'?this.t('发现','Discover'):this.section==='preferences'?this.t('偏好设置','Preferences'):this.t('个人中心','Account')},
        accountLink(){return this.$page.props.user ? this.route('profile.show') : this.route('login')},
        accountUid(){return formatAccountUid(this.$page.props.user?.id)},
        internalRecipientCode(){return String(this.$page.props.user?.referral_code || '')},
        preferenceKind(){return this.section==='preference-currency'?'currency':this.section==='preference-colors'?'colors':null},
        marketColorOptions(){return [{value:'green-up',label:this.t('绿涨红跌','Green up / red down')},{value:'red-up',label:this.t('红涨绿跌','Red up / green down')}]},
        marketColorsLabel(){return this.marketColorOptions.find(option=>option.value===this.displayPreferences.colors)?.label},
        preferenceOptions(){return this.preferenceKind==='currency'?this.displayCurrencies.map(currency=>({value:currency.symbol,label:this.currencyLabel(currency.symbol)})):this.marketColorOptions},
        profileItems(){return [
            {label:this.t('邀请好友','Invite friends'),icon:'invite',path:this.route('reports.referral-transactions')},
            {label:this.t('安全中心','Security'),icon:'shield',path:this.route('profile.show',{slug:'2fa',lite:1})},
            {label:this.t('偏好设置','Preferences'),icon:'settings',action:'preferences'},
            {label:this.t('清理缓存','Clear cache'),icon:'refresh',action:'cache'},
            {label:this.t('在线客服','Support'),icon:'support',path:this.route('support')},
            {label:this.t('分享应用','Share Deepro'),icon:'share',action:'share'},
            {label:this.t('关于 Deepro','About Deepro'),icon:'info',action:'about'}
        ]},
        aboutGroups(){
            const nav=this.$page.props.siteNavigation||{};
            const fallback=[
                {title:this.t('交易与生态','Trade and ecosystem'),links:[{path:'/markets',label:this.t('市场','Markets')},{path:'/stocks',label:this.t('股票','Stocks')},{path:'/umi-ecosystem',label:'UMI '+this.t('生态','Ecosystem')}]},
                {title:this.t('帮助与服务','Help and services'),links:[{path:'/support',label:this.t('客服中心','Support')},{path:'/faq',label:this.t('帮助中心','Help Center')},{path:'/fees',label:this.t('手续费','Fees')},{path:'/trading-rules',label:this.t('交易规则','Trading rules')},{path:'/download',label:this.t('下载客户端','Download App')}]},
                {title:this.t('平台与法律信息','About and legal'),links:[{path:'/about',label:this.t('品牌与平台介绍','About our platform')},{path:'/terms',label:this.t('使用条款','Terms of Use')},{path:'/privacy-gdpr',label:this.t('隐私政策','Privacy Policy')},{path:'/disclosure',label:this.t('风险声明与重要信息披露','Disclosure Statement')}]}
            ];
            // Missing editorial material remains discoverable, but explicit publication controls win.
            const suppressed=new Set(Array.isArray(nav.suppressed_paths)?nav.suppressed_paths:[]);
            for(const group of nav.groups||[])for(const link of group.links||[])if(link.visible===false)suppressed.add(link.path);
            if(!nav.api_public)suppressed.add('/docs/api');
            if(suppressed.has('/umi-ecosystem'))suppressed.add('/umi-ecosystem/portfolio');
            const groups=(Array.isArray(nav.groups)?nav.groups:[]).map(g=>({title:contentText(g.title,this.$i18n.locale),links:(g.links||[]).filter(l=>l.visible!==false && !suppressed.has(l.path)).map(l=>({path:l.path,label:contentText(l.label,this.$i18n.locale),unavailable:!!l.unavailable}))}));
            for(const group of fallback){
                const missing=group.links.filter(link=>!suppressed.has(link.path) && !groups.some(g=>g.links.some(item=>item.path===link.path))).map(link=>({...link,unavailable:true}));
                if(missing.length){const matching=groups.find(g=>g.links.some(link=>group.links.some(item=>item.path===link.path)));if(matching)matching.links.push(...missing);else groups.push({title:group.title,links:missing});}
            }
            const help=groups.find(g=>g.links.some(l=>l.path==='/faq'))||groups[0];
            if(help)[{path:this.route('reports.referral-transactions'),label:this.t('返佣中心','Referral Center')},{path:this.route('stakings',{staking_type:0}),label:this.t('理财中心','Wealth')},{path:'/docs/api',label:this.t('API 文档','API Documentation')}].forEach(l=>{if(!suppressed.has(l.path) && !groups.some(g=>g.links.some(i=>i.path===l.path)))help.links.push(l)});
            return groups.filter(g=>g.links.length);
        }
    },
    mounted(){this.$refs.dialog.showModal()},beforeDestroy(){clearTimeout(this.timer);this.$refs.dialog?.close()},
    methods:{
        async copyValue(value,success){if(!value || value==='—')return;try{await copyText(value,this.$refs.dialog);this.manualCopy='';this.toast(success)}catch(_){this.manualCopy=String(value);this.toast(this.t('请长按复制','Press and hold to copy'))}},
        copyUid(){return this.copyValue(this.accountUid,this.t('UID 已复制','UID copied'))},
        copyRecipientCode(){return this.copyValue(this.internalRecipientCode,this.t('内部收款码已复制','Internal recipient code copied'))},
        openRecipientCode(){this.message='';this.section='recipient-code';this.$nextTick(()=>this.$refs.recipientCopy?.focus())},
        openPreference(kind){if(!['currency','colors'].includes(kind))return;this.message='';this.section='preference-'+kind;this.$nextTick(()=>this.$refs.preferenceChoices?.querySelector('[aria-pressed="true"]')?.focus())},
        choosePreference(value){const kind=this.preferenceKind;if(!kind || !this.preferenceOptions.some(option=>option.value===value))return;if(!this.saveDisplayPreferences({...this.displayPreferences,[kind]:value})){this.toast(this.t('保存失败，原设置已保留。请允许浏览器存储后重试。','Unable to save. Your previous setting is unchanged. Please allow browser storage and retry.'));return}this.back()},
        t(zh,en){return this.$t(en)},
        cancel(){if(this.preferenceKind || this.section==='recipient-code')this.back();else this.$emit('close')},
        back(){const kind=this.preferenceKind;this.message='';if(kind){this.section='preferences';this.$nextTick(()=>this.$refs[kind+'Preference']?.focus())}else if(this.section==='recipient-code'){this.section='profile';this.$nextTick(()=>this.$refs.recipientCodeLink?.focus())}else if(['about','preferences'].includes(this.section))this.section='profile';else this.$emit('close')},
        toast(message){this.message=message;clearTimeout(this.timer);this.timer=setTimeout(()=>this.message='',2600)},
        async activate(item){
            if(item.unavailable)return this.toast(this.t('暂未开通此功能','This feature is not available yet'));
            if(item.path){this.$emit('close');return this.$inertia.visit(item.path)}
            if(['about','preferences'].includes(item.action)){this.section=item.action;return}
            if(item.action==='share'){try{await copyText(window.location.origin,this.$refs.dialog);this.toast(this.t('链接已复制','Link copied'))}catch(_){this.toast(this.t('复制失败，请重试','Copy failed. Please try again.'))}return}
            if(item.action==='cache'){
                if(this.clearingCache)return;
                if(!('caches' in window)){this.toast(this.t('此浏览器不支持清理应用缓存','This browser does not support clearing the app cache.'));return}
                this.clearingCache=true;
                try{const names=(await window.caches.keys()).filter(n=>n.startsWith('deepro-static-'));
                    if(!names.length)this.toast(this.t('没有需要清理的应用缓存','No app cache to clear.'));
                    else{await Promise.all(names.map(n=>window.caches.delete(n)));this.toast(this.t('应用缓存已清理','App cache cleared.'))}
                }catch(_){this.toast(this.t('清理失败，请稍后重试','Unable to clear the cache. Please try again.'))}
                finally{this.clearingCache=false}return
            }
            this.toast(this.t('暂未开通此功能','This feature is not available yet'));
        }
    }
}
</script>
