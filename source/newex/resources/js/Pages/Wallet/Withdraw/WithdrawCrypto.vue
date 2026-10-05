<script>
import {requestIntent, completeIntent} from '@/Functions/RequestIntent.mjs';
import WalletRefresh from '@/Mixins/WalletRefresh';
import {balanceDecimal, addBalance} from '@/Functions/WalletBalance.mjs';
import {saveWithdrawalDraft, takeWithdrawalDraft} from '@/Functions/WalletHandoff.mjs';
import WalletFlow from '@/Components/WalletFlow.vue';
import CameraQrScanner from '@/Components/CameraQrScanner.vue';
import {networkDisplayName} from '@/Functions/NetworkIdentity.mjs';
import {walletUiCopy} from '@/Functions/WalletUiCopy.mjs';
import CurrencyAvatar from '@/Components/CurrencyAvatar.vue';
import {withdrawalAmountError, withdrawalRate} from '@/Functions/WithdrawalFlow.mjs';
import AssetPicker from "@/Components/AssetPicker.vue";
import {displayDecimal} from '@/Functions/UserDisplay.mjs';
import { legacyText } from '@/Functions/LegacyTranslation';

import NetworkStatus from '@/Components/NetworkStatus.vue';
import Template from '{Template}/Web/Pages/Wallet/Withdraw/WithdrawCrypto.template'
import AppLayout from '@/Layouts/AppLayout'
import TextUserInput from "@/Jetstream/TextUserInput";
import TextUserInputBadge from "@/Jetstream/TextUserInputBadge";
import SelectInput from "@/Jetstream/SelectInput";
import {mapGetters} from "vuex";
import {string_cut} from "@/Functions/String";
import JetDialogModal from '@/Jetstream/DialogModal'
import JetSecondaryButton from '@/Jetstream/SecondaryButton'
import LoadingButton from "@/Jetstream/LoadingButton";
import SvgIcon from "@/Components/Svg/SvgIcon";
import {math_formatter, math_percentage} from "@/Functions/Math";
import { router } from '@inertiajs/vue2'
import {readQrImage} from '@/Functions/QrImage';
import {takeScannedRecipient, storeScannedRecipient} from '@/Functions/ScannedRecipient.mjs';
import { recipientAddress } from '@/Functions/RecipientAddress';

const defaultForm = {
    withdraw_type: 'external',
    network: null,
    address: null,
    internal_uid: null,
    amount: 0,
    payment_id: null
};

