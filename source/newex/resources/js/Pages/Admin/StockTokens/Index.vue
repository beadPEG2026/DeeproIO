<template>
<admin-layout
    ><div class="stock-admin">
        <span class="umi-eyebrow">DEEPRO ASSET MANAGEMENT</span>
        <h1>{{ $t("币股管理") }}</h1>
        <p>
            {{ $t("Stock quick listing uses the shared currency, wallet and spot market configuration.") }}
        </p>
        <div class="stock-admin-note">
            BSC (BEP-20) · Ondo / bStocks
        </div>
        <form class="stock-onboard" @submit.prevent="onboard">
            <h2>{{ $t("新增币股 / 检查并配置") }}</h2>
            <label>{{ $t("Network") }}<input value="BSC (BEP-20)" disabled /></label>
            <label>{{ $t("资产类型") }}<themed-select v-model="candidate.asset_type"><option value="stock">{{ $t("股票代币") }}</option><option value="etf">{{ $t("ETF 代币") }}</option></themed-select></label>
            <label>{{ $t("代币合约") }}<input v-model.trim="candidate.contract" :placeholder="$t(&quot;0x…&quot;)" required pattern="0x[0-9a-fA-F]{40}" /></label>
            <label>{{ $t("显示名称（可选）") }}<input v-model.trim="candidate.name" maxlength="80" /></label>
            <button :disabled="onboarding">{{ onboarding ? $t("正在核验与配置…") : $t("自动核验并配置") }}</button>

            <p v-if="onboardError" class="text-red-500" role="alert">{{ onboardError }}</p>
        </form>
        <div v-if="saved" class="stock-admin-saved" role="status">
            {{ $t("设置已保存") }}
        </div>
        <div class="stock-admin-list">
            <article v-for="a in assets" :key="a.symbol">
                <div class="stock-admin-title">
                    <b>{{ a.name }}</b
                    ><span
                        >{{ a.symbol }} ·
                        {{
                            a.assetType === "etf" ? "ETF" : $t("股票代币")
                        }}</span
                    >
                </div>
                <div class="stock-admin-contract">
                    <span>BSC · {{ a.issuer }} · {{ a.unit }} · {{ a.decimals }} {{ $t("位精度") }}</span
                    ><a
                        :href="a.explorerUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        >{{ a.contract }}</a
                    ><small v-if="a.validation">{{ $t("身份 / 单位 / 行情 / K 线：已核验") }}</small><small>{{ $t("交易：") }}{{ a.tradeEnabled ? $t("已配置") : $t("未开启") }} {{ $t("· 深度：") }}{{ a.depthReady ? $t("可用") : $t("等待更新") }} {{ $t("· 充提入口：") }}{{ a.depositEnabled && a.withdrawEnabled ? $t("已配置") : $t("待配置") }}</small><small
                        >{{ $t("合约核验：") }}{{
                            new Date(a.verifiedAt).toLocaleString($i18n.locale)
                        }}</small
                    >
                    <div class="stock-admin-links">
                        <Link v-if="a.currencyId" :href="route('admin.currencies.edit', a.currencyId)">{{ $t('Manage asset') }}</Link>
                        <Link v-if="a.marketId" :href="route('admin.markets.edit', a.marketId)">{{ $t('Manage market') }}</Link>
                        <Link :href="route('admin.deposit-channels')">{{ $t('Deposit channels') }}</Link>
                    </div>
                </div>
                <form @submit.prevent="save(a)">
                    <label
                        >{{ $t("市场展示") }}<themed-select
                            v-model="forms[a.symbol].display_enabled"
                        >
                            <option :value="true">{{ $t("展示") }}</option>
                            <option :value="false">{{ $t("隐藏") }}</option>
                        </themed-select></label
                    ><label
                        >{{ $t("默认 K 线") }}<themed-select
                            v-model="forms[a.symbol].default_interval"
                        >
                            <option
                                v-for="p in intervals"
                                :key="p"
                                :value="p"
                            >
                                {{ p }}
                            </option>
                        </themed-select></label
                    ><button :disabled="busy === a.symbol">
                        {{
                            busy === a.symbol ? $t("保存中…") : $t("保存设置")
                        }}</button
                    ><small v-if="errors[a.symbol]" class="text-red-500">{{
                        errors[a.symbol]
                    }}</small>
                </form>
            </article>
        </div>
    </div></admin-layout
