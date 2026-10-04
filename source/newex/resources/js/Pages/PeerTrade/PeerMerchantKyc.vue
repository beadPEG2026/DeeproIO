<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Web/Pages/PeerTrade/PeerMerchantKyc.template'
import AppLayout from '@/Layouts/AppLayout'
import TextUserInput from "@/Jetstream/TextUserInput";
import TButton from "@/Jetstream/Button";
import LoadingButton from "@/Jetstream/LoadingButton";

const defaultForm = {
    document_type: 'bank_statement',
    address: "",
    postal_code: "",
    city: "",
    state: "",
    file_id: null,
};

export default Template({
    components: {
        TButton,
        LoadingButton,
        TextUserInput,
        AppLayout,
    },
    data() {
        return {
            uploaded: false,
            documentPhoto: null,
            sending: false,
            form: Object.assign({}, defaultForm),
            uploadUrl: this.route('user-file-upload-merchant'),
            uploadHeaders: {
                'X-XSRF-TOKEN' : $cookies.get('XSRF-TOKEN')
            }
        }
    },
    computed: {
        actionButtonTitle: function () {
            return legacyText("Submit");
        },
    },
    props: {
        isVerified: Boolean,
        errors: Object,
        country: String,
        pendingDocument: Object,
        rejectedDocument: Object,
    },
    methods: {
        submit() {

            if(this.sending) return;

            this.sending = true;

            let afterRequest = {
                onStart: () => this.sending = true,
                onFinish: () => this.sending = false,
                onSuccess: () => {
                    this.sending = false;
                    this.$toast.open(this.$t('Your Documents were submitted'));
                },
                onError: () => {
                    this.sending = false;
                    this.$toast.error(this.$t('There are some form errors'));
                },
                preserveScroll: true,
            };

            this.$inertia.post(this.route('p2p.kyc.store'), this.form, afterRequest);
        },
        removePic: function(){
            this.documentPhoto = null;
            this.form.file_id = null;
            this.uploaded = false;
        },
        upload: function(){
            let self = this;
            this.$refs.fileUploadForm.upload(this.uploadUrl, this.uploadHeaders, [this.documentPhoto]).then(function(){
                self.uploaded = true;
            });
        },
        onSelect: function(fileRecords){
            this.upload();
            this.uploaded = false;
        },
        onUpload: function(responses){
            let response = responses[0];
            if (response && !response.error) {
                this.form.file_id = response.data.uuid;
            }
        },
    },
    watch: {
        'form.document': function (type, newType) {
            this.documentPhoto = null;
        },
    }
})
</script>
