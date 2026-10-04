<script>
import {loadSupportChat,hideSupportChat} from "@/Functions/SupportChat.mjs";
import { legacyText } from '@/Functions/LegacyTranslation';

import {contentText} from '@/Functions/ContentText.mjs';
import PrivateAttachment from '@/Components/Support/PrivateAttachment';
import Template from '{Template}/Web/Pages/Support/SupportForm.template'
import AppLayout from '@/Layouts/AppLayout'
import TextUserInput from "@/Jetstream/TextUserInput";
import TButton from "@/Jetstream/Button";
import LoadingButton from "@/Jetstream/LoadingButton";
import TextareaInput from "@/Jetstream/TextareaInput";
import VueRecaptcha from 'vue-recaptcha';

const defaultForm = {
    title: "",
    body: "",
    file_id: null,
    'g-recaptcha-response': false,
};

export default Template({
    components: {
        PrivateAttachment,
        TButton,
        LoadingButton,
        TextUserInput,
        TextareaInput,
        AppLayout,
        VueRecaptcha,
    },

    data() {
        return {
            attachmentBusy: false,
            uploaded: false,
            documentPhoto: null,
            sending: false,
            successNotice: "",
            form: {...defaultForm, request_key:crypto.randomUUID()},
            showOnlineSupportModal: false,
            showTicketForm: false, chatWait:false, chatAttempt:0,

            companyContact: { address: "", email: "" }
        }
    },

    computed: {
        tawkDirectChatUrl(){return this.channels?.chat_url||''},
        availableChannels(){return (this.channels?.order||['tickets','help']).filter(k=>k!=='email'||this.channels.email).filter(k=>k!=='chat'||this.channels.chat_enabled&&this.channels.availability!=='offline')},

        actionButtonTitle: function () {
            return legacyText("Submit");
        },
    },

    props: {
        channels: Object,
        errors: Object,
        success: String,
    },

    mounted() {
        this.showTicketForm=new URLSearchParams(location.search).get("request")==="1";
        window.addEventListener("keydown",this.onEscape);
        if (this.success) {
            this.successNotice = this.success;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    },

    beforeDestroy(){this.chatAttempt++;hideSupportChat();window.removeEventListener("keydown",this.onEscape)},
    methods: {
        text(v){return contentText(v,this.$i18n.locale)},
        onEscape(e){if(e.key==="Escape")this.closeOnlineSupport()},
        openTicketForm(){this.closeOnlineSupport();this.showTicketForm=true;this.$nextTick(()=>this.$refs.ticketForm?.scrollIntoView({behavior:"smooth"}))},
        async openOnlineSupport() {
            const attempt=++this.chatAttempt;
            this.chatWait=false;
            this.showOnlineSupportModal=true;
            this.$nextTick(()=>this.$refs.closeChat?.focus());
            try {
                const api=await loadSupportChat(this.tawkDirectChatUrl);
                if(attempt!==this.chatAttempt || !this.showOnlineSupportModal)return;
                this.showOnlineSupportModal=false;
                api.showWidget();
                api.maximize();
            } catch (_) {
                if(attempt===this.chatAttempt)this.chatWait=true;
            }
        },
        closeOnlineSupport() {
            this.chatAttempt++;
            this.showOnlineSupportModal=false;
            hideSupportChat();
            this.$nextTick(()=>(Array.isArray(this.$refs.chatButton)?this.$refs.chatButton[0]:this.$refs.chatButton)?.focus());
        },

        submit() {
            if (this.sending || this.attachmentBusy) return;

            this.sending = true;

            let afterRequest = {
                onStart: () => {
                    this.sending = true;
                    this.successNotice = "";
                },
                onFinish: () => {
                    this.sending = false;

                    if (this.$refs.recaptcha) {
                        this.$refs.recaptcha.reset();
                    }
                },
                onSuccess: () => {
                    this.sending = false;
                    this.successNotice = this.$t('Your request has been submitted successfully. Our support team will review it and respond as soon as possible over your email.');
                    this.$toast.open(this.$t('Your message was submitted'));
                    this.form = {...defaultForm, request_key:crypto.randomUUID()};
                    this.documentPhoto = null;
                    this.uploaded = false;
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                },
                onError: () => {
                    this.sending = false;
                    this.$toast.error(this.$t('There are some form errors'));
                },
                preserveScroll: true,
                preserveState: true,
            };

            this.$inertia.post(this.route('support.store'), this.form, afterRequest);
        },

        onVerify: function (response) {
            this.form['g-recaptcha-response'] = response;
        },

    }
})
</script>
