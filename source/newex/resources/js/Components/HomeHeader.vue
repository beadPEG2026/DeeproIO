<template>
 <header class="dp-home-header">
  <button type="button" class="dp-home-header__brand" :aria-label="$t('Profile')" @click="openProfile"><img src="/images/deepro-icon.png" alt="Deepro"></button>
  <Link :href="route('markets')" class="dp-home-search"><action-icon name="search"/>{{ $t('Search markets') }}</Link>
  <button type="button" :disabled="scanning || cameraLoading" :aria-label="$t('Scan QR code')" @click="openScanner"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 3h6v6H3Zm12 0h6v6h-6ZM3 15h6v6H3Zm12 0h3v3h-3Zm6 0v6h-6"/></svg></button>
  <Link :href="route('support')" :aria-label="$t('Support')"><action-icon name="support"/></Link>
  <Link :href="route('articles')" :aria-label="$t('Announcements')"><action-icon name="bell"/></Link>
  <input ref="recipientImage" class="dp-home-scan-input" type="file" accept="image/*" :aria-label="$t('Import recipient QR image')" @change="scanImage"/>
  <component :is="cameraComponent" v-if="cameraOpen && cameraComponent" @close="closeScanner" @decoded="cameraDecoded" @import="chooseImage"/>
  <mounting-portal mount-to="body" append>
   <dialog ref="recipientDialog" class="dp-home-recipient" :aria-label="$t('Scanned recipient')" @cancel.prevent="closeRecipient">
    <header><h2>{{$t('Scanned recipient')}}</h2><button type="button" @click="closeRecipient" :aria-label="$t('Close')">×</button></header>
    <p>{{$t('Only the address was imported. Select the asset, then check the network, amount and Memo on the withdrawal page.')}}</p>
    <code>{{recipient ? recipient.address : ''}}</code>
    <asset-picker v-model="selectedAsset" :assets="assets" input-id="scanned-recipient-asset"/>
    <p v-if="assetsLoading" role="status">{{$t('Loading assets…')}}</p>
    <p v-if="assetError" role="alert">{{assetError}} <button type="button" @click="loadAssets">{{$t('Retry')}}</button></p>
    <p v-if="scanError" role="alert">{{scanError}}</p>
    <button type="button" class="dp-home-recipient__continue" :disabled="!selectedAsset || assetsLoading" @click="continueRecipient">{{$t('Continue to withdrawal')}}</button>
   </dialog>
  </mounting-portal>
  <p v-if="!recipient && (scanError || scanning || cameraLoading)" class="dp-home-scan-status" :role="scanError?'alert':'status'">{{ scanError || (cameraLoading ? copy('Opening camera…') : $t('Reading QR code…')) }} <button v-if="scanError || cameraLoading" type="button" @click="closeScanner();scanError=''" :aria-label="$t('Close')">×</button><span v-if="cameraLoadFailed || cameraLoading" class="dp-home-scan-status__actions"><button v-if="cameraLoadFailed" type="button" @click="openScanner">{{copy('Try camera again')}}</button><button type="button" @click="chooseImage">{{copy('Choose QR image')}}</button></span></p>
 </header>