>
</template>
<script>
import AdminLayout from "@/Layouts/AdminLayout";
export default {
    components: { AdminLayout },
    props: { assets: Array, intervals: Array },
    data() {
        return {
            candidate:{contract:'',name:'',asset_type:'stock'}, onboarding:false, onboardError:'',
            forms: Object.fromEntries(
                this.assets.map((a) => [
                    a.symbol,
                    {
                        display_enabled: a.displayEnabled,
                        default_interval: a.defaultInterval,
                    },
                ])
            ),
            busy: null,
            saved: false,
            errors: {},
        };
    },
    methods: {
        onboard() {
            this.onboarding=true;this.onboardError='';this.saved=false;
            this.$inertia.post(this.route('admin.stock-tokens.store'),this.candidate,{
                preserveScroll:true,
                onSuccess:()=>{this.saved=true;this.candidate={contract:'',name:'',asset_type:'stock'};this.forms=Object.fromEntries(this.assets.map(a=>[a.symbol,{display_enabled:a.displayEnabled,default_interval:a.defaultInterval}]))},
                onError:e=>{this.onboardError=Object.values(e).join(' ')},
                onFinish:()=>{this.onboarding=false}
            });
        },
        save(a) {
            this.busy = a.symbol;
            this.saved = false;
            this.$delete(this.errors, a.symbol);
            this.$inertia.put(
                this.route("admin.stock-tokens.update", a.symbol),
                this.forms[a.symbol],
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        this.saved = true;
                    },
                    onError: (e) => {
                        this.$set(
                            this.errors,
                            a.symbol,
                            Object.values(e).join(" ")
                        );
                    },
                    onFinish: () => {
                        this.busy = null;
                    },
                }
            );
        },
    },
};
</script>
<style scoped>
.stock-admin-links{display:flex;gap:14px;flex-wrap:wrap;margin-top:8px}.stock-admin-links a{font-family:inherit;text-decoration:underline;}
.stock-onboard {background:var(--ui-field);border:1px solid var(--ui-line);border-radius:14px;padding:24px;display:grid;gap:14px;margin-bottom:24px;}
.stock-onboard h2{font-size:18px;font-weight:700}.stock-onboard label{display:grid;gap:6px}.stock-onboard input{border:1px solid var(--ui-line);border-radius:8px;padding:10px;max-width:640px;width:100%}.stock-onboard button{background:var(--ui-umi-accent);padding:12px;border-radius:8px;width:max-content;font-weight:700}.stock-onboard p{font-size:12px;color:var(--ui-muted);line-height:1.7}
.stock-admin {
    padding: 36px;
    max-width: 1380px;
    margin: auto;
}
.stock-admin h1 {
    font-size: 28px;
    font-weight: 600;
    margin: 12px 0;
}
.stock-admin > p {
    color: var(--ui-muted);
    font-size: 12px;
    line-height: 1.9;
    max-width: 760px;
}
.stock-admin-note {
    background: var(--ui-field);
    color: var(--ui-muted);
    padding: 14px 18px;
    border-radius: 9px;
    margin: 24px 0;
    font-size: 12px;
}
.stock-admin-list {
    display: grid;
    gap: 15px;
}
.stock-admin-list article {
    display: grid;
    grid-template-columns: 170px minmax(250px, 1fr) 330px;
    align-items: center;
    gap: 24px;
    padding: 23px;
    background: var(--ui-surface);
    border: 1px solid var(--ui-line);
    border-radius: 12px;
}
.stock-admin-title {
    display: grid;
    gap: 8px;
}
.stock-admin-title b {
    font-size: 16px;
}
.stock-admin-title span,
.stock-admin-contract span,
.stock-admin-contract small {
    font-size: 11px;
    color: var(--ui-muted);
}
.stock-admin-contract {
    display: grid;
    gap: 7px;
    min-width: 0;
}
.stock-admin-contract a {
    font-size: 11px;
    font-family: monospace;
    overflow-wrap: anywhere;
    color: var(--ui-text);
}
.stock-admin-list form {
    display: flex;
    gap: 12px;
    align-items: end;
}
.stock-admin-list label {
    font-size: 11px;
    color: var(--ui-muted);
    display: grid;
    gap: 6px;
}
.stock-admin-list select {
    font-size: 12px;
    padding: 7px 26px 7px 10px;
    min-height: 34px;
    background: var(--ui-surface);
}
.stock-admin-list button {
    background: var(--ui-umi-accent);
    padding: 9px 14px;
    border-radius: 6px;
    font-size: 11px;
    white-space: nowrap;
    color: var(--ui-on-accent);
}
.stock-admin-saved {
    color: var(--ui-text);
    margin-bottom: 16px;
    font-size: 12px;
}
@media (max-width: 1100px) {
    .stock-admin-list article {
        grid-template-columns: 150px 1fr;
    }
    .stock-admin-list form {
        grid-column: span 2;
    }
}
@media (max-width: 600px) {
    .stock-admin {
        padding: 25px 16px;
    }
    .stock-admin-list article {
        grid-template-columns: 1fr;
        padding: 20px;
        gap: 17px;
    }
    .stock-admin-list form {
        grid-column: span 1;
        flex-wrap: wrap;
    }
    .stock-admin h1 {
        font-size: 24px;
    }
}
</style>