export default Template({
    mixins: [WalletRefresh],
    components: {
        WalletFlow, CurrencyAvatar, CameraQrScanner,
        AssetPicker,
        NetworkStatus,
        AppLayout,
        TextUserInput,
        TextUserInputBadge,
        SelectInput,
        JetDialogModal,
        JetSecondaryButton,
        LoadingButton,
        SvgIcon
    },

    props: {
        symbol: String,
        currency: Object,
        errors: Object,
        limit: Object,
        currencies: Array,
        disabledNetworks: Array,

        userVip: {
            type: [Number, String],
            default: 0,
        },

        internalWithdrawMinVip: {
            type: [Number, String],
            default: 0,
        },

        canInternalWithdraw: {
            type: Boolean,
            default: false,
        },
    },

    mounted() {
        try {
            if(new URLSearchParams(window.location.search).get('from_transfer')==='1') {
                const draft=takeWithdrawalDraft(this.currentUser.id,this.currency.symbol);
                if(draft){Object.assign(this.form,draft);this.activeNetwork=draft.network;}
            }
        } catch (_) { /* Browser storage may be unavailable; fresh input remains usable. */ }
        this.restoreScannedRecipient();
        if (!this.scannedRecipientDraft && this.canShowInternalWithdraw && new URLSearchParams(window.location.search).get('type') === 'internal') this.form.withdraw_type = 'internal';
        if (!this.canShowInternalWithdraw) {
            this.form.withdraw_type = 'external';
        }

        if (this.currency) {
            this.activeAsset = {...this.currency};
            this.loadNetworks();
        }




    },

    data() {
        return {
            scannedRecipientDraft: null,
            stopScannedRecipientNavigation: null,
            networkStates: [], networksLoading: false, networkError: '', networkRequest: null,
            addressBook: [], bookOpen: false, bookLabel: '', bookBusy: false, bookEpoch: 0,
            addressError: '', scanning: false, recipientImportEpoch: 0, cameraOpen: false, cameraRequest: null,
            state: 0, flowStep: 0, amountError: '', submitError: '',
            activeNetwork: null,
            recipientNetwork: null,
            networks: [],
            activeAsset: {},
            form: Object.assign({}, defaultForm),
            showWithdrawalModal: false,
            closeWithdrawalModal: false,
            withdrawalModal: null,
            sending: false,
            fetchInterval: null,
            depositClosed: false,
        }
    },

    beforeDestroy() {
        this.stopScannedRecipientNavigation?.();
        this.stopScannedRecipientNavigation = null;
        this.invalidateRecipientImport();
        clearInterval(this.fetchInterval);
        this.bookEpoch++;
        if(this.networkRequest) this.networkRequest.cancel();
    },

    computed: {
        balanceReady() { return this.walletBalanceReady && !!this.wallet; },
        tradeAvailableBalance() { return balanceDecimal(this.wallet?.balance_in_trade,8) || '0'; },
        balanceStateText() { return this.$t(this.walletBalanceStatus==='loading'?'Loading balance…':this.walletBalanceStatus==='error'?'Balance could not be refreshed. Please retry.':'Refresh balance before continuing.'); },
        selectedNetworkName() { return this.getSelectedNetwork()?.text || ''; },
        feeLabel() { return this.isInternalWithdraw ? '0 '+this.currency.symbol : this.withdrawFee.displayFee+(this.withdrawFee.type==='floating'?'%':' '+this.currency.symbol); },
        displayReceived() { return Number(this.calculatedFee)>0 ? this.calculatedFee : '0'; },
        cryptoAssets() { return this.currencies.filter(c=>!['stock','etf'].includes(c.asset_category)); },
        stockAssets() { return this.currencies.filter(c=>['stock','etf'].includes(c.asset_category)); },
        ...mapGetters({
            wallets: 'getWallets',
            withdrawals: 'getWithdrawals',
        }),

        wallet: function () {
            if (this.$store.getters.getUser) {
                return this.$store.getters.getWallet(this.currency.symbol);
            }
        },

        currentUser() {
            return this.$page.props.user || this.$store.getters.getUser || {};
        },

        isVirtualAccount() {
            const user = this.currentUser || {};

            return user.is_xn === true
                || user.is_xn === 1
                || user.is_xn === '1'
                || user.is_xm === true
                || user.is_xm === 1
                || user.is_xm === '1';
        },

        realAvailableBalance() {
            if (!this.wallet) {
                return 0;
            }

            return this.toNumber(this.wallet.balance_in_wallet);
        },

        virtualWalletBalance() {
            if (!this.wallet) {
                return 0;
            }

            const nested = this.getNestedVirtualBalances();
            const value = this.wallet.balance_in_virtual_wallet !== undefined
                ? this.wallet.balance_in_virtual_wallet
                : (nested.balance_in_virtual_wallet !== undefined ? nested.balance_in_virtual_wallet : nested.wallet);

            return this.toNumber(value);
        },

        virtualAvailableBalance() {
            if (!this.wallet) {
                return 0;
            }

            return this.virtualWalletBalance;
        },

        virtualAvailableBalanceDisplay() {
            return math_formatter(this.virtualAvailableBalance, 8);
        },

        availableBalance() {
            const real=balanceDecimal(this.wallet?.balance_in_wallet) || '0';
            return this.isVirtualAccount && !this.isInternalWithdraw
                ? addBalance(real, this.wallet?.balance_in_virtual_wallet || '0', 8)
                : balanceDecimal(real, 8);
        },

        userVipLevel() {
            const vip = parseInt(this.userVip || 0);
            return Number.isFinite(vip) ? vip : 0;
        },

        requiredInternalWithdrawVip() {
            const vip = parseInt(this.internalWithdrawMinVip || 0);
            return Number.isFinite(vip) ? vip : 0;
        },

        canShowInternalWithdraw() {
            return !!this.canInternalWithdraw;
        },

        isInternalWithdraw() {
            return this.canShowInternalWithdraw && this.form.withdraw_type === 'internal';
        },

        withdrawTypeOptions() {
            const options = [
                {
                    value: 'external',
                    text: this.$t('On-chain withdrawal')
                }
            ];

            if (this.canShowInternalWithdraw) {
                options.push({
                    value: 'internal',
                    text: this.$t('Internal withdrawal')
                });
            }

            return options;
        },

        withdrawFee() {
            const rate=withdrawalRate(this.currency,this.getActiveNetworkId(),this.isInternalWithdraw);
            const floating=Number(rate.percent)>0, fee=floating?rate.percent:rate.fixed;
            return {type:floating?'floating':'fixed',fee,displayFee:displayDecimal(fee)};
        },

        calculatedFee: function () {
            if (!Number.isFinite(Number(this.form.amount))) return 0;
            if (!this.form.amount || /^\d+(\.\d+)?$/.test(String(this.form.amount)) === false) {
                return 0;
            }

            if (this.isInternalWithdraw) {
                return math_formatter(this.form.amount, 8);
            }

            let withdrawFee = this.withdrawFee;

            if (withdrawFee.type === "fixed") {
                return math_formatter(this.form.amount - withdrawFee.fee, 8);
            }

            return math_formatter(this.form.amount - math_percentage(this.form.amount, withdrawFee.fee), 8);
        },

        canShowExternalForm() {
            return this.activeNetwork &&
                this.currency.withdraw_status &&
                (!this.disabledNetworks || !this.disabledNetworks.includes(parseInt(this.activeNetwork)));
        },

        canShowWithdrawForm() {
            if (!this.currency.withdraw_status) {
                return false;
            }

            if (this.isInternalWithdraw) {
                return true;
            }

            return this.canShowExternalForm;
        }
    },

    methods: {
        goToFundingTransfer() {
            try {saveWithdrawalDraft(this.currentUser.id,this.currency.symbol,{...this.form,network:this.activeNetwork});}catch(_){}
            this.$inertia.visit(this.route('wallets.transfer',{symbol:this.currency.symbol,from:'trade',return:'withdraw'}));
        },
        walletCopy(key) { return walletUiCopy(this,key); },
        restoreScannedRecipient() {
            const url=new URL(window.location.href),token=url.searchParams.get('recipient_scan');
            if(!token)return;
            try {const draft=takeScannedRecipient(token);if(draft){this.scannedRecipientDraft=draft;this.form.address=draft.address;this.form.withdraw_type='external';this.form.amount=0;this.form.payment_id=null;this.flowStep=0;}else{this.addressError=this.$t('Unable to open the scanned address. Allow browser storage or enter the address manually.');}}
            catch(_){this.addressError=this.$t('Unable to open the scanned address. Allow browser storage or enter the address manually.');}
            this.cleanScannedRecipientUrl(url,token);
            this.stopScannedRecipientNavigation?.();
            this.stopScannedRecipientNavigation=router.on('finish',event=>{
                if(!event.detail.visit.completed)return;
                this.cleanScannedRecipientUrl(url,token);
                this.stopScannedRecipientNavigation?.();
                this.stopScannedRecipientNavigation=null;
            });
        },
        cleanScannedRecipientUrl(importedUrl,token) {
            const current=new URL(window.location.href);
            if(current.origin!==importedUrl.origin || current.pathname!==importedUrl.pathname || current.searchParams.get('recipient_scan')!==token)return;
            current.searchParams.delete('recipient_scan');
            const clean=current.pathname+current.search+current.hash;
            // Inertia may save its page URL again after mounted while restoring scroll.
            // Keep only this consumed handoff in sync, preserving all unrelated state.
            if(this.$page?.url){
                const pageUrl=new URL(this.$page.url,current.origin);
                if(pageUrl.pathname===importedUrl.pathname && pageUrl.searchParams.get('recipient_scan')===token)this.$page.url=clean;
            }
            const history=window.history.state;
            window.history.replaceState(history && typeof history==='object' ? {...history,url:clean} : history,'',clean);
        },
        checkScannedNetwork(network) {
            if(this.scannedRecipientDraft && this.form.address===this.scannedRecipientDraft.address && !this.scannedRecipientDraft.networks.includes(Number(network))) throw new Error('QR code does not match the selected network.');
        },
        goBack() { if (this.sending) return; this.invalidateRecipientImport();this.flowStep=0;this.submitError='';window.scrollTo(0,0); },
        validateRecipient() {
            this.addressError='';
            if (!this.canShowWithdrawForm) {this.addressError=this.$t('Please select network');return false;}
            if (this.isInternalWithdraw) {
                if (!/^0*[1-9][0-9]{0,17}$/.test(String(this.form.internal_uid || '').trim()) || String(this.form.internal_uid).length > 20) this.addressError=this.walletCopy('Enter the recipient UID');
            } else {
                try {this.checkScannedNetwork(this.getActiveNetworkId());this.form.address=recipientAddress(this.form.address,this.getActiveNetworkId());}
                catch(e) {this.addressError=this.recipientError(e);}
                if (!this.addressError && this.currency.has_payment_id && !String(this.form.payment_id ?? '').trim()) this.addressError=this.$t('Memo is required for this asset.');
            }
            if (this.addressError) {this.flowStep=0;return false;}
            return true;
        },
        validateAmount() {
            if (!this.balanceReady) {this.amountError=this.$t('Refresh balance before continuing.');return false;}
            const error=withdrawalAmountError({amount:this.form.amount,balance:this.availableBalance,minimum:this.currency.min_withdraw,maximum:this.currency.max_withdraw,received:this.calculatedFee,dailyAvailable:this.limit.status ? this.limit.available : null});
            this.amountError=error ? this.$t(error) : '';
            if (error) this.flowStep=0;
            return !error;
        },
        advanceWithdrawal() {
            if (this.sending || !this.validateRecipient()) return;
            if (!this.wallet || !this.validateAmount()) return;
            if (this.flowStep===0) {this.invalidateRecipientImport();this.flowStep=2;this.bookOpen=false;window.scrollTo(0,0);return;}
            this.withdraw();
        },
        changeWithdrawType() {
            this.invalidateRecipientImport();
            this.flowStep=0;this.amountError='';this.submitError='';this.form.twofa='';
            this.bookEpoch++;this.addressBook=[];this.bookOpen=false;this.addressError="";
            if (!this.canShowInternalWithdraw && this.form.withdraw_type === 'internal') {
                this.form.withdraw_type = 'external';
            }

            this.scannedRecipientDraft = null;
            this.form.address = null;
            this.form.internal_uid = null;
            this.form.payment_id = null;
            this.recipientNetwork = null;

            if (this.isInternalWithdraw) {
                this.activeNetwork = null;
                this.form.network = null;
            }
        },

        changeAsset() {
            this.invalidateRecipientImport();
            let target=this.route('wallets.withdraw.crypto', this.activeAsset.symbol);
            if(this.scannedRecipientDraft && this.form.address===this.scannedRecipientDraft.address){
                try{target+='?recipient_scan='+storeScannedRecipient(this.scannedRecipientDraft)}catch(_){this.addressError=this.$t('Unable to open the scanned address. Allow browser storage or enter the address manually.');return;}
            }
            return router.visit(target);
        },
        loadNetworks() {
            this.invalidateRecipientImport();
            if(this.networkRequest) this.networkRequest.cancel();
            const request=axios.CancelToken.source();this.networkRequest=request;
            this.networksLoading=true; this.networkError='';
            const restoreNetwork=this.activeNetwork;
            this.networks=[];this.networkStates=[];this.activeNetwork=null;this.form.network=null;
            axios.get(this.route('wallets.api.deposit.networks'), {
                params:{symbol:this.activeAsset.symbol,purpose:'withdraw',include_unavailable:1},timeout:20000,cancelToken:request.token
            }).then(response=>{
                if(request!==this.networkRequest)return;
                if(!Array.isArray(response.data.networks)) throw new Error('Invalid network response');
                this.networkStates=response.data.networks.map(n=>({...n,name:networkDisplayName(n.id,n.name)}));
                this.networks=this.normalizeNetworks(Object.fromEntries(this.networkStates.filter(n=>n.available).map(n=>[n.id,n.name])));
                if(!this.isInternalWithdraw && !this.scannedRecipientDraft && restoreNetwork && this.networks.some(n=>Number(n.value)===Number(restoreNetwork))){this.activeNetwork=Number(restoreNetwork);this.changeNetwork();}
                else if(!this.isInternalWithdraw && !this.scannedRecipientDraft && this.networks.length===1){this.activeNetwork=this.networks[0].value;this.changeNetwork();}
            }).catch(error=>{
                if(request===this.networkRequest && !axios.isCancel(error)) this.networkError=this.$t('Unable to load networks. Please try again.');
            }).finally(()=>{if(request===this.networkRequest)this.networksLoading=false;});
        },
        recipientError(error, fallback = 'Unable to import address. Please paste it manually.') {
            const messages = ['Please select network', 'Invalid wallet address', 'QR code does not match the selected network.', 'Choose a QR image smaller than 10 MB.', 'Unable to read this image.', 'No QR code found. Try a clearer image.'];
            return this.walletCopy(messages.includes(error?.message) ? error.message : fallback);
        },
        async loadAddressBook() {
            const epoch=++this.bookEpoch;
            this.addressBook=[];
            if(!this.activeNetwork)return;
            try { const r=await axios.get(this.route('wallets.address-book.index'),{params:{symbol:this.currency.symbol,network:this.getActiveNetworkId()},timeout:15000});
                if(epoch===this.bookEpoch)this.addressBook=r.data;
            }catch(e){if(epoch===this.bookEpoch)this.addressError=this.$t('Unable to load address book. Please retry.');}
        },
        selectRecipient(item) {
            this.invalidateRecipientImport();
            this.form.address=item.address;this.form.payment_id=item.payment_id;
            this.addressError='';this.bookOpen=false;
        },
        async saveRecipient() {
            if(this.bookBusy)return;
            this.bookBusy=true;this.addressError='';
            try {const address=recipientAddress(this.form.address,this.getActiveNetworkId());
                await axios.post(this.route('wallets.address-book.store'),{symbol:this.currency.symbol,network:this.getActiveNetworkId(),label:this.bookLabel,address,payment_id:this.form.payment_id},{timeout:20000});
                this.bookLabel='';await this.loadAddressBook();this.$toast.success(this.$t('Address saved'));
            }catch(e){this.addressError=Object.values(e.response?.data?.errors||{}).flat().join('；')||this.recipientError(e, 'Unable to save address. Please retry.');}
            finally{this.bookBusy=false;}
        },
        async removeRecipient(item) {
            try {await axios.delete(this.route('wallets.address-book.destroy',item.id),{timeout:15000});await this.loadAddressBook();}
            catch(e){this.addressError=this.$t('Unable to remove address. Please retry.');}
        },
        invalidateRecipientImport() {
            this.recipientImportEpoch++;
            this.scanning=false;
            this.cameraOpen=false;
            this.cameraRequest=null;
        },
        editRecipient() {
            this.invalidateRecipientImport();
            this.scannedRecipientDraft=null;
            this.addressError='';
        },
        beginRecipientImport() {
            if (this.flowStep!==0 || this.isInternalWithdraw || !this.canShowExternalForm) return null;
            this.invalidateRecipientImport();
            this.addressError='';
            return {epoch:this.recipientImportEpoch,network:this.getActiveNetworkId(),address:this.form.address,memo:this.form.payment_id};
        },
        recipientImportIsCurrent(request) {
            return request.epoch===this.recipientImportEpoch && this.flowStep===0 && !this.isInternalWithdraw
                && request.network===this.getActiveNetworkId()
                && request.address===this.form.address && request.memo===this.form.payment_id;
        },
        async pasteRecipient() {
            const request=this.beginRecipientImport();if(!request)return;
            try {const value=await navigator.clipboard.readText();if(!this.recipientImportIsCurrent(request))return;this.form.address=recipientAddress(value,request.network);this.form.payment_id=null;}
            catch(e){if(this.recipientImportIsCurrent(request))this.addressError=this.recipientError(e, 'Clipboard access is unavailable. Paste the address manually.');}
        },
        openCamera() {
            if (!this.canShowExternalForm) {
                this.addressError=this.walletCopy('Select a network before scanning the recipient address.');
                this.$nextTick(()=>document.getElementById('withdraw-network')?.focus());
                return;
            }
            const request=this.beginRecipientImport();if(!request)return;
            this.cameraRequest=request;this.cameraOpen=true;
        },
        cameraRecipient(value) {
            const request=this.cameraRequest;
            this.cameraOpen=false;
            if(!request || !this.recipientImportIsCurrent(request))return;
            try {
                this.form.address=recipientAddress(value,request.network);this.form.payment_id=null;
                this.$toast.success(this.$t('Address imported. Check the network and recipient before submitting.'));
            } catch(error) {this.addressError=this.recipientError(error);}
            finally {this.invalidateRecipientImport();}
        },
        importCameraImage() {
            this.invalidateRecipientImport();
            this.$nextTick(()=>this.$refs.recipientImage?.click());
        },
        async scanRecipient(event) {
            const file=event.target.files[0];event.target.value='';if(!file)return;
            const request=this.beginRecipientImport();if(!request)return;
            this.scanning=true;
            try {
                const value=await readQrImage(file);
                if(!this.recipientImportIsCurrent(request))return;
                this.form.address=recipientAddress(value,request.network);this.form.payment_id=null;
                this.$toast.success(this.$t('Address imported. Check the network and recipient before submitting.'));
            }catch(e){if(this.recipientImportIsCurrent(request))this.addressError=this.recipientError(e);}
            finally{if(request.epoch===this.recipientImportEpoch)this.scanning=false;}
        },
        normalizeNetworks(networks) {
            if (!networks) {
                return [];
            }

            if (Array.isArray(networks)) {
                return networks.map((item, index) => {
                    return this.normalizeNetworkItem(item, index);
                }).filter(item => item.value !== null && item.value !== undefined && !Number.isNaN(Number(item.value)));
            }

            if (typeof networks === 'object') {
                return Object.keys(networks).map((key) => {
                    const item = networks[key];

                    if (item !== null && typeof item === 'object') {
                        return this.normalizeNetworkItem({
                            ...item,
                            key: key
                        });
                    }

                    return {
                        value: Number(key),
                        text: String(item)
                    };
                }).filter(item => item.value !== null && item.value !== undefined && !Number.isNaN(Number(item.value)));
            }

            return [];
        },

        normalizeNetworkItem(item, index = null) {
            if (item !== null && typeof item === 'object') {
                const rawValue = item.value !== undefined
                    ? item.value
                    : item.id !== undefined
                        ? item.id
                        : item.network_id !== undefined
                            ? item.network_id
                            : item.key !== undefined
                                ? item.key
                                : index;

                const text = item.text ||
                    item.name ||
                    item.title ||
                    item.label ||
                    item.network ||
                    item.network_name ||
                    '';

                return {
                    ...item,
                    value: Number(rawValue),
                    text: text
                };
            }

            return {
                value: index !== null ? Number(index) : null,
                text: String(item)
            };
        },

        changeNetwork(value) {
            this.invalidateRecipientImport();
            this.flowStep=0;this.submitError='';this.form.twofa='';
            this.addressError='';this.bookOpen=false;

            let networkId = null;

            if (value !== null && typeof value === 'object' && !value.target) {
                networkId = Number(
                    value.value !== undefined
                        ? value.value
                        : value.id !== undefined
                            ? value.id
                            : value.network_id !== undefined
                                ? value.network_id
                                : value.key
                );
            } else {
                networkId = this.getActiveNetworkId();
            }

            if (networkId && networkId > 0) {
                const switched=this.recipientNetwork!==null && this.recipientNetwork!==networkId;
                if (switched) {this.form.address=null;this.form.payment_id=null;this.scannedRecipientDraft=null;}
                this.activeNetwork = networkId;
                this.form.network = networkId;
                this.recipientNetwork = networkId;
                // The first network selection validates the user's draft without discarding it.
                if (this.form.address && this.canShowExternalForm) {
                    try {this.checkScannedNetwork(networkId);this.form.address=recipientAddress(this.form.address,networkId);}
                    catch(error) {this.addressError=this.recipientError(error);}
                }
            }
        },

        getActiveNetworkId() {
            if (this.activeNetwork !== null && typeof this.activeNetwork === 'object') {
                return Number(
                    this.activeNetwork.value !== undefined
                        ? this.activeNetwork.value
                        : this.activeNetwork.id !== undefined
                            ? this.activeNetwork.id
                            : this.activeNetwork.network_id !== undefined
                                ? this.activeNetwork.network_id
                                : this.activeNetwork.key
                );
            }

            return Number(this.activeNetwork);
        },

        getSelectedNetwork() {
            const networkId = this.getActiveNetworkId();

            return this.networks.find((item) => Number(item.value) === Number(networkId));
        },

        fetchWithdrawals() {
            this.$store.dispatch('fetchWithdrawals', this.route('wallets.api.withdrawals', {
                type: 'coin'
            }));
        },

        format_string(string, limit) {
            return string_cut(string, limit);
        },

        toNumber(value) {
            if (value === null || value === undefined || value === '') {
                return 0;
            }

            const number = parseFloat(String(value).replace(/,/g, ''));

            return Number.isFinite(number) ? number : 0;
        },

        getNestedVirtualBalances() {
            if (!this.wallet) {
                return {};
            }

            if (this.wallet.virtual_balances) {
                return this.wallet.virtual_balances;
            }

            if (this.wallet.balances && this.wallet.balances.virtual) {
                return this.wallet.balances.virtual;
            }

            return {};
        },

        doCopy(string) {
            this.$copyText(string).then(() => {
                this.$toast.open(this.$t('Text was copied to the clipboard'));
            }, function (e) {

            })
        },

        withdraw() {
            if (this.flowStep!==2 || !this.validateRecipient() || !this.validateAmount() || this.$page.props.mode==='readonly') return;
            this.submitError='';
            if (this.sending) {
                return;
            }

            if (!this.form.amount || parseFloat(this.form.amount) <= 0) {
                return this.$toast.error(this.$t('Please enter withdrawal amount'));
            }

            let payload = {
                ...this.form,
                symbol: this.currency.symbol
            };

            if (this.form.withdraw_type === 'internal') {
                if (!this.canShowInternalWithdraw) {
                    return this.$toast.error(this.$t('Your VIP level is not eligible for internal withdrawal'));
                }

                if (!this.form.internal_uid) {
                    return this.$toast.error(this.walletCopy('Enter the recipient UID'));
                }

                payload.internal_transfer = true;
                payload.recipient_type = 'uid';
                payload.network = null;
                payload.address = null;
                payload.payment_id = null;
            } else {
                const networkId = this.getActiveNetworkId();

                if (!networkId || networkId <= 0) {
                    return this.$toast.error(this.$t ? this.$t('Please select network') : legacyText("Please select network"));
                }

                if (!this.form.address) {
                    return this.$toast.error(this.$t('Please enter withdrawal address'));
                }

                try { payload.address=recipientAddress(this.form.address,networkId); }
                catch(e){this.addressError=this.recipientError(e);return;}
                if(this.currency.has_payment_id && (this.form.payment_id===null||this.form.payment_id==='')) {
                    this.addressError=this.$t('Memo is required for this asset.');return;
                }
                this.addressError='';
                payload.internal_transfer = false;
                payload.network = networkId;
            }

            this.sending = true;

            const intentScope='withdraw:'+this.$page.props.user.id;
            const intent=requestIntent(intentScope,payload,payload);
            axios.post(this.route('wallets.api.withdraw'), intent.payload, {timeout:20000,headers:{'Idempotency-Key':intent.key}}).then((response) => {
                completeIntent(intentScope,intent.key);
                this.$inertia.visit(this.route('wallets.withdraw.crypto.success'));
            }).catch(error => {
                this.sending = false;

                if (error.response && error.response.data && error.response.data.errors) {
                    const fields=error.response.data.errors;
                    if (fields.address || fields.network || fields.payment_id || fields.internal_uid) {this.flowStep=0;this.addressError=Object.values(fields).flat().join(' ');}
                    else if (fields.amount) {this.flowStep=0;this.amountError=fields.amount.join(' ');}
                    else this.submitError=Object.values(fields).flat().join(' ');
                    _.each(error.response.data.errors, (field, key) => {
                        this.$toast.error(field[0]);
                    });
                    return;
                }

                if (error.response && error.response.data && error.response.data.message) {
                    this.submitError=error.response.data.message;
                    this.$toast.error(error.response.data.message);
                    return;
                }

                this.submitError=this.$i18n.locale.startsWith('zh')?'提现结果尚未确认，请先检查提现记录；重试相同内容将沿用本次请求。':'Withdrawal outcome is not confirmed. Check withdrawal history; retrying the same details will reuse this request.';
                this.$toast.error(this.submitError);
            });
        },

        openModal(withdrawal) {
            this.withdrawalModal = withdrawal;
            this.showWithdrawalModal = true;
        },

        closeModal() {
            this.showWithdrawalModal = false;
        },

        handleInput($event) {
            let keyCode = ($event.keyCode ? $event.keyCode : $event.which);

            if ((keyCode < 48 || keyCode > 57) && (keyCode !== 46 || this.form.amount.toString().indexOf('.') !== -1)) {
                $event.preventDefault();
            }

            let precision = 8;

            if (
                this.form.amount !== null &&
                this.form.amount.toString().indexOf(".") > -1 &&
                this.form.amount.toString().split('.')[1].length >= precision
            ) {
                $event.preventDefault();
            }
        },

        clearInput($event) {
            let field = this.form.amount.toString();

            if (field.charAt(0) === '.') {
                this.form.amount = 0;
                return;
            }

            if (/^0+\.\d+/.test(field)) {
                this.form.amount = field.replace(/^0+/, '0');
            }

            if (/^0+\d+/.test(field)) {
                this.form.amount = field.replace(/^0+/, '');
            }
        },

        setMaxAmount() {
            if (!this.balanceReady) return;
            this.form.amount = this.availableBalance;
        }
    },
})
</script>