</template>
<script>
import axios from 'axios';
import AssetPicker from './AssetPicker.vue';
import ActionIcon from './ActionIcon.vue';
import {walletUiCopy} from '@/Functions/WalletUiCopy.mjs';
import {scannedRecipient, storeScannedRecipient} from '@/Functions/ScannedRecipient.mjs';
export default {
 components:{ActionIcon,AssetPicker},
 data:()=>({cameraComponent:null,cameraOpen:false,cameraLoading:false,cameraLoadFailed:false,scanning:false,scanError:'',scanEpoch:0,recipient:null,selectedAsset:null,assets:[],assetsLoading:false,assetError:'',assetsRequest:null}),
 beforeDestroy(){this.closeRecipient()},
 methods:{
  copy(key){return walletUiCopy(this,key)},
  openProfile(){window.dispatchEvent(new CustomEvent('deepro:hub',{detail:'profile'}))},
  closeScanner(){this.scanEpoch++;this.cameraOpen=false;this.cameraLoading=false;this.cameraLoadFailed=false;this.scanning=false},
  async openScanner(){
   this.closeScanner();const epoch=this.scanEpoch;this.scanError='';this.cameraLoading=true;
   try{if(!this.cameraComponent){const module=await import(/* webpackChunkName: "camera-qr-scanner" */ './CameraQrScanner.vue');if(epoch!==this.scanEpoch)return;this.cameraComponent=module.default}if(epoch===this.scanEpoch)this.cameraOpen=true}
   catch(_){if(epoch===this.scanEpoch){this.cameraLoadFailed=true;this.scanError=this.copy('Camera is unavailable. Choose a QR image or paste the address.')}}
   finally{if(epoch===this.scanEpoch)this.cameraLoading=false}
  },
  chooseImage(){this.closeScanner();this.scanError='';this.$nextTick(()=>this.$refs.recipientImage?.click())},
  cameraDecoded(value){if(!this.cameraOpen)return;this.closeScanner();this.openRecipient(value)},
  openRecipient(value){
   try{this.recipient=scannedRecipient(value)}catch(error){this.scanError=this.$t(error.message);return}
   this.selectedAsset=null;this.scanError='';
   const recipient=this.recipient,epoch=this.scanEpoch;
   this.$nextTick(()=>{if(epoch!==this.scanEpoch || this.recipient!==recipient)return;this.$refs.recipientDialog?.showModal();this.loadAssets()});
  },
  closeRecipient(){this.closeScanner();this.assetsRequest?.cancel();this.assetsRequest=null;this.assetsLoading=false;this.recipient=null;this.selectedAsset=null;this.$refs.recipientDialog?.close()},
  async loadAssets(){
   if(!this.recipient)return;this.assetsRequest?.cancel();const request=axios.CancelToken.source();this.assetsRequest=request;this.assetsLoading=true;this.assetError='';this.assets=[];this.selectedAsset=null;
   try{const response=await axios.get(this.route('currencies.index'),{timeout:15000,cancelToken:request.token});
    if(request!==this.assetsRequest || !this.recipient)return;
    if(!Array.isArray(response.data.data))throw new Error('invalid_assets');
    this.assets=response.data.data.filter(asset=>asset.type==='coin' && [true,1,'1'].includes(asset.status) && [true,1,'1'].includes(asset.withdraw_status) && !['stock','etf'].includes(asset.asset_category) && /^[A-Za-z0-9_-]{1,30}$/.test(asset.symbol));
    if(!this.assets.length)this.assetError=this.$t('No withdrawal assets are currently available.');
   }catch(error){if(request===this.assetsRequest && !axios.isCancel(error))this.assetError=this.$t('Unable to load assets. Please try again.')}
   finally{if(request===this.assetsRequest)this.assetsLoading=false}
  },
  continueRecipient(){
   if(!this.recipient || !this.assets.some(asset=>asset.symbol===this.selectedAsset) || this.assetsLoading)return;
   try{const token=storeScannedRecipient(this.recipient),symbol=this.selectedAsset;this.closeRecipient();this.$inertia.visit(this.route('wallets.withdraw.crypto',symbol)+'?recipient_scan='+token)}
   catch(_){this.scanError=this.$t('Unable to open the scanned address. Allow browser storage or enter the address manually.')}
  },
  async scanImage(event){
   const file=event.target.files[0];event.target.value='';if(!file)return;
   this.closeScanner();const epoch=this.scanEpoch;this.scanning=true;this.scanError='';
   try{const {readQrImage}=await import(/* webpackChunkName: "qr-image-reader" */ '@/Functions/QrImage');if(epoch!==this.scanEpoch)return;const value=await readQrImage(file);if(epoch===this.scanEpoch)this.openRecipient(value)}
   catch(error){if(epoch===this.scanEpoch)this.scanError=this.$t(['Choose a QR image smaller than 10 MB.','Unable to read this image.','No QR code found. Try a clearer image.'].includes(error.message)?error.message:'Unable to read this image.')}
   finally{if(epoch===this.scanEpoch)this.scanning=false}
  }
 }
};
</script>
<style scoped>
.dp-home-scan-input{display:none}.dp-home-scan-status{position:fixed;z-index:110110;left:50%;transform:translateX(-50%);top:80px;width:min(88vw,420px);padding:12px 16px;border:1px solid var(--ui-divider,#dce1e8);border-radius:12px;background:var(--ui-surface,#fff);color:var(--ui-text,#171d26);font-size:13px;line-height:1.6;box-shadow:0 8px 32px #0002}.dp-home-scan-status button{float:right;border:0;background:transparent;color:inherit;min-width:32px;min-height:32px}
.dp-home-scan-status__actions{display:flex;gap:8px;clear:both;padding-top:10px}.dp-home-scan-status__actions button{float:none;flex:1;padding:8px;border:1px solid var(--ui-divider,#dce1e8);border-radius:8px}
.dp-home-recipient{width:min(92vw,440px);padding:20px;border:1px solid var(--ui-divider,#dce1e8);border-radius:18px;background:var(--ui-surface,#fff);color:var(--ui-text,#171d26);max-height:90dvh;overflow:auto}.dp-home-recipient::backdrop{background:#05090faa}.dp-home-recipient header{display:flex;align-items:center;justify-content:space-between;gap:12px}.dp-home-recipient h2{font-size:19px;margin:0}.dp-home-recipient p{font-size:13px;line-height:1.7}.dp-home-recipient code{display:block;overflow-wrap:anywhere;font-size:13px;margin:16px 0}.dp-home-recipient button{color:inherit}.dp-home-recipient header button{font-size:25px;min-width:44px;min-height:44px;background:transparent;border:0}.dp-home-recipient__continue{margin-top:16px;width:100%;min-height:46px;background:var(--ui-accent,#f4c430);color:#161b24!important;border:0;border-radius:10px}.dp-home-recipient__continue:disabled{opacity:.45}.dp-home-recipient button:focus-visible{outline:2px solid var(--ui-accent,#d9aa12);outline-offset:2px}
</style>
