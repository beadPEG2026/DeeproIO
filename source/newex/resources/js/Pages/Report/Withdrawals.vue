<script>
import Template from '{Template}/Web/Pages/Report/Withdrawals.template'
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
        withdrawals: Object,
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

            selectedWithdrawal: null,
            showWithdrawalDetailModal: false,
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
                const date = new Date(stringValue.replace(' ', 'T'));
                return Number.isNaN(date.getTime()) ? null : date;
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
            this.$inertia.replace(this.route('reports.withdrawals', Object.keys(query).length ? query : { remember: 'forget' }), afterRequest)
        },

        openWithdrawalDetail(withdrawal) {
            this.selectedWithdrawal = withdrawal;
            this.showWithdrawalDetailModal = true;
        },

        closeWithdrawalDetail() {
            this.showWithdrawalDetailModal = false;
            this.selectedWithdrawal = null;
        },

        isUidWithdrawal(withdrawal) {
            if (!withdrawal) {
                return false;
            }

            const address = String(withdrawal.address || '');
            return address.indexOf('UID:') === 0;
        },

        isInternalAddress(withdrawal) {
            if (!withdrawal) {
                return false;
            }

            const address = String(withdrawal.address || '').toLowerCase();
            return address === 'internal';
        },

        isInternalWithdrawal(withdrawal) {
            if (!withdrawal) {
                return false;
            }

            return this.isUidWithdrawal(withdrawal) ||
                this.isInternalAddress(withdrawal) ||
                !!withdrawal.internal_id ||
                withdrawal.source_id === 'internal' ||
                withdrawal.extra_status === 'internal_completed';
        },

        getUidCode(withdrawal) {
            if (!withdrawal) {
                return '';
            }

            const address = String(withdrawal.address || '');

            if (address.indexOf('UID:') === 0) {
                return address.replace('UID:', '').trim();
            }

            if (withdrawal.internal_id) {
                return withdrawal.internal_id;
            }

            return '';
        },

        getAddressLabel(withdrawal) {
            if (this.isUidWithdrawal(withdrawal)) {
                return this.$t('UID');
            }

            return this.$t('Address');
        },

        getDisplayAddress(withdrawal) {
            if (!withdrawal) {
                return '';
            }

            if (this.isUidWithdrawal(withdrawal)) {
                const uid = this.getUidCode(withdrawal);
                return uid ? (this.$t('UID') + ' ' + uid + ' ' + this.$t('Withdrawal')) : '';
            }

            if (this.isInternalAddress(withdrawal)) {
                return this.$t('Internal Transfer');
            }

            return withdrawal.address || '';
        },

        getAddressCopyValue(withdrawal) {
            if (!withdrawal) {
                return '';
            }

            if (this.isUidWithdrawal(withdrawal)) {
                return this.getUidCode(withdrawal);
            }

            if (this.isInternalAddress(withdrawal)) {
                return '';
            }

            return withdrawal.address || '';
        },

        getStatusLabel(withdrawal) {
            if (!withdrawal) {
                return '-';
            }

            const status = String(withdrawal.status || '').toLowerCase();

            if (status === 'completed' || status === 'confirmed_provider' || status === 'confirmed') {
                return this.$t('Completed');
            }

            if (status === 'pending') {
                return this.$t('Pending');
            }

            if (status === 'rejected') {
                return this.$t('Rejected');
            }

            if (status === 'failed') {
                return this.$t('Failed');
            }

            return withdrawal.status || '-';
        },

        getStatusClass(withdrawal) {
            if (!withdrawal) {
                return 'label-orange';
            }

            const status = String(withdrawal.status || '').toLowerCase();

            if (status === 'completed' || status === 'confirmed_provider' || status === 'confirmed') {
                return 'label-green';
            }

            if (status === 'rejected' || status === 'failed') {
                return 'label-red';
            }

            return 'label-orange';
        },

        isNativeAssetOnTron(withdrawal) {
            const symbol = String(withdrawal && withdrawal.symbol ? withdrawal.symbol : '').toUpperCase();
            const currency = String(withdrawal && withdrawal.currency ? withdrawal.currency : '').toLowerCase();
            return symbol === 'TRX' || currency === 'tron';
        },

        isNativeAssetOnEthereum(withdrawal) {
            const symbol = String(withdrawal && withdrawal.symbol ? withdrawal.symbol : '').toUpperCase();
            const currency = String(withdrawal && withdrawal.currency ? withdrawal.currency : '').toLowerCase();
            return symbol === 'ETH' || currency.indexOf('ethereum') !== -1 || currency === 'ether';
        },

        isNativeAssetOnBsc(withdrawal) {
            const symbol = String(withdrawal && withdrawal.symbol ? withdrawal.symbol : '').toUpperCase();
            const currency = String(withdrawal && withdrawal.currency ? withdrawal.currency : '').toLowerCase();
            return symbol === 'BNB' || currency.indexOf('binance') !== -1;
        },

        getNetworkName(withdrawal) {
            if (!withdrawal) {
                return '';
            }

            if (withdrawal.network_display) {
                return withdrawal.network_display;
            }

            if (withdrawal.network_name) {
                return withdrawal.network_name;
            }

            if (withdrawal.network) {
                return withdrawal.network;
            }

            const explorer = String(withdrawal.explorer || '').toLowerCase();
            const symbol = String(withdrawal.symbol || '').toUpperCase();
            const currency = String(withdrawal.currency || '').toLowerCase();

            if (explorer.indexOf('tronscan') !== -1) {
                return this.isNativeAssetOnTron(withdrawal) ? 'Tron (TRX)' : 'Tron (TRC20)';
            }

            if (explorer.indexOf('etherscan') !== -1) {
                return this.isNativeAssetOnEthereum(withdrawal) ? 'Ethereum (ETH)' : 'Ethereum (ERC20)';
            }

            if (explorer.indexOf('bscscan') !== -1) {
                return this.isNativeAssetOnBsc(withdrawal) ? 'BNB Smart Chain (BNB)' : 'BNB Smart Chain (BEP20)';
            }

            if (explorer.indexOf('polygonscan') !== -1) {
                if (symbol === 'MATIC' || symbol === 'POL' || currency.indexOf('polygon') !== -1) {
                    return 'Polygon (MATIC)';
                }

                return 'Polygon';
            }

            if (this.isInternalWithdrawal(withdrawal)) {
                if (this.isNativeAssetOnTron(withdrawal)) {
                    return 'Tron (TRX)';
                }

                if (this.isNativeAssetOnEthereum(withdrawal)) {
                    return 'Ethereum (ETH)';
                }

                if (this.isNativeAssetOnBsc(withdrawal)) {
                    return 'BNB Smart Chain (BNB)';
                }

                if (symbol === 'USDT' || symbol === 'USDC' || currency.indexOf('tether') !== -1 || currency.indexOf('usd coin') !== -1) {
                    return 'Tron (TRC20)';
                }

                return this.$t('Internal Transfer');
            }

            return '';
        },

        getFeeText(withdrawal) {
            if (!withdrawal) {
                return '';
            }

            const fee = this.toNumber(withdrawal.fee || withdrawal.network_fee || withdrawal.system_fee || 0);
            return this.formatNumber(fee, 8) + ' ' + (withdrawal.symbol || '');
        },

        getHashValue(withdrawal) {
            if (!withdrawal) {
                return '';
            }

            return withdrawal.txn || '';
        },

        getHashText(withdrawal, limit = 22) {
            const hash = this.getHashValue(withdrawal);
            return hash ? this.format_string(hash, limit) : '';
        },

        getWithdrawalIdText(withdrawal, limit = 20) {
            if (!withdrawal || !withdrawal.withdrawal_id) {
                return '';
            }

            return this.format_string(withdrawal.withdrawal_id, limit);
        },

        getWithdrawalTitle(withdrawal) {
            if (!withdrawal) {
                return '';
            }

            return this.$t('Withdrawn') + ' ' + this.formatAmount(withdrawal.amount, withdrawal.symbol);
        },

        getExplorerButtonText(withdrawal) {
            if (!withdrawal || !withdrawal.explorer) {
                return this.$t('No explorer available');
            }

            return this.$t('View in explorer');
        },

        canOpenExplorer(withdrawal) {
            return !!(withdrawal && withdrawal.explorer);
        },

        openExplorer(withdrawal) {
            if (!this.canOpenExplorer(withdrawal)) {
                return;
            }

            window.open(withdrawal.explorer, '_blank');
        },
    },
})
</script>