import Vue from 'vue';
import {sessionView} from '@/Functions/MarketSession.mjs';
const clock=Vue.observable({now:performance.now()});
let users=0,timer=null;
export default {
    data:()=>({sessionOffset:null}),
    computed:{
        sessionMarket(){const m=this.market?.data || this.market;return {...m,...(this.$store.getters.getMarket(m?.name)||{})};},
        sessionData(){return this.sessionMarket.trading_session;},
        sessionState(){return sessionView(this.sessionMarket,clock.now+this.sessionOffset);},
        sessionBlocked(){return this.sessionState.blocked;},
        sessionLabel(){return this.sessionState.label;},
        nextSession(){const t=this.sessionData?.next_open_at;return t?this.hongKongTime(t):'';},
    },
    watch:{sessionData:{immediate:true,handler(s){clock.now=performance.now();this.sessionOffset=s?Date.parse(s.server_time)-clock.now:null;}}},
    mounted(){if(!this.sessionMarket.price_reference_product)return;this.sessionSubscribed=true;users++;if(!timer)timer=setInterval(()=>{clock.now=performance.now();},500);},
    beforeDestroy(){if(this.sessionSubscribed && --users===0){clearInterval(timer);timer=null;}},
    methods:{hongKongTime(value){return new Intl.DateTimeFormat(this.$i18n.locale,{timeZone:'Asia/Hong_Kong',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).format(new Date(value));}}
};
