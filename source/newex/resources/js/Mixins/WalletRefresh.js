import {walletBalanceReady} from '@/Functions/WalletBalance.mjs';
export default {
 data:()=>({walletClock:Date.now(),walletRefreshTimer:null}),
 computed:{
  walletBalanceStatus(){return this.$store.getters.getWalletBalanceStatus || 'idle';},
  walletBalanceReady(){return walletBalanceReady(this.walletBalanceStatus,this.$store.getters.getWalletBalanceUpdatedAt,Math.max(this.walletClock,Date.now()));},
 },
 mounted(){this.refreshWalletBalances();window.addEventListener('online',this.refreshVisibleWallets);window.addEventListener('focus',this.refreshVisibleWallets);document.addEventListener('visibilitychange',this.refreshVisibleWallets);this.walletRefreshTimer=setInterval(this.refreshVisibleWallets,30000);},
 beforeDestroy(){clearInterval(this.walletRefreshTimer);window.removeEventListener('online',this.refreshVisibleWallets);window.removeEventListener('focus',this.refreshVisibleWallets);document.removeEventListener('visibilitychange',this.refreshVisibleWallets);},
 methods:{
  refreshWalletBalances(){this.walletClock=Date.now();return this.$store.dispatch('fetchWallets',this.route('wallets.index')).then(ok=>{this.walletClock=Date.now();return ok;});},
  refreshVisibleWallets(){this.walletClock=Date.now();if(!document.hidden && this.walletBalanceStatus!=='loading' && !this.sending && !this.processing)return this.refreshWalletBalances();},
 }
};
