<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/Settings/Index.template'
import AppLayout from '@/Layouts/AdminLayout'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import TextInput from '@/Jetstream/TextInput'
import TextareaInput from "@/Jetstream/TextareaInput";
import LoadingButton from "@/Jetstream/LoadingButton";
import TrashedMessage from "@/Jetstream/TrashedMessage";
import SelectInput from "@/Jetstream/SelectInput";
import EmptyColumn from "@/Jetstream/EmptyColumn";
import Badge from "@/Jetstream/Badge";

export default Template({
    components: {
        NavButtonLink,
        AppLayout,
        TextInput,
        TextareaInput,
        LoadingButton,
        TrashedMessage,
        SelectInput,
        EmptyColumn,
        Badge
    },
    props: {
        isEdit: {
            type: Boolean,
            default: false,
        },
        errors: Object,
        general: Object,
        trade: Object,
        mail: Object,
        coinpayments: Object,
        ethereum: Object,
        bnb: Object,
        customtoken: Object,
        bitcoin: Object,
        polygon: Object,
        xlayer: Object,
        solana: Object,
        ripple: Object,
        tron: Object,
        ton: Object,
        recaptcha: Object,
        stripe: Object,
        unlimit: Object,
        notification: Object,
        social: Object
    },
    data() {
        return {
            validationErrors: {},
            form: {},
            sending: false,
            uploadHeaders: {
                'X-XSRF-TOKEN' : $cookies.get('XSRF-TOKEN')
            },
            uploadUrl: this.route('file-upload'),
            siteLogo: null,
            uploaded: false,
            section: 'general',
            syncingCoins: false,
            coinpaymentsCurrencies: [],
            // Unlimit configuration
            unlimitConfig: null,
            unlimitConfigError: '',
            loadingUnlimitConfig: false,
            syncingUnlimit: false,
        }
    },
    computed: {
        subTitle: function () {
            return legacyText("Settings");
        },
        actionButtonTitle: function () {
            return 'Update Settings';
        },
    },
mounted() {
    this.applyGeneralDownloadDefaults();
    this.applyTradeVipBoostDefaults();
    this.applyUnlimitDefaults();

    if(this.general.logo) {
        this.siteLogo = {
            name: '',
            type: 'image',
            url: this.general.logo
        }
    }

    this.ensureTradeVipBoostDefaults();
},
methods: {
    applyUnlimitDefaults() {
        const defaults = {
            exchange_rate: '',
            processing_fee: '',
        };

        Object.keys(defaults).forEach((key) => {
            if (this.unlimit[key] === undefined || this.unlimit[key] === null) {
                this.$set(this.unlimit, key, defaults[key]);
            }
        });
    },

    applyGeneralDownloadDefaults() {
        const defaults = {
            android_download_url: '',
            ios_download_url: '',
        };

        Object.keys(defaults).forEach((key) => {
            if (this.general[key] === undefined || this.general[key] === null) {
                this.$set(this.general, key, defaults[key]);
            }
        });
    },

    applyTradeVipBoostDefaults() {
        const defaults = {
            lc_vip_1_boost_percent: '10',
            lc_vip_2_boost_percent: '20',
            lc_vip_3_boost_percent: '30',
            lc_vip_4_boost_percent: '40',
            lc_vip_5_boost_percent: '50',
            lc_vip_6_boost_percent: '60',
            lc_vip_7_boost_percent: '70',
            lc_vip_8_boost_percent: '80',
        };

        Object.keys(defaults).forEach((key) => {
            if (this.trade[key] === undefined || this.trade[key] === null || this.trade[key] === '') {
                this.$set(this.trade, key, defaults[key]);
            }
        });
    },
        ensureTradeVipBoostDefaults() {
            const defaults = {
                lc_vip_1_boost_percent: '10',
                lc_vip_2_boost_percent: '20',
                lc_vip_3_boost_percent: '30',
                lc_vip_4_boost_percent: '40',
                lc_vip_5_boost_percent: '50',
                lc_vip_6_boost_percent: '60',
                lc_vip_7_boost_percent: '70',
                lc_vip_8_boost_percent: '80',
            };

            Object.keys(defaults).forEach((key) => {
                if (this.trade[key] === undefined || this.trade[key] === null || this.trade[key] === '') {
                    this.$set(this.trade, key, defaults[key]);
                }
            });
        },

        loadUnlimitConfig(force = false) {
            if (this.loadingUnlimitConfig || (this.unlimitConfig && !force)) return;
            this.loadingUnlimitConfig = true;
            this.unlimitConfigError = '';
            axios.get(this.route('admin.unlimit.config')).then(res => {
                if (res.data && res.data.success) {
                    this.unlimitConfig = res.data.data;
                } else {
                    this.unlimitConfigError = '暂时无法加载入金选项，请稍后重试。';
                }
            }).catch(error => {
                this.unlimitConfigError = error.response?.data?.configured === false
                    ? '请先完善 Unlimit 服务配置，再加载入金选项。'
                    : '暂时无法加载入金选项，请稍后重试。';
            }).finally(() => {
                this.loadingUnlimitConfig = false;
            });
        },
        syncUnlimit() {
            if (this.syncingUnlimit) {
                this.$toast.open('Sync is still in progress');
                return;
            }
            this.$toast.open('Sync process started');
            this.syncingUnlimit = true;
            axios.post(this.route('admin.unlimit.sync')).then(res => {
                this.syncingUnlimit = false;
                if (res.data && res.data.success) {
                    this.unlimitConfig = res.data.data;
                    this.$toast.open('Unlimit configuration synced.');
                } else if (res.data && res.data.error) {
                    this.$toast.error(res.data.error);
                }
            }).catch(() => {
                this.syncingUnlimit = false;
                this.$toast.error('Failed to sync Unlimit configuration');
            });
        },
        submit(key) {

            if(this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            let afterRequest = {
                onStart: () => this.sending = true,
                onFinish: () => {
                    this.sending = false,
                    this.validationErrors = this.errors;
                },
                onSuccess: () => {
                    this.$toast.open('Settings were updated');
                    this.validationErrors = this.errors;
                },
                onError: () => {
                    this.$toast.error(legacyText("There are some form errors"));
                }
            };

            if(key == 'general') {
                this.form = { general: this.general };
            }

            if(key == 'trade') {
                this.form = { trade: this.trade };
            }

            if(key == 'mail') {
                this.form = { mail: this.mail };
            }

            if(key == 'coinpayments') {
                this.form = { coinpayments: this.coinpayments };
            }

            if(key == 'ethereum') {
                this.form = { ethereum: this.ethereum };
            }

            if(key == 'bnb') {
                this.form = { bnb: this.bnb };
            }

            if(key == 'customtoken') {
                this.form = { customtoken: this.customtoken };
            }

            if(key == 'polygon') {
                this.form = { polygon: this.polygon };
            }

            if(key == 'xlayer') {
                this.form = { xlayer: this.xlayer };
            }

            if(key == 'solana') {
                this.form = { solana: this.solana };
            }

            if(key == 'ripple') {
                this.form = { ripple: this.ripple };
            }

            if(key == 'ton') {
                this.form = { ton: this.ton };
            }

            if(key == 'tron') {
                this.form = { tron: this.tron };
            }

            if(key == 'bitcoin') {
                this.form = { bitcoin: this.bitcoin };
            }

            if(key == 'recaptcha') {
                this.form = { recaptcha: this.recaptcha };
            }

            if(key == 'stripe') {
                this.form = { stripe: this.stripe };
            }

            if(key == 'unlimit') {
                this.form = { unlimit: this.unlimit };
            }

            if(key == 'notification') {
                this.form = { notification: this.notification };
            }

            if(key == 'social') {
                this.form = { social: this.social };
            }

            this.$inertia.put(this.route('admin.settings.update'), this.form, afterRequest);
        },
        removePic: function(){
            this.general.logo = null;
            this.siteLogo = null;
            this.uploaded = false;
        },
        upload: function(){
            let self = this;
            this.$refs.fileUploadForm.upload(this.uploadUrl, this.uploadHeaders, [this.siteLogo]).then(function(){
                self.uploaded = true;
            });
        },
        onSelect: function(fileRecords){
            this.upload();
            this.uploaded = false;
        },
        onUpload: function(responses){
            let response = responses[0];
            if (!response.error) {
                this.general.logo = response.data.path;
            }
        },
        setSection(section) {
            this.section = section;
            this.validationErrors = [];

            if(section == "coinpayments") {
                this.loadCoins();
            }
            if(section == "unlimit") {
                this.loadUnlimitConfig();
            }
        },
        loadCoins(force = false) {

            if(this.coinpaymentsCurrencies.length && !force) return;

            axios.get(this.route('admin.currencies.coinpayments.coins')).then((res) => {
                this.coinpaymentsCurrencies = res.data;
            })
        },
        syncCoins() {

            if(this.syncingCoins) {
                this.$toast.open('Sync is still in progress');
                return;
            } else {
                this.$toast.open('Sync process started');
            }
            this.syncingCoins = true;
            axios.post(this.route('admin.currencies.coinpayments.sync')).then((res) => {
                this.syncingCoins = false;
                this.$toast.open('Coins are synced.');
                this.loadCoins(true);
            })
        }
    },
});
</script>
