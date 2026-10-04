<script>
import {parseTimestamp} from '@/Functions/UserDisplay.mjs';
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Web/Pages/Report/Deposits.template'
import AppLayout from '@/Layouts/AppLayout'
import Welcome from '@/Jetstream/Welcome'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import Pagination from '@/Jetstream/Pagination'
import NavLink from "@/Jetstream/NavLink";
import Badge from "@/Jetstream/Badge";
import SearchFilter from '@/Jetstream/SearchFilter'
import pickBy from 'lodash/pickBy'
import mapValues from 'lodash/mapValues'
import {string_cut} from "@/Functions/String";
import TextUserInput from "@/Jetstream/TextUserInput";
import SelectUserInput from "@/Jetstream/SelectUserInput";
import ReportsTab from "@/Components/Reports/ReportsTab";
import SvgIcon from "@/Components/Svg/SvgIcon";
import {math_formatter} from "@/Functions/Math";

export default Template({
    components: {
        Badge,
        NavLink,
        NavButtonLink,
        AppLayout,
        Welcome,
        Pagination,
        SearchFilter,
        TextUserInput,
        SelectUserInput,
        ReportsTab,
        SvgIcon
    },

    props: {
        deposits: Object,
        filters: Object,
        currencies: Object,
    },

    data() {
        return {
            sending: false,
            form: {
                currency: this.filters.currency ? parseInt(this.filters.currency) : null,
                txn: this.filters.txn || '',
                status: this.filters.status || '',
            },
            selectedDeposit: null,
            showDepositDetailModal: false,
        }
    },

    methods: {

        padDateNumber(value) {
            return String(value).padStart(2, '0');
        },

        getTimeZoneOffset(timeZone, date) {
            if (!timeZone || !(date instanceof Date) || Number.isNaN(date.getTime())) {
                return 0;
            }

            try {
                const formatter = new Intl.DateTimeFormat('en-US', {
                    timeZone: timeZone,
                    hour12: false,
                    hourCycle: 'h23',
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                });

                const parts = formatter.formatToParts(date).reduce((carry, item) => {
                    carry[item.type] = item.value;
                    return carry;
                }, {});

                const year = parseInt(parts.year, 10);
                const month = parseInt(parts.month, 10);
                const day = parseInt(parts.day, 10);
                let hour = parseInt(parts.hour, 10);
                const minute = parseInt(parts.minute, 10);
                const second = parseInt(parts.second, 10);

                if (hour === 24) {
                    hour = 0;
                }

                const localAsUtc = Date.UTC(year, month - 1, day, hour, minute, second);

                return localAsUtc - date.getTime();
            } catch (e) {
                return 0;
            }
        },

        parseServerDateTime(value) {
            if (!value) {
                return null;
            }

            if (value instanceof Date) {
                return Number.isNaN(value.getTime()) ? null : value;
            }

            if (typeof value === 'number') {
                const date = new Date(value > 10000000000 ? value : value * 1000);
                return Number.isNaN(date.getTime()) ? null : date;
            }

            const stringValue = String(value).trim();

            if (!stringValue || stringValue === '-') {
                return null;
            }

            if (/[zZ]$/.test(stringValue) || /[+-]\d{2}:?\d{2}$/.test(stringValue)) {
                const date = parseTimestamp(stringValue);
                return date;
            }

            const match = stringValue.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/);

            if (!match) {
                const date = new Date(stringValue);
                return Number.isNaN(date.getTime()) ? null : date;
            }

            const year = parseInt(match[1], 10);
            const month = parseInt(match[2], 10);
            const day = parseInt(match[3], 10);
            const hour = parseInt(match[4], 10);
            const minute = parseInt(match[5], 10);
            const second = parseInt(match[6] || '0', 10);

            const wallTimeAsUtc = Date.UTC(year, month - 1, day, hour, minute, second);
            const sourceServerTimeZone = 'Europe/Berlin';

            let utcTime = wallTimeAsUtc - this.getTimeZoneOffset(sourceServerTimeZone, new Date(wallTimeAsUtc));
            utcTime = wallTimeAsUtc - this.getTimeZoneOffset(sourceServerTimeZone, new Date(utcTime));

            const date = new Date(utcTime);

            return Number.isNaN(date.getTime()) ? null : date;
        },

        formatLocalDateTime(value) {
            const date = this.parseServerDateTime(value);

            if (!date || Number.isNaN(date.getTime())) {
                return '-';
            }

            return [
                date.getFullYear(),
                this.padDateNumber(date.getMonth() + 1),
                this.padDateNumber(date.getDate()),
            ].join('-') + ' ' + [
                this.padDateNumber(date.getHours()),
                this.padDateNumber(date.getMinutes()),
                this.padDateNumber(date.getSeconds()),
            ].join(':');
        },
        stripTrailingZeros(value) {
            if (value === null || value === undefined || value === '') {
                return '0';
            }

            let stringValue = String(value);

            if (stringValue.indexOf('.') === -1) {
                return stringValue;
            }

            stringValue = stringValue.replace(/(\.\d*?[1-9])0+$/g, '$1');
            stringValue = stringValue.replace(/\.0+$/g, '');
            stringValue = stringValue.replace(/\.$/g, '');

            return stringValue === '' ? '0' : stringValue;
        },

        toNumber(value) {
            if (value === null || value === undefined || value === '') {
                return 0;
            }

            const number = parseFloat(String(value).replace(/,/g, ''));
            return Number.isFinite(number) ? number : 0;
        },

        formatNumber(value, decimals = 8) {
            return this.stripTrailingZeros(math_formatter(this.toNumber(value), decimals));
        },

        formatAmount(value, symbol = '') {
            const amount = this.toNumber(value);
            return this.formatNumber(amount, 8) + (symbol ? ' ' + symbol : '');
        },

        format_string(string, limit) {
            if (!string) {
                return '';
            }

            return string_cut(string, limit);
        },

        doCopy(string) {
            if (!string) {
                return;
            }

            this.$copyText(string).then(() => {
                this.$toast.open(this.$t('Text was copied to the clipboard'));
            }, function (e) {

            })
        },

        reset() {
            this.form = mapValues(this.form, () => null)
        },

        getList() {
            if (this.sending) return;

            this.sending = true;

            let afterRequest = {
                onStart: () => this.sending = true,
                onFinish: () => this.sending = false,
                onSuccess: () => {
                    this.sending = false;
                },
                onError: () => {
                    this.sending = false;
                },
                preserveScroll: true
            };

            let query = pickBy(this.form)
            this.$inertia.replace(this.route('reports.deposits', Object.keys(query).length ? query : { remember: 'forget' }), afterRequest)
        },

        openDepositDetail(deposit) {
            this.selectedDeposit = deposit;
            this.showDepositDetailModal = true;
        },

        closeDepositDetail() {
            this.showDepositDetailModal = false;
            this.selectedDeposit = null;
        },

        isUidDeposit(deposit) {
            if (!deposit) {
                return false;
            }

            const address = String(deposit.address || '');
            return address.indexOf('UID:') === 0;
        },

        isInternalAddress(deposit) {
            if (!deposit) {
                return false;
            }

            const address = String(deposit.address || '').toLowerCase();
            return address === 'internal';
        },

        isInternalDeposit(deposit) {
            if (!deposit) {
                return false;
            }

            return this.isUidDeposit(deposit) || this.isInternalAddress(deposit) || !!deposit.internal_id;
        },

        isPlatformInternalTransfer(deposit) {
            if (!deposit) {
                return false;
            }

            const sourceId = String(deposit.source_id || '').toLowerCase();
            const recordType = String(deposit.record_type || '').toLowerCase();
            const sourceLabel = String(deposit.source_label || '').toLowerCase();
            const networkDisplay = String(deposit.network_display || '').toLowerCase();
            const addressDisplay = String(deposit.address_display || '').toLowerCase();
            const address = String(deposit.address || '').toLowerCase();
            const txn = String(deposit.txn || '').toLowerCase();

            return sourceId === 'internal'
                || recordType === 'internal_deposit'
                || recordType === 'internal_transfer'
                || sourceLabel.indexOf('内部转账') !== -1
                || sourceLabel.indexOf('internal transfer') !== -1
                || networkDisplay.indexOf('内部转账') !== -1
                || networkDisplay.indexOf('internal transfer') !== -1
                || addressDisplay.indexOf('内部转账') !== -1
                || addressDisplay.indexOf('internal transfer') !== -1
                || address.indexOf('内部转账') === 0
                || address.indexOf('internal transfer') === 0
                || txn.indexOf('internal-') === 0;
        },

        getUidCode(deposit) {
            if (!deposit) {
                return '';
            }

            const address = String(deposit.address || '');

            if (address.indexOf('UID:') === 0) {
                return address.replace('UID:', '').trim();
            }

            const fromUidMatch = address.match(/(?:From UID|来自UID:?|来自 UID:?|UID:)\s*([^\s-]+)/i);

            if (fromUidMatch && fromUidMatch[1]) {
                return fromUidMatch[1].trim();
            }

            return '';
        },

        getInternalTransferSourceText(deposit) {
            if (!deposit) {
                return '';
            }

            if (deposit.address_display) {
                return deposit.address_display;
            }

            const uid = this.getUidCode(deposit) || deposit.from_referral_code || '';

            return uid ? (legacyText("内部转账 - 来自UID:") + uid) : legacyText("内部转账");
        },

        getInternalTransferBadgeText(deposit) {
            return this.isPlatformInternalTransfer(deposit) ? legacyText("内部转账入账") : '';
        },

        getAddressLabel(deposit) {
            if (this.isPlatformInternalTransfer(deposit)) {
                return legacyText("来源");
            }

            if (this.isUidDeposit(deposit)) {
                return this.$t('UID');
            }

            return this.$t('Address');
        },

        getDisplayAddress(deposit) {
            if (!deposit) {
                return '';
            }

            if (this.isPlatformInternalTransfer(deposit)) {
                return this.getInternalTransferSourceText(deposit);
            }

            if (this.isUidDeposit(deposit)) {
                const uid = this.getUidCode(deposit);
                return uid ? (this.$t('UID') + ' ' + uid + ' ' + this.$t('Deposit')) : '';
            }

            if (this.isInternalAddress(deposit)) {
                return this.$t('Internal Transfer');
            }

            return deposit.address || '';
        },

        getAddressCopyValue(deposit) {
            if (!deposit) {
                return '';
            }

            if (this.isPlatformInternalTransfer(deposit)) {
                return this.getUidCode(deposit) || deposit.from_referral_code || '';
            }

            if (this.isUidDeposit(deposit)) {
                return this.getUidCode(deposit);
            }

            if (this.isInternalAddress(deposit)) {
                return '';
            }

            return deposit.address || '';
        },

        getStatusLabel(deposit) {
            if (!deposit) {
                return '-';
            }

            const status = String(deposit.status || '').toLowerCase();

            if (status === 'confirmed' || status === 'completed' || status === 'confirmed_provider') {
                return this.$t('Completed');
            }

            if (status === 'pending') {
                return this.$t('Pending');
            }

            if (status === 'failed') {
                return this.$t('Failed');
            }

            if (status === 'ignored') return this.$t('Not credited');

            if (status === 'rejected') {
                return this.$t('Rejected');
            }

            return deposit.status || '-';
        },

        getStatusClass(deposit) {
            if (!deposit) {
                return 'label-orange';
            }

            const status = String(deposit.status || '').toLowerCase();

            if (status === 'confirmed' || status === 'completed' || status === 'confirmed_provider') {
                return 'label-green';
            }

            if (status === 'failed' || status === 'rejected') {
                return 'label-red';
            }

            return 'label-orange';
        },

        isNativeAssetOnTron(deposit) {
            const symbol = String(deposit && deposit.symbol ? deposit.symbol : '').toUpperCase();
            const currency = String(deposit && deposit.currency ? deposit.currency : '').toLowerCase();
            return symbol === 'TRX' || currency === 'tron';
        },

        isNativeAssetOnEthereum(deposit) {
            const symbol = String(deposit && deposit.symbol ? deposit.symbol : '').toUpperCase();
            const currency = String(deposit && deposit.currency ? deposit.currency : '').toLowerCase();
            return symbol === 'ETH' || currency.indexOf('ethereum') !== -1 || currency === 'ether';
        },

        isNativeAssetOnBsc(deposit) {
            const symbol = String(deposit && deposit.symbol ? deposit.symbol : '').toUpperCase();
            const currency = String(deposit && deposit.currency ? deposit.currency : '').toLowerCase();
            return symbol === 'BNB' || currency.indexOf('binance') !== -1;
        },

        getNetworkName(deposit) {
            if (!deposit) {
                return '';
            }

            if (this.isPlatformInternalTransfer(deposit)) {
                return deposit.network_display || legacyText("内部转账");
            }

            if (deposit.network_display) {
                return deposit.network_display;
            }

            if (deposit.network_name) {
                return deposit.network_name;
            }

            if (deposit.network) {
                return deposit.network;
            }

            const explorer = String(deposit.explorer || '').toLowerCase();
            const symbol = String(deposit.symbol || '').toUpperCase();
            const currency = String(deposit.currency || '').toLowerCase();

            if (explorer.indexOf('tronscan') !== -1) {
                return this.isNativeAssetOnTron(deposit) ? 'Tron (TRX)' : 'Tron (TRC20)';
            }

            if (explorer.indexOf('etherscan') !== -1) {
                return this.isNativeAssetOnEthereum(deposit) ? 'Ethereum (ETH)' : 'Ethereum (ERC20)';
            }

            if (explorer.indexOf('bscscan') !== -1) {
                return this.isNativeAssetOnBsc(deposit) ? 'BNB Smart Chain (BNB)' : 'BNB Smart Chain (BEP20)';
            }

            if (explorer.indexOf('polygonscan') !== -1) {
                if (symbol === 'MATIC' || symbol === 'POL' || currency.indexOf('polygon') !== -1) {
                    return 'Polygon (MATIC)';
                }
                return 'Polygon';
            }

            /**
             * 内部记录没有 explorer 的情况下做一个兜底：
             * - TRX / Tron -> Tron (TRX)
             * - USDT / USDC 等常见 Tron 资产默认 Tron (TRC20)
             * - ETH -> Ethereum (ETH)
             * - BNB -> BNB Smart Chain (BNB)
             */
            if (this.isInternalDeposit(deposit)) {
                if (this.isNativeAssetOnTron(deposit)) {
                    return 'Tron (TRX)';
                }

                if (this.isNativeAssetOnEthereum(deposit)) {
                    return 'Ethereum (ETH)';
                }

                if (this.isNativeAssetOnBsc(deposit)) {
                    return 'BNB Smart Chain (BNB)';
                }

                if (symbol === 'USDT' || symbol === 'USDC' || currency.indexOf('tether') !== -1 || currency.indexOf('usd coin') !== -1) {
                    return 'Tron (TRC20)';
                }
            }

            return '';
        },

        getFeeText(deposit) {
            if (!deposit) {
                return '';
            }

            const fee = this.toNumber(deposit.network_fee || deposit.system_fee || deposit.fee || 0);
            return this.formatNumber(fee, 8) + ' ' + (deposit.symbol || '');
        },

        getHashValue(deposit) {
            if (!deposit) {
                return '';
            }

            return deposit.txn || '';
        },

        getHashText(deposit, limit = 22) {
            const hash = this.getHashValue(deposit);
            return hash ? this.format_string(hash, limit) : '';
        },

        getDepositIdText(deposit, limit = 20) {
            if (!deposit || !deposit.deposit_id) {
                return '';
            }

            return this.format_string(deposit.deposit_id, limit);
        },

        getDepositTitle(deposit) {
            if (!deposit) {
                return '';
            }

            if (this.isPlatformInternalTransfer(deposit)) {
                return legacyText("内部转账入账 ") + this.formatAmount(deposit.amount, deposit.symbol);
            }

            return this.$t('Deposited') + ' ' + this.formatAmount(deposit.amount, deposit.symbol);
        },

        getExplorerButtonText(deposit) {
            if (!deposit || !deposit.explorer) {
                return this.$t('No explorer available');
            }

            return this.$t('View in explorer');
        },

        canOpenExplorer(deposit) {
            return !!(deposit && deposit.explorer);
        },

        openExplorer(deposit) {
            if (!this.canOpenExplorer(deposit)) {
                return;
            }

            window.open(deposit.explorer, '_blank');
        }
    },
})
</script>
