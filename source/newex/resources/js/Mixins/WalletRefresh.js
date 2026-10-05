import {walletBalanceReady,walletBalanceVisible} from '@/Functions/WalletBalance.mjs';
export default {
 data:()=>({walletClock:Date.now(),walletRefreshTimer:null}),
 created(){
  if(this.$options.walletOverview===true){
   this.$store.commit('walletBalanceOwner',this.$page.props.user?.id);
   this.$store.commit('walletBalanceSnapshot',this.$page.props.walletSnapshot);
  }
 },
 computed:{
  walletBalanceStatus(){return this.$store.getters.getWalletBalanceStatus || 'idle';},
  walletBalanceReady(){return walletBalanceReady(this.walletBalanceStatus,this.$store.getters.getWalletBalanceUpdatedAt,Math.max(this.walletClock,Date.now()));},
  walletBalanceVisible(){return walletBalanceVisible(this.walletBalanceStatus,this.$store.getters.getWalletBalanceUpdatedAt,Math.max(this.walletClock,Date.now()));},
 },
 mounted(){this.refreshWalletBalances({reuseRecent:this.$options.walletOverview===true});window.addEventListener('online',this.refreshVisibleWallets);window.addEventListener('focus',this.refreshVisibleWallets);document.addEventListener('visibilitychange',this.refreshVisibleWallets);this.walletRefreshTimer=setInterval(this.refreshVisibleWallets,30000);},
 beforeDestroy(){clearInterval(this.walletRefreshTimer);window.removeEventListener('online',this.refreshVisibleWallets);window.removeEventListener('focus',this.refreshVisibleWallets);document.removeEventListener('visibilitychange',this.refreshVisibleWallets);},
 methods:{
  refreshWalletBalances(options={}){this.walletClock=Date.now();return this.$store.dispatch('fetchWallets',{route:this.route('wallets.index'),ownerId:this.$page.props.user?.id,reuseRecent:options.reuseRecent===true}).then(ok=>{this.walletClock=Date.now();return ok;});},
  refreshVisibleWallets(){this.walletClock=Date.now();if(!document.hidden && this.walletBalanceStatus!=='loading' && !this.sending && !this.processing)return this.refreshWalletBalances();},
 }
};
