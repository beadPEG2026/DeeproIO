<template>
  <div class="support-attachment">
    <label :for="inputId">{{ $t('Attachment') }} · JPG / PNG / PDF ≤ 5 MB</label>
    <input :id="inputId" type="file" accept="image/jpeg,image/png,application/pdf" :disabled="busy || disabled" @change="upload" ref="input">
    <p v-if="busy" role="status">{{ $t('Uploading attachment…') }}</p>
    <p v-if="name">{{ name }} <button type="button" @click="remove" :disabled="busy || disabled">{{ $t('Remove') }}</button></p>
    <p v-if="error" role="alert" class="text-red-600">{{ error }}</p>
    <small>{{ $t('Only you and authorized support staff can access new ticket attachments.') }}</small>
  </div>
</template>
<script>
import axios from 'axios';
export default {
  props: { value: [Number, String], disabled: Boolean },
  data: () => ({ name:'', busy:false, error:'', inputId:'attachment-'+crypto.randomUUID() }),
  watch: { value(v) { if (!v) { this.name=''; if (this.$refs.input) this.$refs.input.value=''; } } },
  methods: {
    async upload(event) {
      const file=event.target.files[0]; if (!file) return;
      if (file.size>5*1024*1024) { this.error=this.$t('Attachment must be no larger than 5 MB.'); event.target.value=''; return; }
      const previous=this.value;
      this.busy=true; this.$emit('busy',true); this.error='';
      try {
        const data=new FormData(); data.append('file',file);
        const response=await axios.post(this.route('support.attachments.store'),data);
        this.$emit('input',response.data.uuid); this.name=response.data.name;
        if(previous) await axios.delete(this.route('support.attachments.delete'),{data:{uuid:previous}}).catch(()=>{});
      } catch(e) { this.error=e.response?.data?.errors?.file?.[0] || this.$t('Upload failed. Please try again.'); }
      finally { this.busy=false; this.$emit('busy',false); }
    },
    async remove() {
      this.busy=true;this.$emit('busy',true);this.error='';
      try { await axios.delete(this.route('support.attachments.delete'),{data:{uuid:this.value}});this.$emit('input',null); }
      catch(e) { this.error=this.$t('Could not remove the attachment. Please refresh and try again.'); }
      finally { this.busy=false;this.$emit('busy',false); }
    }
  }
};
</script>
<style scoped>.support-attachment{min-width:0;display:grid;gap:10px;padding:16px;border:1px solid #a58b4866;border-radius:12px}.support-attachment input{width:100%;min-width:0;max-width:100%;box-sizing:border-box}.support-attachment button{text-decoration:underline;padding:4px 10px}</style>
