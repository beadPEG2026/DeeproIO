<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/Reports/Withdrawals.template'
import AppLayout from '@/Layouts/AdminLayout'
import Welcome from '@/Jetstream/Welcome'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import Pagination from '@/Jetstream/Pagination'
import NavLink from "@/Jetstream/NavLink";
import Badge from "@/Jetstream/Badge";
import JetDialogModal from '@/Jetstream/DialogModal'
import JetConfirmationModal from '@/Jetstream/ConfirmationModal'
import JetSecondaryButton from '@/Jetstream/SecondaryButton'
import JetDangerButton from '@/Jetstream/DangerButton'
import throttle from 'lodash/throttle'
import {string_cut} from "@/Functions/String";
import AdminReportsTab from "@/Components/Reports/AdminReportsTab";

export default Template({
    components: {
        Badge,
        NavLink,
        NavButtonLink,
        AppLayout,
        Welcome,
        Pagination,
        JetDialogModal,
        JetSecondaryButton,
        JetConfirmationModal,
        JetDangerButton,
        AdminReportsTab
    },

    props: {
        withdrawals: Object,
        filters: Object,
        networks: {
            type: Array,
            default: () => [],
        },
    },

    data() {
        return {
            manualWithdrawTxn: '',
            manualWithdraw: false,
            withdrawalBeingReviewed: false,
            selectedAction: null,
            selectedWithdrawal: null,
            sending: false,

            form: {
                search: this.filters.search || null,
                type: this.filters.type || null,
                status: this.filters.status || null,
                network_id: this.filters.network_id || null,
                user_id: this.filters.user_id || null,
                txn: this.filters.txn || null,
                address: this.filters.address || null,
                period: this.filters.period || [],
                per_page: Number(this.filters.per_page || 100),
            },

            showReasonModal: false,
            rejectedReason: null,
            rejectedReasonText: "",
            selectedReferrerTitle: '',
            selectedReferrerChain: null,
        }
    },

    computed: {
        isSuperAdmin() {
            const roles = this.$page?.props?.user?.roles || [];

            return roles.some(role => {
                if (typeof role === 'string') {
                    return role === 'superadmin';
                }

                return role && (role.name === 'superadmin');
            });
        },

        withdrawalRows() {
            if (!this.withdrawals || !Array.isArray(this.withdrawals.data)) {
                return [];
            }

            return this.withdrawals.data;
        },

        realWithdrawalRows() {
            return this.withdrawalRows.filter((item) => {
                return !this.isVirtualWithdrawal(item);
            });
        },

        pendingWithdrawalRows() {
            return this.realWithdrawalRows.filter((item) => {
                return this.isPendingWithdrawal(item);
            });
        },

        approvedWithdrawalRows() {
            return this.realWithdrawalRows.filter((item) => {
                return this.isApprovedWithdrawal(item);
            });
        },

        rejectedWithdrawalRows() {
            return this.realWithdrawalRows.filter((item) => {
                return this.isRejectedWithdrawal(item);
            });
        },

        pendingWithdrawalUserCount() {
            return this.countUniqueUsers(this.pendingWithdrawalRows);
        },

        pendingWithdrawalAmount() {
            return this.sumWithdrawalAmount(this.pendingWithdrawalRows);
        },

        pendingWithdrawalFeeAmount() {
            return this.sumWithdrawalFee(this.pendingWithdrawalRows);
        },

        approvedWithdrawalUserCount() {
            return this.countUniqueUsers(this.approvedWithdrawalRows);
        },

        approvedWithdrawalAmount() {
            return this.sumWithdrawalAmount(this.approvedWithdrawalRows);
        },

        approvedWithdrawalFeeAmount() {
            return this.sumWithdrawalFee(this.approvedWithdrawalRows);
        },

        rejectedWithdrawalUserCount() {
            return this.countUniqueUsers(this.rejectedWithdrawalRows);
        },

        rejectedWithdrawalAmount() {
            return this.sumWithdrawalAmount(this.rejectedWithdrawalRows);
        },

        rejectedWithdrawalFeeAmount() {
            return this.sumWithdrawalFee(this.rejectedWithdrawalRows);
        },
    },

    methods: {
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

        formatAmount(value) {
            const number = this.toNumber(value);

            return number.toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 8,
            });
        },

        formatDate(value) {
            if (!value) {
                return '-';
            }

            if (typeof value === 'string') {
                return value
                    .replace('T', ' ')
                    .replace(/\.\d+Z$/, '')
                    .replace(/Z$/, '')
                    .slice(0, 19);
            }

            const date = new Date(value);

            if (Number.isNaN(date.getTime())) {
                return '-';
            }

            const pad = (number) => String(number).padStart(2, '0');

            return [
                date.getFullYear(),
                pad(date.getMonth() + 1),
                pad(date.getDate()),
            ].join('-') + ' ' + [
                pad(date.getHours()),
                pad(date.getMinutes()),
                pad(date.getSeconds()),
            ].join(':');
        },

        withdrawalPassedAt(withdrawal) {
            if (!withdrawal) {
                return null;
            }

            return withdrawal.updated_at || withdrawal.created_at;
        },

        isPendingWithdrawal(item) {
            return item && item.status === 'waiting_approval';
        },

        isApprovedWithdrawal(item) {
            if (!item || !item.status) {
                return false;
            }

            return [
                'confirmed_system',
                'waiting_provider_approval',
                'confirmed_provider',
            ].includes(item.status);
        },

        isRejectedWithdrawal(item) {
            if (!item || !item.status) {
                return false;
            }

            return [
                'rejected',
                'failed',
            ].includes(item.status);
        },

        isVirtualWithdrawal(item) {
            if (!item) {
                return false;
            }

            const user = item.user || {};

            return item.is_xn === true
                || item.is_xn === 1
                || item.is_xn === '1'
                || item.is_xm === true
                || item.is_xm === 1
                || item.is_xm === '1'
                || item.user_is_xn === true
                || item.user_is_xn === 1
                || item.user_is_xn === '1'
                || item.user_is_xm === true
                || item.user_is_xm === 1
                || item.user_is_xm === '1'
                || user.is_xn === true
                || user.is_xn === 1
                || user.is_xn === '1'
                || user.is_xm === true
                || user.is_xm === 1
                || user.is_xm === '1';
        },

        countUniqueUsers(rows) {
            const userIds = [];

            rows.forEach((item) => {
                if (item.user && item.user.id && !userIds.includes(item.user.id)) {
                    userIds.push(item.user.id);
                }
            });

            return userIds.length;
        },

        sumWithdrawalAmount(rows) {
            return rows.reduce((total, item) => {
                return total + this.toNumber(item.amount);
            }, 0);
        },

        sumWithdrawalFee(rows) {
            return rows.reduce((total, item) => {
                return total + this.toNumber(item.fee);
            }, 0);
        },

        getReferrerChain(row) {
            if (row && Array.isArray(row.referrer_chain)) {
                return row.referrer_chain;
            }

            if (row && row.user && Array.isArray(row.user.referrer_chain)) {
                return row.user.referrer_chain;
            }

            return [];
        },

        getReferrerDisplay(row) {
            if (row && row.referrer_display_name) {
                return row.referrer_display_name;
            }

            if (row && row.user && row.user.referrer_display_name) {
                return row.user.referrer_display_name;
            }

            return 'N/A';
        },

        showReferrerChain(row) {
            const userId = row && row.user ? (row.user.referral_code || row.user.id) : '';

            this.selectedReferrerTitle = legacyText("用户 {value0} 的所有上级", {value0: userId});
            this.selectedReferrerChain = this.getReferrerChain(row);
        },

        closeReferrerChain() {
            this.selectedReferrerTitle = '';
            this.selectedReferrerChain = null;
        },

        referrerPrimaryName(item) {
            if (!item) {
                return '-';
            }

            return (item.nickname && item.nickname !== '-')
                ? item.nickname
                : (item.account || item.name || `ID: ${item.id}`);
        },

        doCopy(string) {
            this.$copyText(string).then(() => {
                this.$toast.open(legacyText("Text was copied to the clipboard"));
            }, function (e) {

            })
        },

        reset() {
            this.form = {
                search: null,
                type: null,
                status: null,
                network_id: null,
                user_id: null,
                txn: null,
                address: null,
                period: [],
                per_page: 100,
            }
        },

        buildQuery() {
            const query = {};

            if (this.form.search) query.search = this.form.search;
            if (this.form.type) query.type = this.form.type;
            if (this.form.status) query.status = this.form.status;
            if (this.form.network_id) query.network_id = this.form.network_id;
            if (this.form.user_id) query.user_id = this.form.user_id;
            if (this.form.txn) query.txn = this.form.txn;
            if (this.form.address) query.address = this.form.address;

            if (this.form.period && this.form.period.length === 2) {
                query['period[0]'] = this.form.period[0];
                query['period[1]'] = this.form.period[1];
            }

            if (this.form.per_page) {
                query.per_page = this.form.per_page;
            }

            if (this.filters && this.filters.referrer) {
                query.referrer = this.filters.referrer;
            }

            if (this.filters.team_user_id) query.team_user_id = this.filters.team_user_id;
            return query;
        },

        getList() {
            const query = this.buildQuery();
            const params = new URLSearchParams(query).toString();
            const url = `/exchange-control-panel/reports/withdrawals${params ? '?' + params : '?remember=forget'}`;
            this.$inertia.replace(url);
        },

        exportExcel() {
            const query = this.buildQuery();
            const params = new URLSearchParams(query).toString();
            const url = `/exchange-control-panel/reports/withdrawals/export${params ? '?' + params : ''}`;
            window.location.href = url;
        },

        async approve(withdrawal) {
            if (!this.isSuperAdmin) {
                return this.$toast.error(legacyText("只有超级管理员可以操作提现"));
            }

            this.manualWithdrawTxn = '';
            this.manualWithdraw = false;
            this.selectedWithdrawal = withdrawal;
            this.selectedAction = 'approve';

            await this.directApproveWithdrawal(withdrawal);
        },

        reject(withdrawal) {
            if (!this.isSuperAdmin) {
                return this.$toast.error(legacyText("只有超级管理员可以操作提现"));
            }

            this.manualWithdrawTxn = '';
            this.manualWithdraw = false;
            this.rejectedReasonText = '';
            this.selectedWithdrawal = withdrawal;
            this.selectedAction = 'reject';
            this.withdrawalBeingReviewed = true;
        },

        refresh() {
            this.getList();
            this.$toast.success(this.$t ? this.$t('Withdrawal states are refreshed') : legacyText("Withdrawal states are refreshed"))
        },

        normalizeLowerText(value) {
            return String(value || '').replace(/[A-Z]/g, function (char) {
                return String.fromCharCode(char.charCodeAt(0) + 32);
            });
        },

        getCurrencySymbol(withdrawal) {
            if (!withdrawal) {
                return '';
            }

            if (withdrawal.currency && typeof withdrawal.currency === 'object') {
                return String(withdrawal.currency.symbol || '').toUpperCase();
            }

            if (typeof withdrawal.currency === 'string') {
                return String(withdrawal.currency || '').toUpperCase();
            }

            if (typeof withdrawal.symbol === 'string') {
                return String(withdrawal.symbol || '').toUpperCase();
            }

            if (typeof withdrawal.currency_symbol === 'string') {
                return String(withdrawal.currency_symbol || '').toUpperCase();
            }

            return '';
        },

        getNetworkName(withdrawal) {
            if (!withdrawal) {
                return '';
            }

            let name = '';

            if (withdrawal.network && typeof withdrawal.network === 'object') {
                name =
                    withdrawal.network.name ||
                    withdrawal.network.slug ||
                    withdrawal.network.title ||
                    withdrawal.network.text ||
                    '';
            } else if (typeof withdrawal.network === 'string') {
                name = withdrawal.network;
            } else if (typeof withdrawal.network_name === 'string') {
                name = withdrawal.network_name;
            } else if (typeof withdrawal.network_slug === 'string') {
                name = withdrawal.network_slug;
            } else if (typeof withdrawal.network_title === 'string') {
                name = withdrawal.network_title;
            }

            return this.normalizeLowerText(name)
                .replace(/\s+/g, '')
                .replace(/-/g, '')
                .replace(/_/g, '');
        },

        getWithdrawalWalletType(withdrawal) {
            const networkId = Number(withdrawal && withdrawal.network_id ? withdrawal.network_id : 0);

            if (networkId === 0) {
                return null;
            }

            if (networkId === 8) {
                return 'tron';
            }

            if ([3, 6, 15, 16].includes(networkId)) {
                return 'evm';
            }

            const networkName = this.getNetworkName(withdrawal);
            const symbol = this.getCurrencySymbol(withdrawal);
            const address = String((withdrawal && withdrawal.address) || '').trim();

            if (
                address.startsWith('T') &&
                ['USDT', 'USDC', 'TRX'].includes(symbol)
            ) {
                return 'tron';
            }

            if (
                address.startsWith('0x') &&
                ['USDT', 'USDC', 'ETH', 'BNB', 'MATIC'].includes(symbol)
            ) {
                return 'evm';
            }

            if (
                networkName.includes('tron') ||
                networkName.includes('trc') ||
                networkName.includes('trc20') ||
                networkName.includes('trx')
            ) {
                return 'tron';
            }

            if (
                networkName.includes('ethereum') ||
                networkName.includes('erc') ||
                networkName.includes('erc20') ||
                networkName.includes('eth') ||
                networkName.includes('bsc') ||
                networkName.includes('bep') ||
                networkName.includes('bep20') ||
                networkName.includes('bnb') ||
                networkName.includes('binancesmartchain') ||
                networkName.includes('matic') ||
                networkName.includes('matic20') ||
                networkName.includes('polygon')
            ) {
                return 'evm';
            }

            return null;
        },

        getEvmChainConfig(withdrawal) {
            const networkId = Number(withdrawal && withdrawal.network_id ? withdrawal.network_id : 0);
            const networkName = this.getNetworkName(withdrawal);

            if (
                networkId === 15 ||
                networkId === 16 ||
                networkName.includes('matic') ||
                networkName.includes('matic20') ||
                networkName.includes('polygon')
            ) {
                return {
                    key: 'polygon',
                    chainId: '0x89',
                    addChainParams: {
                        chainId: '0x89',
                        chainName: 'Polygon Mainnet',
                        nativeCurrency: {
                            name: 'MATIC',
                            symbol: 'MATIC',
                            decimals: 18,
                        },
                        rpcUrls: ['https://polygon-rpc.com'],
                        blockExplorerUrls: ['https://polygonscan.com'],
                    },
                };
            }

            if (
                networkId === 6 ||
                networkName.includes('bsc') ||
                networkName.includes('bep') ||
                networkName.includes('bep20') ||
                networkName.includes('bnb') ||
                networkName.includes('binancesmartchain')
            ) {
                return {
                    key: 'bsc',
                    chainId: '0x38',
                    addChainParams: {
                        chainId: '0x38',
                        chainName: 'BNB Smart Chain',
                        nativeCurrency: {
                            name: 'BNB',
                            symbol: 'BNB',
                            decimals: 18,
                        },
                        rpcUrls: ['https://bsc-dataseed.binance.org/'],
                        blockExplorerUrls: ['https://bscscan.com'],
                    },
                };
            }

            return {
                key: 'ethereum',
                chainId: '0x1',
                addChainParams: {
                    chainId: '0x1',
                    chainName: 'Ethereum Mainnet',
                    nativeCurrency: {
                        name: 'Ether',
                        symbol: 'ETH',
                        decimals: 18,
                    },
                    rpcUrls: ['https://rpc.ankr.com/eth'],
                    blockExplorerUrls: ['https://etherscan.io'],
                },
            };
        },

        getEvmTokenConfig(withdrawal) {
            const symbol = this.getCurrencySymbol(withdrawal);
            const chain = this.getEvmChainConfig(withdrawal).key;

            if (symbol === 'USDT' && chain === 'ethereum') {
                return {
                    symbol: 'USDT',
                    contract: '0xdAC17F958D2ee523a2206206994597C13D831ec7',
                    decimals: 6,
                };
            }

            if (symbol === 'USDT' && chain === 'bsc') {
                return {
                    symbol: 'USDT',
                    contract: '0x55d398326f99059fF775485246999027B3197955',
                    decimals: 18,
                };
            }

            if (symbol === 'USDC' && chain === 'ethereum') {
                return {
                    symbol: 'USDC',
                    contract: '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48',
                    decimals: 6,
                };
            }

            if (symbol === 'USDC' && chain === 'bsc') {
                return {
                    symbol: 'USDC',
                    contract: '0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d',
                    decimals: 18,
                };
            }

            if (symbol === 'USDT' && chain === 'polygon') {
                return {
                    symbol: 'USDT',
                    contract: '0xc2132D05D31c914a87C6611C10748AEb04B58e8F',
                    decimals: 6,
                };
            }

            if (symbol === 'USDC' && chain === 'polygon') {
                return {
                    symbol: 'USDC',
                    contract: '0x2791Bca1f2de4661ED88A30C99A7a9449Aa84174',
                    decimals: 6,
                };
            }

            return null;
        },

        getTronTokenConfig(withdrawal) {
            const symbol = this.getCurrencySymbol(withdrawal);

            if (symbol === 'USDT') {
                return {
                    symbol: 'USDT',
                    contract: 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
                    decimals: 6,
                };
            }

            if (symbol === 'USDC') {
                return {
                    symbol: 'USDC',
                    contract: 'TEkxiTehnzSmSe2XqrBj4w32RUN966rdz8',
                    decimals: 6,
                };
            }

            return null;
        },

        getTransferAmount(withdrawal) {
            return this.subDecimal(
                withdrawal && withdrawal.amount !== undefined ? withdrawal.amount : '0',
                withdrawal && withdrawal.fee !== undefined ? withdrawal.fee : '0',
                18
            );
        },

        subDecimal(a, b, precision = 18) {
            const aInt = this.toBaseUnitBigInt(a || '0', precision);
            const bInt = this.toBaseUnitBigInt(b || '0', precision);

            if (aInt <= bInt) {
                throw new Error(legacyText("提现金额必须大于手续费"));
            }

            return this.fromBaseUnit((aInt - bInt).toString(), precision);
        },

        toBaseUnit(value, decimals) {
            return this.toBaseUnitBigInt(value, decimals).toString();
        },

        toBaseUnitBigInt(value, decimals) {
            const str = String(value || '0').replace(/,/g, '').trim();

            if (!/^\d+(\.\d+)?$/.test(str)) {
                throw new Error(legacyText("金额格式不正确"));
            }

            decimals = Number(decimals);

            if (!Number.isInteger(decimals) || decimals < 0) {
                throw new Error(legacyText("币种精度不正确"));
            }

            const parts = str.split('.');
            const intPart = parts[0] || '0';
            const decimalPart = parts[1] || '';

            const fixedDecimal = (decimalPart + '0'.repeat(decimals)).slice(0, decimals);
            const base = BigInt('1' + '0'.repeat(decimals));

            return BigInt(intPart) * base + BigInt(fixedDecimal || '0');
        },

        fromBaseUnit(value, decimals) {
            decimals = Number(decimals);

            const raw = String(value || '0');
            const negative = raw.startsWith('-');
            const str = raw.replace(/^-/, '');

            if (decimals <= 0) {
                return negative ? '-' + str : str;
            }

            const padded = str.padStart(decimals + 1, '0');
            const intPart = padded.slice(0, -decimals) || '0';
            const decimalPart = padded.slice(-decimals).replace(/0+$/, '');

            const result = decimalPart ? `${intPart}.${decimalPart}` : intPart;

            return negative ? '-' + result : result;
        },

        toHex(value) {
            return '0x' + BigInt(String(value)).toString(16);
        },

        encodeErc20Transfer(to, amountBaseUnit) {
            const methodId = 'a9059cbb';
            const cleanTo = this.normalizeLowerText(String(to || '').replace(/^0x/, ''));

            if (!/^[0-9a-f]{40}$/.test(cleanTo)) {
                throw new Error(legacyText("EVM 收款地址格式不正确"));
            }

            const encodedTo = cleanTo.padStart(64, '0');
            const encodedAmount = BigInt(String(amountBaseUnit)).toString(16).padStart(64, '0');

            return '0x' + methodId + encodedTo + encodedAmount;
        },

        sleep(milliseconds) {
            return new Promise((resolve) => {
                setTimeout(resolve, milliseconds);
            });
        },

        getTrustEthereumProviders() {
            const providers = [];
            const trustRoots = [
                window.trustwallet,
                window.trustWallet,
                window.TrustWallet,
            ].filter(Boolean);

            for (let i = 0; i < trustRoots.length; i++) {
                const root = trustRoots[i];
                const candidates = [
                    root.ethereum,
                    root.provider,
                    root.evm,
                    root.providers && root.providers.ethereum,
                ].filter(Boolean);

                for (let j = 0; j < candidates.length; j++) {
                    const provider = candidates[j];

                    if (provider && typeof provider.request === 'function') {
                        providers.push(provider);
                    }
                }
            }

            if (window.ethereum && Array.isArray(window.ethereum.providers)) {
                window.ethereum.providers.forEach((provider) => {
                    if (provider && (
                        provider.isTrust ||
                        provider.isTrustWallet ||
                        provider.isTrustWalletProvider
                    )) {
                        providers.push(provider);
                    }
                });
            }

            if (window.ethereum && (
                window.ethereum.isTrust ||
                window.ethereum.isTrustWallet ||
                window.ethereum.isTrustWalletProvider
            )) {
                providers.push(window.ethereum);
            }

            if (window.ethereum) {
                providers.push(window.ethereum);
            }

            return providers
                .filter(Boolean)
                .filter((provider, index, items) => items.indexOf(provider) === index);
        },

        getTrustEthereumProvider() {
            const providers = this.getTrustEthereumProviders();

            return providers.length ? providers[0] : null;
        },

        isTrustEvmNoAccountError(error) {
            const message = error && error.message ? String(error.message) : '';
            const dataMethod = error && error.data && error.data.method ? String(error.data.method) : '';

            return (
                message.indexOf('Unable to find any account for 60') !== -1 ||
                message.indexOf('Unable to find any account') !== -1 ||
                dataMethod.indexOf('PUBLIC_requestAccounts') !== -1
            );
        },

        async connectTrustEvmWallet(chainConfig = null) {
            const providers = this.getTrustEthereumProviders();

            if (!providers.length) {
                throw new Error(legacyText("请使用 Trust Wallet 浏览器扩展或 Trust Wallet 内置浏览器打开"));
            }

            let lastError = null;

            for (let i = 0; i < providers.length; i++) {
                const provider = providers[i];

                if (!provider || typeof provider.request !== 'function') {
                    continue;
                }

                try {
                    if (chainConfig) {
                        await this.switchOrAddEvmChain(provider, chainConfig);
                    }

                    const accounts = await provider.request({
                        method: 'eth_requestAccounts',
                    });

                    if (accounts && accounts.length) {
                        return {
                            provider,
                            account: accounts[0],
                        };
                    }
                } catch (e) {
                    lastError = e;

                    if (e && Number(e.code) === 4001) {
                        throw new Error(legacyText("用户取消了钱包确认"));
                    }

                    if (e && Number(e.code) === -32002) {
                        throw new Error(legacyText("Trust Wallet 已有授权窗口正在处理，请先在插件里完成或取消当前授权请求"));
                    }

                    if (!this.isTrustEvmNoAccountError(e)) {
                        continue;
                    }
                }
            }

            if (lastError && this.isTrustEvmNoAccountError(lastError)) {
                const chainName = chainConfig && chainConfig.addChainParams
                    ? chainConfig.addChainParams.chainName
                    : legacyText("对应 EVM 网络");

                throw new Error(legacyText("Trust Wallet 当前没有可用的 EVM 账户，请先在 Trust Wallet 里添加或启用 {value0} 钱包后重试", {value0: chainName}));
            }

            throw new Error(lastError && lastError.message ? lastError.message : legacyText("没有获取到 Trust Wallet 钱包地址"));
        },

        async switchOrAddEvmChain(provider, chainConfig) {
            try {
                await provider.request({
                    method: 'wallet_switchEthereumChain',
                    params: [{ chainId: chainConfig.chainId }],
                });
            } catch (e) {
                if (e && Number(e.code) === 4902) {
                    await provider.request({
                        method: 'wallet_addEthereumChain',
                        params: [chainConfig.addChainParams],
                    });
                    return;
                }

                throw e;
            }
        },

        async sendEvmWithdrawal(withdrawal) {
            const chainConfig = this.getEvmChainConfig(withdrawal);
            const wallet = await this.connectTrustEvmWallet(chainConfig);
            const provider = wallet.provider;
            const from = wallet.account;

            await this.switchOrAddEvmChain(provider, chainConfig);

            const symbol = this.getCurrencySymbol(withdrawal);
            const amount = this.getTransferAmount(withdrawal);
            const tokenConfig = this.getEvmTokenConfig(withdrawal);
            const toAddress = String(withdrawal && withdrawal.address ? withdrawal.address : '').trim();

            if (!toAddress) {
                throw new Error(legacyText("提现地址不能为空"));
            }

            if (tokenConfig) {
                const amountBaseUnit = this.toBaseUnit(amount, tokenConfig.decimals);

                const txHash = await provider.request({
                    method: 'eth_sendTransaction',
                    params: [{
                        from: from,
                        to: tokenConfig.contract,
                        value: '0x0',
                        data: this.encodeErc20Transfer(toAddress, amountBaseUnit),
                    }],
                });

                return txHash;
            }

            if (symbol === 'ETH') {
                if (chainConfig.key !== 'ethereum') {
                    throw new Error(legacyText("当前网络不是 Ethereum，不能直接发送 ETH 主币"));
                }

                const valueWei = this.toBaseUnit(amount, 18);

                const txHash = await provider.request({
                    method: 'eth_sendTransaction',
                    params: [{
                        from: from,
                        to: toAddress,
                        value: this.toHex(valueWei),
                        data: '0x',
                    }],
                });

                return txHash;
            }

            if (symbol === 'BNB') {
                if (chainConfig.key !== 'bsc') {
                    throw new Error(legacyText("当前网络不是 BSC，不能直接发送 BNB 主币"));
                }

                const valueWei = this.toBaseUnit(amount, 18);

                const txHash = await provider.request({
                    method: 'eth_sendTransaction',
                    params: [{
                        from: from,
                        to: toAddress,
                        value: this.toHex(valueWei),
                        data: '0x',
                    }],
                });

                return txHash;
            }

            if (symbol === 'MATIC') {
                if (chainConfig.key !== 'polygon') {
                    throw new Error(legacyText("当前网络不是 Polygon，不能直接发送 MATIC 主币"));
                }

                const valueWei = this.toBaseUnit(amount, 18);

                const txHash = await provider.request({
                    method: 'eth_sendTransaction',
                    params: [{
                        from: from,
                        to: toAddress,
                        value: this.toHex(valueWei),
                        data: '0x',
                    }],
                });

                return txHash;
            }

            throw new Error(legacyText("暂不支持在该 EVM 网络发送 {value0}", {value0: symbol}));
        },

        getTronLinkProvider() {
            if (window.tronLink) {
                return window.tronLink;
            }

            if (window.tron) {
                return window.tron;
            }

            return null;
        },

        async waitForTronLinkProvider() {
            for (let i = 0; i < 10; i++) {
                const provider = this.getTronLinkProvider();
                const tronWeb = window.tronWeb || (provider && provider.tronWeb);

                if (provider || tronWeb) {
                    return {
                        provider,
                        tronWeb,
                    };
                }

                await this.sleep(300);
            }

            const provider = this.getTronLinkProvider();

            return {
                provider,
                tronWeb: window.tronWeb || (provider && provider.tronWeb),
            };
        },

        getTronLinkAddress(tronWeb) {
            if (!tronWeb) {
                return '';
            }

            const defaultAddress = tronWeb.defaultAddress || {};

            if (defaultAddress.base58) {
                return defaultAddress.base58;
            }

            const hexAddress = defaultAddress.hex || tronWeb.defaultAddressHex || '';

            if (hexAddress && tronWeb.address && typeof tronWeb.address.fromHex === 'function') {
                return tronWeb.address.fromHex(hexAddress);
            }

            return '';
        },

        async connectTronLinkWallet() {
            let result = await this.waitForTronLinkProvider();
            let provider = result.provider;
            let tronWeb = result.tronWeb;

            if (!provider && !tronWeb) {
                throw new Error(legacyText("请安装并连接 TronLink 插件"));
            }

            if (provider && typeof provider.request === 'function') {
                try {
                    await provider.request({
                        method: 'tron_requestAccounts',
                    });
                } catch (e) {
                    if (e && Number(e.code) === 4001) {
                        throw new Error(legacyText("用户取消了 TronLink 授权"));
                    }

                    if (e && Number(e.code) === -32002) {
                        throw new Error(legacyText("TronLink 已有授权窗口正在处理，请先在插件里完成或取消当前授权请求"));
                    }

                    throw new Error(e && e.message ? e.message : legacyText("TronLink 授权失败"));
                }
            }

            await this.sleep(200);

            result = await this.waitForTronLinkProvider();
            provider = result.provider || provider;
            tronWeb = window.tronWeb || result.tronWeb || (provider && provider.tronWeb);

            if (!tronWeb) {
                throw new Error(legacyText("TronLink 未注入 tronWeb，请确认插件已连接当前网站"));
            }

            const fromAddress = this.getTronLinkAddress(tronWeb);

            if (!fromAddress) {
                throw new Error(legacyText("TronLink 未授权当前网站的 TRON 地址，请先在插件中连接钱包"));
            }

            return {
                provider,
                tronWeb,
                fromAddress,
            };
        },

        async sendTronLinkTrx(wallet, toAddress, amountSun) {
            const transaction = await wallet.tronWeb.transactionBuilder.sendTrx(
                toAddress,
                Number(amountSun),
                wallet.fromAddress
            );

            const signed = await wallet.tronWeb.trx.sign(transaction);
            const result = await wallet.tronWeb.trx.sendRawTransaction(signed);
            const txHash = this.extractTxHash(result) || transaction.txID;

            if (!txHash) {
                throw new Error(legacyText("TRX 交易未返回 txHash"));
            }

            return txHash;
        },

        async sendTronLinkTrc20(wallet, tokenConfig, toAddress, amountBaseUnit) {
            const triggerResult = await wallet.tronWeb.transactionBuilder.triggerSmartContract(
                tokenConfig.contract,
                'transfer(address,uint256)',
                {
                    feeLimit: 150000000,
                    callValue: 0,
                },
                [
                    {
                        type: 'address',
                        value: toAddress,
                    },
                    {
                        type: 'uint256',
                        value: amountBaseUnit.toString(),
                    },
                ],
                wallet.fromAddress
            );

            const transaction = triggerResult && triggerResult.transaction
                ? triggerResult.transaction
                : triggerResult;

            if (!transaction) {
                throw new Error(legacyText("TRC20 交易构建失败"));
            }

            const signed = await wallet.tronWeb.trx.sign(transaction);
            const result = await wallet.tronWeb.trx.sendRawTransaction(signed);
            const txHash = this.extractTxHash(result) || transaction.txID;

            if (!txHash) {
                throw new Error(legacyText("TRC20 交易未返回 txHash"));
            }

            return txHash;
        },

        getTrustTronProvider() {
            if (window.trustwallet && window.trustwallet.tron) {
                return window.trustwallet.tron;
            }

            if (window.tron && (
                window.tron.isTrust ||
                window.tron.isTrustWallet ||
                window.tron.isTrustWalletProvider
            )) {
                return window.tron;
            }

            if (window.tron) {
                return window.tron;
            }

            if (window.tronLink) {
                return window.tronLink;
            }

            return null;
        },

        getTrustTronWebFromProvider(provider) {
            if (provider && provider.tronWeb) {
                return provider.tronWeb;
            }

            if (window.trustwallet && window.trustwallet.tronWeb) {
                return window.trustwallet.tronWeb;
            }

            if (window.tronWeb) {
                return window.tronWeb;
            }

            return null;
        },

        async waitForTrustTronProvider() {
            let provider = this.getTrustTronProvider();
            let tronWeb = this.getTrustTronWebFromProvider(provider);

            if (tronWeb && tronWeb.defaultAddress && tronWeb.defaultAddress.base58) {
                return {
                    provider,
                    tronWeb,
                };
            }

            for (let i = 0; i < 10; i++) {
                await this.sleep(300);

                provider = this.getTrustTronProvider();
                tronWeb = this.getTrustTronWebFromProvider(provider);

                if (tronWeb && tronWeb.defaultAddress && tronWeb.defaultAddress.base58) {
                    return {
                        provider,
                        tronWeb,
                    };
                }
            }

            return {
                provider,
                tronWeb,
            };
        },

        async requestTrustTronAccounts(provider) {
            if (!provider || typeof provider.request !== 'function') {
                return null;
            }

            const methods = [
                'tron_requestAccounts',
                'eth_requestAccounts',
            ];

            let lastError = null;

            for (let i = 0; i < methods.length; i++) {
                try {
                    const accounts = await provider.request({
                        method: methods[i],
                    });

                    if (accounts && accounts.length) {
                        return accounts;
                    }
                } catch (e) {
                    lastError = e;

                    if (e && Number(e.code) === 4001) {
                        throw new Error(legacyText("用户取消了 Trust Wallet 授权"));
                    }
                }
            }

            if (lastError && lastError.message) {
                console.warn('Trust Wallet TRON 授权请求失败：', lastError.message);
            }

            return null;
        },

        async connectTrustTronWallet() {
            let result = await this.waitForTrustTronProvider();
            let provider = result.provider;
            let tronWeb = result.tronWeb;

            if (!provider && !tronWeb) {
                throw new Error(legacyText("没有检测到 Trust Wallet 的 TRON Provider，请确认 Trust Wallet 已连接 Tron 网络"));
            }

            await this.requestTrustTronAccounts(provider);

            result = await this.waitForTrustTronProvider();
            provider = result.provider;
            tronWeb = result.tronWeb;

            if (!tronWeb) {
                throw new Error(legacyText("Trust Wallet 已连接，但网页没有获取到 tronWeb"));
            }

            if (!tronWeb.defaultAddress || !tronWeb.defaultAddress.base58) {
                throw new Error(legacyText("Trust Wallet 未授权当前网站的 TRON 地址，请断开后重新连接 Tron 网络"));
            }

            return {
                provider,
                tronWeb,
                fromAddress: tronWeb.defaultAddress.base58,
            };
        },

        normalizeWalletNumber(value) {
            if (value === null || value === undefined || value === '') {
                return '0';
            }

            if (typeof value === 'bigint') {
                return value.toString();
            }

            if (typeof value === 'number') {
                return String(Math.trunc(value));
            }

            if (typeof value === 'string') {
                const str = value.trim();

                if (/^0x[0-9a-f]+$/i.test(str)) {
                    return BigInt(str).toString();
                }

                if (/^\d+$/.test(str)) {
                    return str;
                }

                const matched = str.match(/\d+/);
                return matched ? matched[0] : '0';
            }

            if (typeof value === 'object') {
                if (value._hex) {
                    return BigInt(value._hex).toString();
                }

                if (value.hex) {
                    return BigInt(value.hex).toString();
                }

                if (value.balance) {
                    return this.normalizeWalletNumber(value.balance);
                }

                if (value.value) {
                    return this.normalizeWalletNumber(value.value);
                }

                if (value._value) {
                    return this.normalizeWalletNumber(value._value);
                }

                if (Array.isArray(value) && value.length) {
                    return this.normalizeWalletNumber(value[0]);
                }

                if (typeof value.toString === 'function') {
                    const str = value.toString();

                    if (/^\d+$/.test(str)) {
                        return str;
                    }

                    if (/^0x[0-9a-f]+$/i.test(str)) {
                        return BigInt(str).toString();
                    }
                }
            }

            return '0';
        },

        extractTxHash(result) {
            if (!result) {
                return '';
            }

            if (typeof result === 'string') {
                return result;
            }

            if (result.txid) {
                return result.txid;
            }

            if (result.txID) {
                return result.txID;
            }

            if (result.hash) {
                return result.hash;
            }

            if (result.txHash) {
                return result.txHash;
            }

            if (result.transactionHash) {
                return result.transactionHash;
            }

            if (result.id) {
                return result.id;
            }

            if (result.transaction && result.transaction.txID) {
                return result.transaction.txID;
            }

            if (result.result && typeof result.result === 'string') {
                return result.result;
            }

            return '';
        },

        async getTrc20Contract(tronWeb, tokenConfig) {
            try {
                if (tronWeb.contract && typeof tronWeb.contract === 'function') {
                    const contractBuilder = tronWeb.contract();

                    if (contractBuilder && typeof contractBuilder.at === 'function') {
                        return await contractBuilder.at(tokenConfig.contract);
                    }
                }
            } catch (e) {
                console.warn('通过 contract().at 获取 TRC20 合约失败，改用 ABI 方式：', e);
            }

            const trc20Abi = [
                {
                    constant: true,
                    inputs: [
                        {
                            name: '_owner',
                            type: 'address',
                        },
                    ],
                    name: 'balanceOf',
                    outputs: [
                        {
                            name: 'balance',
                            type: 'uint256',
                        },
                    ],
                    payable: false,
                    stateMutability: 'view',
                    type: 'function',
                },
                {
                    constant: false,
                    inputs: [
                        {
                            name: '_to',
                            type: 'address',
                        },
                        {
                            name: '_value',
                            type: 'uint256',
                        },
                    ],
                    name: 'transfer',
                    outputs: [
                        {
                            name: '',
                            type: 'bool',
                        },
                    ],
                    payable: false,
                    stateMutability: 'nonpayable',
                    type: 'function',
                },
            ];

            return await tronWeb.contract(trc20Abi, tokenConfig.contract);
        },

        async sendTronWithdrawal(withdrawal) {
            const wallet = await this.connectTronLinkWallet();
            const tronWeb = wallet.tronWeb;

            const symbol = this.getCurrencySymbol(withdrawal);
            const amount = this.getTransferAmount(withdrawal);
            const tokenConfig = this.getTronTokenConfig(withdrawal);
            const toAddress = String(withdrawal && withdrawal.address ? withdrawal.address : '').trim();

            if (!toAddress) {
                throw new Error(legacyText("提现地址不能为空"));
            }

            if (typeof tronWeb.isAddress === 'function' && !tronWeb.isAddress(toAddress)) {
                throw new Error(legacyText("TRON 收款地址格式不正确"));
            }

            if (tokenConfig) {
                const amountBaseUnit = this.toBaseUnit(amount, tokenConfig.decimals);

                if (BigInt(amountBaseUnit) <= 0n) {
                    throw new Error(legacyText("TRC20 转账金额必须大于 0"));
                }

                try {
                    return await this.sendTronLinkTrc20(
                        wallet,
                        tokenConfig,
                        toAddress,
                        amountBaseUnit
                    );
                } catch (e) {
                    let message = e && e.message ? e.message : legacyText("TronLink TRC20 交易失败");

                    if (e && Number(e.code) === 4001) {
                        message = legacyText("用户取消了钱包确认");
                    }

                    throw new Error(message);
                }
            }

            if (symbol === 'TRX') {
                const sun = this.toBaseUnit(amount, 6);

                if (BigInt(sun) <= 0n) {
                    throw new Error(legacyText("TRX 转账金额必须大于 0"));
                }

                try {
                    return await this.sendTronLinkTrx(
                        wallet,
                        toAddress,
                        sun
                    );
                } catch (e) {
                    let message = e && e.message ? e.message : legacyText("TronLink TRX 交易失败");

                    if (e && Number(e.code) === 4001) {
                        message = legacyText("用户取消了钱包确认");
                    }

                    throw new Error(message);
                }
            }

            throw new Error(legacyText("暂不支持在 TRON 网络发送 {value0}", {value0: symbol}));
        },

        openManualApproveModal(withdrawal, message) {
            this.manualWithdrawTxn = '';
            this.manualWithdraw = true;
            this.selectedWithdrawal = withdrawal;
            this.selectedAction = 'approve';
            this.withdrawalBeingReviewed = true;
            this.sending = false;

            this.$toast.error(message || legacyText("无法自动发起钱包交易，请手动转账后填写交易哈希。"));
        },

        async directApproveWithdrawal(withdrawal) {
            if (this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            if (this.sending) {
                return;
            }

            this.sending = true;

            const actionRoute = this.route('admin.reports.withdrawals.moderate', withdrawal.id);

            const form = {
                action: 'approve',
            };

            const afterRequest = {
                onSuccess: () => {
                    this.withdrawalBeingReviewed = false;
                    this.sending = false;
                    this.manualWithdraw = false;
                    this.manualWithdrawTxn = '';
                    this.$toast.open(this.$t ? this.$t('Withdrawal was moderated') : legacyText("Withdrawal was moderated"));
                },
                onError: () => {
                    this.sending = false;
                    this.$toast.error(this.$t ? this.$t('There are some form errors') : legacyText("There are some form errors"));
                }
            };

            try {
                if (this.isVirtualWithdrawal(withdrawal)) {
                    this.$toast.open(legacyText("虚拟账户提现将直接通过"));
                    this.$inertia.put(actionRoute, form, afterRequest);
                    return;
                }

                if (Number(withdrawal.network_id) === 17) {
                    const brcData = JSON.parse(withdrawal.raw);

                    if (typeof window.unisat === 'undefined') {
                        this.sending = false;
                        return this.$toast.error('Please install Unisat Wallet to sign this BRC20 withdrawal.');
                    }

                    await window.unisat.requestAccounts();

                    const tx = await window.unisat.sendInscription(
                        withdrawal.address,
                        brcData.inscriptionId
                    );

                    if (!tx) {
                        this.sending = false;
                        return;
                    }

                    form.manual = true;
                    form.txn = tx;

                    this.$inertia.put(actionRoute, form, afterRequest);
                    return;
                }

                const walletType = this.getWithdrawalWalletType(withdrawal);

                if (Number(withdrawal.network_id) === 0) {
                    this.$inertia.put(actionRoute, form, afterRequest);
                    return;
                }

                if (walletType === 'evm') {
                    this.$toast.open(legacyText("请在 Trust Wallet 中确认提现交易"));

                    const txHash = await this.sendEvmWithdrawal(withdrawal);

                    form.manual = true;
                    form.txn = txHash;

                    this.$inertia.put(actionRoute, form, afterRequest);
                    return;
                }

                if (walletType === 'tron') {
                    this.$toast.open(legacyText("请在 TronLink 中确认提现交易"));

                    const txHash = await this.sendTronWithdrawal(withdrawal);

                    form.manual = true;
                    form.txn = txHash;

                    this.$inertia.put(actionRoute, form, afterRequest);
                    return;
                }

                this.sending = false;
                return this.$toast.error(legacyText("当前网络没有配置自动提现逻辑"));

            } catch (e) {
                this.sending = false;

                let message = legacyText("钱包交易失败");

                if (e && e.message) {
                    message = e.message;
                }

                if (e && Number(e.code) === 4001) {
                    message = legacyText("用户取消了钱包确认");
                }

                console.error(e);

                if (
                    this.selectedWithdrawal &&
                    this.getWithdrawalWalletType(this.selectedWithdrawal) === 'tron' &&
                    (
                        message.indexOf('Unexpected end of JSON input') !== -1 ||
                        message.indexOf('没有获取到 tronWeb') !== -1 ||
                        message.indexOf('没有检测到 Trust Wallet') !== -1 ||
                        message.indexOf('Trust Wallet 已连接') !== -1 ||
                        message.indexOf('TronLink') !== -1
                    )
                ) {
                    this.openManualApproveModal(
                        this.selectedWithdrawal,
                        legacyText("TronLink 自动发起 TRON 交易失败。请在 TronLink 手动转账后，把交易哈希填入弹窗。")
                    );
                    return;
                }

                this.$toast.error(message);
            }
        },

        confirmAction() {
            if (this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            if (this.sending) {
                return;
            }

            this.sending = true;

            const afterRequest = {
                onSuccess: () => {
                    this.withdrawalBeingReviewed = false;
                    this.sending = false;
                    this.manualWithdraw = false;
                    this.manualWithdrawTxn = '';
                    this.$toast.open(legacyText("Withdrawal was moderated"));
                },
                onError: () => {
                    this.sending = false;
                    this.$toast.error(legacyText("There are some form errors"));
                }
            };

            const form = {
                action: this.selectedAction,
            };

            const actionRoute = this.route('admin.reports.withdrawals.moderate', this.selectedWithdrawal.id);

            if (this.selectedAction === "reject") {
                form.reason = this.rejectedReasonText || '';
                this.$inertia.put(actionRoute, form, afterRequest);
                return;
            }

            if (this.selectedAction === "approve") {
                if (!this.manualWithdraw) {
                    this.sending = false;
                    this.withdrawalBeingReviewed = false;
                    this.directApproveWithdrawal(this.selectedWithdrawal);
                    return;
                }

                const txn = String(this.manualWithdrawTxn || '').trim();

                if (!txn) {
                    this.sending = false;
                    this.$toast.error(legacyText("请输入交易哈希"));
                    return;
                }

                if (!/^[0-9a-fA-F]{64}$/.test(txn)) {
                    this.sending = false;
                    this.$toast.error(legacyText("交易哈希格式不正确，应为 64 位十六进制字符"));
                    return;
                }

                form.manual = true;
                form.txn = txn;

                this.$inertia.put(actionRoute, form, afterRequest);
                return;
            }

            this.sending = false;
        },

        showReason(document) {
            this.showReasonModal = true;
            this.rejectedReason = document.rejected_reason;
        },

        closeReasonModal() {
            this.showReasonModal = false;
        }
    },

    watch: {
        form: {
            handler: throttle(function() {
                this.getList()
            }, 300),
            deep: true,
        },
    },
})
</script>
