<template>
 <mounting-portal mount-to="body" append>
  <div class="dp-qr-camera-overlay" @click.self="$emit('close')">
   <section ref="dialog" class="dp-qr-camera" role="dialog" aria-modal="true" :aria-label="copy('Scan QR code')" @keydown="keydown">
    <header><h2>{{copy('Scan QR code')}}</h2><button ref="close" type="button" :aria-label="copy('Close scanner')" @click="$emit('close')">×</button></header>
    <div class="dp-qr-camera__view"><video ref="video" autoplay muted playsinline></video><div v-if="!error" class="dp-qr-camera__guide" aria-hidden="true"></div><p v-if="loading" role="status">{{copy('Opening camera…')}}</p></div>
    <p class="dp-qr-camera__message" :role="error ? 'alert' : 'status'">{{error || copy('Point your camera at the wallet QR code.')}}</p>
    <div class="dp-qr-camera__actions"><button v-if="error" type="button" @click="start">{{copy('Try camera again')}}</button><button type="button" @click="$emit('import')">{{copy('Choose QR image')}}</button></div>
   </section>
  </div>
 </mounting-portal>
</template>
<script>
import jsQR from 'jsqr';
import {walletUiCopy} from '@/Functions/WalletUiCopy.mjs';
export default {
 data:()=>({loading:true,error:'',stream:null,timer:null,epoch:0,previousFocus:null,previousOverflow:''}),
 mounted(){this.previousFocus=document.activeElement;this.previousOverflow=document.body.style.overflow;document.body.style.overflow='hidden';document.addEventListener('visibilitychange',this.visibilityChanged);this.$nextTick(()=>{this.$refs.close?.focus();this.start()})},
 beforeDestroy(){this.stop();document.removeEventListener('visibilitychange',this.visibilityChanged);document.body.style.overflow=this.previousOverflow;this.previousFocus?.focus?.()},
 methods:{
  copy(key){return walletUiCopy(this,key)},
  stop(){this.epoch++;clearTimeout(this.timer);this.timer=null;if(this.stream)this.stream.getTracks().forEach(track=>track.stop());this.stream=null;if(this.$refs.video)this.$refs.video.srcObject=null},
  visibilityChanged(){if(document.hidden)this.$emit('close')},
  async start(){
   this.stop();const epoch=this.epoch;this.loading=true;this.error='';
   try {
    if(!window.isSecureContext || !navigator.mediaDevices?.getUserMedia)throw new Error('unavailable');
    const stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'},width:{ideal:1280},height:{ideal:720}},audio:false});
    if(epoch!==this.epoch){stream.getTracks().forEach(track=>track.stop());return}
    this.stream=stream;this.$refs.video.srcObject=stream;await this.$refs.video.play();
    if(epoch!==this.epoch)return;this.loading=false;
    const canvas=document.createElement('canvas'),context=canvas.getContext('2d',{willReadFrequently:true});
    const scan=()=>{
     if(epoch!==this.epoch)return;
     try {
      const video=this.$refs.video;
      if(video?.readyState>=2 && video.videoWidth && video.videoHeight){
       const scale=Math.min(1,960/Math.max(video.videoWidth,video.videoHeight));canvas.width=Math.round(video.videoWidth*scale);canvas.height=Math.round(video.videoHeight*scale);
       context.drawImage(video,0,0,canvas.width,canvas.height);const pixels=context.getImageData(0,0,canvas.width,canvas.height);const code=jsQR(pixels.data,pixels.width,pixels.height,{inversionAttempts:'attemptBoth'});
       if(code?.data){this.stop();this.$emit('decoded',code.data);return}
      }
      this.timer=setTimeout(scan,150);
     } catch(error) {this.stop();this.loading=false;this.error=this.copy('Camera is unavailable. Choose a QR image or paste the address.')}
    };scan();
   }catch(error){
    if(epoch!==this.epoch)return;this.stop();this.loading=false;
    const key=['NotAllowedError','PermissionDeniedError','SecurityError'].includes(error.name)?'Allow camera access to scan, or choose a QR image.':error.name==='NotReadableError'?'Camera is in use. Close other camera apps and retry.':'Camera is unavailable. Choose a QR image or paste the address.';
    this.error=this.copy(key);
   }
  },
  keydown(event){if(event.key==='Escape'){event.preventDefault();this.$emit('close');return}if(event.key!=='Tab')return;const buttons=[...this.$refs.dialog.querySelectorAll('button:not(:disabled)')],last=buttons[buttons.length-1];if(event.shiftKey&&document.activeElement===buttons[0]){event.preventDefault();last?.focus()}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();buttons[0]?.focus()}},
 }
};
</script>
<style>
.dp-qr-camera-overlay{position:fixed;inset:0;z-index:110100;display:grid;place-items:center;padding:20px;background:#05090fe0;color:#f5f7fa}.dp-qr-camera{width:min(100%,430px);padding:18px;border:1px solid #ffffff24;border-radius:22px;background:#171e28;box-shadow:0 18px 64px #0008}.dp-qr-camera header{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px}.dp-qr-camera h2{margin:0;font-size:19px;font-weight:700}.dp-qr-camera header button{width:42px;height:42px;border:0;border-radius:12px;background:#ffffff12;color:#fff;font-size:28px}.dp-qr-camera__view{position:relative;aspect-ratio:1;overflow:hidden;border-radius:16px;background:#090e15}.dp-qr-camera video{width:100%;height:100%;object-fit:cover}.dp-qr-camera__guide{position:absolute;inset:16%;border:2px solid #f4ca28;border-radius:18px;box-shadow:0 0 0 80px #0004;pointer-events:none}.dp-qr-camera__view>p{position:absolute;inset:40% 8% auto;text-align:center;font-size:14px}.dp-qr-camera__message{font-size:14px;line-height:1.7;color:#d4dbe6;min-height:48px}.dp-qr-camera__actions{display:flex;gap:10px}.dp-qr-camera__actions button{flex:1;min-height:44px;padding:10px;border:1px solid #ffffff24;border-radius:10px;background:#ffffff0f;color:#fff;font-size:14px}.dp-qr-camera button:focus-visible{outline:2px solid #f4ca28;outline-offset:2px}@media(max-width:359px){.dp-qr-camera-overlay{padding:12px}.dp-qr-camera{padding:14px}}
</style>
