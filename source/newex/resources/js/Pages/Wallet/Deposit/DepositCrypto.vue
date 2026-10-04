<script>
import WalletFlow from '@/Components/WalletFlow.vue';
import {networkDisplayName} from '@/Functions/NetworkIdentity.mjs';
import {walletUiCopy} from '@/Functions/WalletUiCopy.mjs';
import AssetPicker from "@/Components/AssetPicker.vue";
import {displayDecimal} from '@/Functions/UserDisplay.mjs';
import SelectInput from "@/Jetstream/SelectInput";
import NetworkStatus from '@/Components/NetworkStatus.vue';
import Template from '{Template}/Web/Pages/Wallet/Deposit/DepositCrypto.template'
import AppLayout from '@/Layouts/AppLayout'
import {mapGetters} from "vuex";
import {string_cut} from "@/Functions/String";
import JetDialogModal from '@/Jetstream/DialogModal'
import JetSecondaryButton from '@/Jetstream/SecondaryButton'
import SvgIcon from "@/Components/Svg/SvgIcon";
import QrCode from 'vue-qrcode-component'
import {router} from "@inertiajs/vue2";

export default Template({
    components: {
        WalletFlow,
        AssetPicker,
        NetworkStatus,
        AppLayout,
        SelectInput,
        JetDialogModal,
        JetSecondaryButton,
        SvgIcon,
        QrCode,
    },
    props: {
        symbol: String,
        currency: Object,
        errors: Object,
        currencies: Array,
    },
    data() {
        return {
            state: 0,
            receiveStep: false,
            networks: {},
            activeAsset: {},
            fetchInterval: null,
            wallet : false,
            walletData: false,
            showDepositModal: false,
            closeDepositModal: false,
            depositModal: null,
            showQr: false,
            showQrAlt: false,
            tooltipOptions: {
                placement: 'auto-start'
            },
            activeNetwork: "",
            addressState: 'idle',
            addressError: '',
            networkStates: [], networksLoading: false,
            networksError: '',
            addressRequest: 0,
            networkRequest: 0,
            historyRequest: 0,
            deposits: [],
            historyError: false,
            historyLoading: false,
            allHistory: false,
            addressCancel: null,
            networksCancel: null,
        }
    },
    mounted() {

        if(this.currency) {
            this.activeAsset = {...this.currency};
            this.loadNetworks();
        }

        if(_.isEmpty(this.wallets)) {
            this.$store.dispatch('fetchWallets', this.route('wallets.index'));
        }




    },
    beforeDestroy() {
        clearInterval(this.fetchInterval);
        this.historyRequest++;
        this.addressRequest++;
        this.networkRequest++;
        if (this.addressCancel) this.addressCancel.cancel();
        if (this.networksCancel) this.networksCancel.cancel();
    },
    computed: {
        cryptoAssets() { return this.currencies.filter(c=>!['stock','etf'].includes(c.asset_category)); },
        stockAssets() { return this.currencies.filter(c=>['stock','etf'].includes(c.asset_category)); },
        networkName() { return this.networks[this.activeNetwork] || ''; },
        nativeEvmDeposit() { const native={2:'ETH',5:'BNB',15:'POL',24:'OKB'}; return native[Number(this.activeNetwork)] === String(this.currency?.symbol || '').toUpperCase(); },
        depositRules() { return this.walletData && this.walletData.deposit_rules; },
        depositMinimum() { return this.depositRules ? displayDecimal(this.depositRules.minimum) : '—'; },
        depositFee() {
            if (!this.depositRules) return '—';
            const {fee_percent: percent, fee_fixed: fixed} = this.depositRules;
            return Number(percent) > 0 ? displayDecimal(percent) + '%' : displayDecimal(fixed) + ' ' + this.currency.symbol;
        },
        ...mapGetters({
            wallets: 'getWallets',
        }),
    },
    methods: {
        walletCopy(key) { return walletUiCopy(this,key); },
        openDepositDetails() {
            if (!this.currency.deposit_status || this.networksLoading || !this.networks[this.activeNetwork]) return;
            this.receiveStep = true;
            window.scrollTo(0, 0);
            if (this.addressState !== 'ready') this.loadAddress();
        },
        async loadNetworks() {
            const request = ++this.networkRequest;
            if (this.networksCancel) this.networksCancel.cancel();
            this.networksCancel = axios.CancelToken.source();
            this.clearAddress();
            this.activeNetwork = '';
            this.networks = {};
            this.networkStates = [];
            this.networksError = '';
            this.networksLoading = true;
            if (!this.currency.deposit_status) { this.networksLoading = false; return; }
            try {
                const response = await axios.get(this.route('wallets.api.deposit.networks'), {
                    params: {symbol: this.activeAsset.symbol, purpose: 'deposit', include_unavailable: 1},
                    timeout: 20000, cancelToken: this.networksCancel.token,
                });
                if (request !== this.networkRequest) return;
                const data = response.data;
                if (!data || !Array.isArray(data.networks)) throw new Error('Invalid network response');
                this.networkStates = data.networks.map(n=>({...n,name:networkDisplayName(n.id,n.name)}));
                this.networks = Object.fromEntries(this.networkStates.filter(n=>n.available).map(n=>[n.id,n.name]));
                const ids = Object.keys(this.networks);
                if (ids.length === 1) {
                    this.activeNetwork = ids[0];
                    this.loadAddress();
                }
            } catch (error) {
                if (request !== this.networkRequest || axios.isCancel(error)) return;
                this.networksError = this.$t('Unable to load networks. Please try again.');
            } finally {
                if (request === this.networkRequest) this.networksLoading = false;
            }
        },
        clearAddress() {
            this.addressRequest++;
            if (this.addressCancel) this.addressCancel.cancel();
            this.walletData = null;
            this.addressState = 'idle';
            this.addressError = '';
        },
        changeAsset() {
            this.historyRequest++;
            this.deposits = [];
            this.clearAddress();
            this.networkRequest++;
            if (this.networksCancel) this.networksCancel.cancel();
            this.activeNetwork = '';
            this.networks = {};
            return router.visit(this.route('wallets.deposit.crypto', this.activeAsset.symbol));
        },
        async fetchDeposits(reset = false) {
            const request = ++this.historyRequest;
            if (reset) this.deposits = [];
            this.historyLoading = true;
            this.historyError = false;
            try {
                const params = this.allHistory ? {} : {currency: this.currency.id, network: this.activeNetwork || undefined};
                const response = await axios.get(this.route('wallets.api.deposits', {type: 'coin'}), {params, timeout: 15000});
                if (request === this.historyRequest) this.deposits = response.data.data || [];
            } catch (error) {
                if (request === this.historyRequest) this.historyError = true;
            } finally {
                if (request === this.historyRequest) this.historyLoading = false;
            }
        },
        doCopy(string) {
            this.$copyText(string).then(() => {
                this.$toast.open(this.$t('Text was copied to the clipboard'));
            }).catch(() => {
                this.$toast.open({message: this.$t('Copy failed. Please select and copy the address.'), type: 'error'});
            });
        },
        format_string(string, limit) {
            return string_cut(string, limit);
        },
        loadAddress() {
            this.clearAddress();
            if (!this.activeNetwork || !this.networks[this.activeNetwork]) return;
            this.getAddress(this.activeNetwork);
        },
        async getAddress(network) {
            const request = this.addressRequest;
            const symbol = this.activeAsset.symbol;
            this.addressState = 'loading';
            this.addressCancel = axios.CancelToken.source();
            try {
                const response = await axios.get(this.route('wallets.api.getAddress'), {
                    params: {network, symbol}, timeout: 30000, cancelToken: this.addressCancel.token,
                });
                if (request !== this.addressRequest || symbol !== this.activeAsset.symbol || String(network) !== String(this.activeNetwork)) return;
                const data = response.data;
                if (!data || data.success === false || typeof data.address !== 'string' || !data.address.trim() || /\s/.test(data.address.trim()) || data.address.length > 256) {
                    throw new Error('Invalid address response');
                }
                if (this.currency.has_payment_id && (data.paymentId === undefined || data.paymentId === null || String(data.paymentId).trim() === '')) {
                    throw new Error('Missing required memo');
                }
                this.walletData = {...data, address: data.address.trim()};
                this.addressState = 'ready';
            } catch (error) {
                if (request !== this.addressRequest || axios.isCancel(error)) return;
                this.walletData = null;
                this.addressState = 'error';
                const status = error.response && error.response.status;
                const key = status === 401 || status === 419 ? 'Your session expired. Please sign in again.'
                    : status === 403 ? 'Deposits are unavailable for this network or account. Choose another network or contact support.'
                    : status === 429 ? 'Too many requests. Please wait before trying again.'
                    : ['ECONNABORTED', 'ETIMEDOUT'].includes(error.code) ? 'Address request timed out. Please try again.'
                    : 'Unable to load your deposit address. Please try again.';
                this.addressError = this.$t(key);
            }
        },
        openModal(deposit) {
            this.depositModal = deposit;
            this.showDepositModal = true;
        },
        closeModal() {
            this.showDepositModal = false;
        }
    }
})
</script>
