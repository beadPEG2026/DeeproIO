<script>
import Template from '{Template}/Admin/Layout/App.template'
import JetApplicationMark from '@/Jetstream/ApplicationMark'
import JetBanner from '@/Jetstream/Banner'
import JetDropdown from '@/Jetstream/Dropdown'
import JetDropdownLink from '@/Jetstream/DropdownLink'
import JetNavLink from '@/Jetstream/NavLink'
import JetResponsiveNavLink from '@/Jetstream/ResponsiveNavLink'
import NavLink from "@/Jetstream/NavLink";
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import ThemeMode from '@/Components/ThemeMode';
import Socket from "@/Jetstream/Socket";

export default Template({
    components: {
        Socket,
        ThemeMode,
        LanguageSwitcher,
        NavLink,
        JetApplicationMark,
        JetBanner,
        JetDropdown,
        JetDropdownLink,
        JetNavLink,
        JetResponsiveNavLink,
    },

    watch: {'$page.url'() { this.showingNavigationDropdown = false; }},
    computed: {
        adminPageTitle() {
            const path=(this.$page.url||'').split('?')[0].replace(/^\/exchange-control-panel\/?/,'');
            const names={
                'reports/staking-transactions':'Staking transactions','reports/auto-invest-orders':'Auto-invest orders',
                'reports/fiat-deposits':'Fiat deposits','reports/fiat-withdrawals':'Fiat withdrawals','reports/fee-refunds':'Fee refunds',
                'reports/funding-fee-distributions':'Funding fee distributions','reports/referral-transactions':'Referral transactions',
                'reports/launchpad-transactions':'Launchpad transactions','reports/bonuses':'Bonuses','reports/wallet-adjustments':'Wallet adjustments',
                'reports/lending-transactions':'Lending transactions','reports/wallets/system':'System wallets','reports/wallets':'Wallets',
                'reports/deposits':'Deposits','reports/withdrawals':'Withdrawals','reports/wallet-balance-logs':'Wallet balance logs',
                'reports/transfer-commissions':'Transfer commissions','reports/finances':'Finance reports','reports/futures':'Futures trades',
                'reports/options':'Options','reports/trades':'Trades','reports/stakings':'Staking transactions','reports/compliance':'Compliance',
                'operations/assets':'Asset workbench','operations/users':'Users','operations/trace':'Transaction trace','operations/incidents':'Incidents',
                'users':'Users','kyc-documents':'KYC Documents','bank-accounts':'Bank Accounts','markets':'Markets','currencies':'Currencies',
                'networks':'Networks','liquidity':'Liquidity','articles':'Articles','pages':'Pages','languages':'Languages',
                'support-tickets':'Support tickets','settings':'Settings','cold-storage':'Cold Storage','custody':'Custody','wallet-recovery':'Wallet recovery',
                'stakings':'Staking','launchpads':'Launchpad','copy-trading':'Copy trading','vouchers':'Vouchers','verification-codes':'Verification codes',
                'stock-tokens':'Stock Tokens','staking-quantify':'Quantitative Trading','staking':'Staking','lending':'Lending',
                'system-monitor':'System monitor','options-templates':'Options templates','guess-games':'Guess games',
                'team/dashboard':'Team dashboard','team/users':'Team users','team/positions':'Team positions','team/orders':'Team orders',
                'team/trades':'Team trades','team/deposits':'Team deposits','team/withdrawals':'Team withdrawals','team/commissions':'Team commissions','team/reports':'Team reports',
                'merchant-acquiring':'Merchant acquiring','peer-merchant-documents':'Merchant documents','peer-trades-payment-methods':'Payment methods',
                'peer-trades-dashboard':'P2P dashboard','peer-trades-transactions':'P2P transactions','peer-trades-appeals':'P2P appeals','peer-trades-chat':'P2P chat',
                'unlimit/config':'Payment settings','reports':'Reports','umi':'UMI','dashboard':'Dashboard'
            };
            const key=Object.keys(names).sort((a,b)=>b.length-a.length).find(k=>path===k||path.startsWith(k+'/'));
            const label=key?this.$t(names[key]):this.$t('Dashboard');
            const record=path.match(/\/(\d+)(?:\/|$)/)?.[1];
            const action=path.endsWith('/create')?' · '+this.$t('Create'):path.endsWith('/edit')?' · '+this.$t('Edit'):'';
            return label+(record?' · #'+record:'')+action+' · Deepro';
        },
        logo() {
            return this.$page.props.siteLogo || '/images/deepro-logo.svg'
        },

        roles() {
            return (this.$page && this.$page.props && this.$page.props.user && this.$page.props.user.roles)
                ? this.$page.props.user.roles
                : []
        },

        isSuperAdmin() {
            return this.roles.includes('superadmin')
        },

        isSalesman() {
            return this.roles.includes('salesman')
        },

        /**
         * 财务菜单权限：
         * 1. 除“开仓中订单”外，财务里面其他菜单只允许超级管理员查看。
         * 2. 不再给 admin / finance_manager / user_leader / perm_finances 放开财务菜单。
         */
        canSeeFullFinance() {
            return this.isSuperAdmin
        },

        canSeeFeeRefunds() {
            return this.roles.some(role => [
                'superadmin',
                'admin',
                'finance_manager',
                'user_leader',
                'salesman',
                'perm_finances',
            ].includes(role))
        },

        /**
         * 开仓中订单：按后端路由授权显示，UMI 独立运营账号不继承。
         */
        canSeeOpenFutures() {
            return this.canVisitAdmin('admin.reports.futures.active')
        },

        /**
         * 量化订单 / 质押订单：与后端路由授权一致。
         */
        canSeeFinanceOrderReports() {
            return this.canVisitAdmin('admin.reports.staking-transactions') || this.canVisitAdmin('admin.reports.auto-invest-orders')
        },

        pendingReviews() {
            return this.$page.props.admin_pending_reviews || {
                kyc_pending_count: 0,
                bank_pending_count: 0,
                total: 0,
            }
        },

        hasPendingReviews() {
            return Number(this.pendingReviews.total || 0) > 0
        }
    },

    data() {
        return {
            open: false,
            showingNavigationDropdown: false,
            menuOpen: {
                trading: true,
                member: false,
                finance: false,
                financeReports: false,
                other: false,
                setting: false,
            },
            pendingReviewSoundPlayed: false,
        }
    },

    created() {
        if (
            this.isUrl('markets') ||
            this.isUrl('currencies') ||
            this.isUrl('networks') ||
            this.isUrl('bank-accounts') ||
            this.isUrl('peer-trades') ||
            this.isUrl('liquidity') ||
            this.isUrl('launchpads') ||
            this.isUrl('stakings') ||
            this.isUrl('copy-trading') ||
            this.isUrl('merchant-acquiring') ||
            this.isUrl('lending')
        ) {
            this.menuOpen.trading = true
        }

        if (
            this.isUrl('users') ||
            this.isUrl('verification-codes') ||
            this.isUrl('kyc-documents') ||
            this.isUrl('vouchers')
        ) {
            this.menuOpen.member = true
        }

        if (
            this.isUrl('reports') && this.isDepositWithdrawalReportUrl()
        ) {
            this.menuOpen.finance = true
        }

        if (
            this.isUrl('reports') && !this.isDepositWithdrawalReportUrl()
        ) {
            this.menuOpen.financeReports = true
        }

        if (
            this.isUrl('pages') ||
            this.isUrl('articles') ||
            this.isUrl('languages') ||
            this.isUrl('options-templates') ||
            this.isUrl('support') ||
            this.isUrl('cold-storage')
        ) {
            this.menuOpen.other = true
        }

        if (
            this.isUrl('settings')
        ) {
            this.menuOpen.setting = true
        }
    },

    updated() {

    },

    mounted() {
        if (this.hasPendingReviews) {
            this.playPendingReviewSound();
        }
    },

    methods: {
        canVisitAdmin(name) { return (this.$page.props.adminAllowedRoutes || []).includes(name); },
        logout() {
            this.$inertia.post(this.route('logout'));
        },

        isUrl(urls) {
            let currentUrl = this.$page.url.substr(1).split('/');

            if (urls === 'dashboard' && !currentUrl[1]) {
                return true;
            }

            if (currentUrl[1]) {
                return currentUrl[1].startsWith(urls)
            }

            return false;
        },

        isDepositWithdrawalReportUrl() {
            const url = this.$page && this.$page.url ? this.$page.url : '';

            return url.includes('/reports/deposits') ||
                url.includes('/reports/withdrawals') ||
                url.includes('/reports/fiat-deposits') ||
                url.includes('/reports/fiat-withdrawals');
        },

        isPathActive(path) {
            const url = this.$page && this.$page.url ? this.$page.url : '';

            return url.includes(path);
        },

        toggleMenu(key) {
            this.menuOpen[key] = !this.menuOpen[key];
        },

        playPendingReviewSound() {
            if (this.pendingReviewSoundPlayed) {
                return;
            }

            this.pendingReviewSoundPlayed = true;

            try {
                const AudioContext = window.AudioContext || window.webkitAudioContext;

                if (!AudioContext) {
                    return;
                }

                const context = new AudioContext();
                const oscillator = context.createOscillator();
                const gain = context.createGain();

                oscillator.type = 'sine';
                oscillator.frequency.value = 880;
                gain.gain.value = 0.08;

                oscillator.connect(gain);
                gain.connect(context.destination);
                oscillator.start();

                setTimeout(() => {
                    oscillator.stop();
                    context.close();
                }, 220);
            } catch (e) {

            }
        },
    }
})
</script>
