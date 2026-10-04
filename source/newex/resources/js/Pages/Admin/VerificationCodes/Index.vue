<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/VerificationCodes/Index.template'
import AppLayout from '@/Layouts/AdminLayout'

export default Template({
    components: {
        AppLayout,
    },
    data() {
        return {
            loading: false,
            form: {
                account: '',
            },
            result: null,
            errorMessage: '',
            copied: false,
        }
    },
    computed: {
        queryUrl() {
            return window.location.pathname.replace(/\/$/, '') + '/query';
        },
        hasCode() {
            return this.result && this.result.found;
        },
    },
    methods: {
        queryCode() {
            const account = (this.form.account || '').trim();

            this.result = null;
            this.errorMessage = '';
            this.copied = false;

            if (!account) {
                this.errorMessage = legacyText("请输入邮箱或手机号");
                return;
            }

            this.loading = true;

            axios.post(this.queryUrl, {account}).then((response) => {
                this.result = response.data.data;
            }).catch((error) => {
                this.result = error.response && error.response.data && error.response.data.data
                    ? error.response.data.data
                    : null;

                this.errorMessage = error.response && error.response.data && error.response.data.message
                    ? error.response.data.message
                    : legacyText("查询失败，请稍后重试");
            }).finally(() => {
                this.loading = false;
            });
        },
        reset() {
            this.form.account = '';
            this.result = null;
            this.errorMessage = '';
            this.copied = false;
        },
    },
})
</script>
