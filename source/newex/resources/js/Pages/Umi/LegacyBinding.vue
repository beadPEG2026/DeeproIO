<template><section class="ub-card"><h2>{{ $t("绑定我的原 UMI 账户") }}</h2><p>{{ $t("使用当前 Deepro 账户绑定原 UID。验证码发送到已核定的原账户邮箱，且须与当前登录邮箱一致。原关系和余额不会因绑定重置。") }}</p><form @submit.prevent="submit"><fieldset :disabled="busy" class="ub-fieldset"><label v-if="!challenge">{{ $t("原 UID 或账号") }}<input v-model.trim="identifier" required maxlength="255" /></label><label v-else>{{ $t("8 位邮箱验证码") }}<input v-model.trim="code" required pattern="[0-9]{8}" maxlength="8" inputmode="numeric" autocomplete="one-time-code" /></label><button>{{challenge?$t("验证并绑定"):$t("获取绑定验证码")}}</button><p v-if="message" role="status">{{ $t(message) }}</p><p v-if="error" role="alert" class="ub-error">{{ $t(error) }}</p></fieldset></form><div v-if="challenge"><p>{{ $t("验证码 10 分钟内有效。重发后请使用最新验证码，并检查垃圾邮件。") }}</p><button type="button" :disabled="busy || cooldown > 0" @click="send">{{cooldown>0?cooldown+$t(" 秒后可重新发送"):$t("重新发送验证码")}}</button><button type="button" :disabled="busy" @click="challenge='';code='';message=''">{{ $t("更换原账号信息") }}</button></div><a :href="route('umi.activate')">{{ $t("尚未核定原邮箱或需要人工核验 →") }}</a></section></template>
<script>
import UmiLocale from '@/Functions/UmiLocale';
import { legacyText } from '@/Functions/LegacyTranslation';

import axios from 'axios';
export default {mixins:[UmiLocale],data:()=>({identifier:'',challenge:'',code:'',busy:false,cooldown:0,timer:null,error:'',message:''}),beforeDestroy(){clearInterval(this.timer);},methods:{
 async send(){if(this.busy||this.cooldown>0)return;this.busy=true;this.error='';try{const {data}=await axios.post(this.route('umi.bind.code'),{identifier:this.identifier},{timeout:20000});this.challenge=data.challenge;this.code='';this.message=data.message;this.cooldown=60;clearInterval(this.timer);this.timer=setInterval(()=>{this.cooldown=Math.max(0,this.cooldown-1);if(!this.cooldown)clearInterval(this.timer);},1000);}catch(e){this.failure(e);}finally{this.busy=false;}},
 async submit(){if(!this.challenge)return this.send();if(this.busy)return;this.busy=true;this.error='';try{await axios.post(this.route('umi.bind.complete'),{challenge:this.challenge,code:this.code},{timeout:20000});this.$inertia.reload();}catch(e){this.failure(e);}finally{this.busy=false;}},
 failure(e){this.error=Object.values(e.response?.data?.errors||{}).flat().join('；')|| (e.response?.status===429?legacyText("操作较频繁，请一分钟后重试。"):legacyText("暂时无法绑定，请稍后重试。"));}
}};
</script>
