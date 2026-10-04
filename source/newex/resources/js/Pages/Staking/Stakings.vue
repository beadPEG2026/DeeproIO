<script>
import {annualizedRate, firstStakingPeriod} from '@/Functions/UserDisplay.mjs';
import Template from '{Template}/Web/Pages/Staking/Stakings.template'
import AppLayout from '@/Layouts/AppLayout'
import IconFilter from "@/Components/Table/IconFilter";

export default Template({
    components: {
        AppLayout,
        IconFilter
    },
    data() {
        return {
            sending: false,
            filter: {
                sortBy: null,
            },
        }
    },
    props: {
        stakings: Object,
        sort: String
    },
    methods: {
        firstStakingPeriod,
        openStakingCard(event,staking,stakingType) {
            if(event.defaultPrevented || event.target?.closest('a,button,input,select,textarea') || String(window.getSelection?.() || '').trim())return;
            this.setStaking(staking,stakingType);
        },
        formatRewardEstimate(value){return value===null || value===undefined ? '—' : Number(value).toLocaleString(this.$i18n.locale,{minimumFractionDigits:2,maximumFractionDigits:2})},
        formatAmount(value, decimals = 4) {
            if (value === null || value === undefined || value === '') {
                return Number(0).toFixed(decimals);
            }

            let number = Number(value);

            if (isNaN(number)) {
                number = 0;
            }

            return number.toFixed(decimals);
        },

        getFirstAnnualizedRate(staking, field = null) {
            if (!staking) {
                return '0.00';
            }

            if (!field && firstStakingPeriod(staking)) return firstStakingPeriod(staking).annualized;

            if (!field && staking.annualized_reward !== undefined && staking.annualized_reward !== null && staking.annualized_reward !== '') {
                return this.formatPercent(staking.annualized_reward);
            }

            const days = this.getFirstDays(staking);
            const reward = field ? this.getFirstCommaValue(staking[field]) : this.getFirstReward(staking);

            return this.formatAnnualizedRate(reward, days);
        },

        getMyAnnualizedRate(staking) {
            if (!staking) {
                return '0.00';
            }

            if (staking.annualized_apy !== undefined && staking.annualized_apy !== null && staking.annualized_apy !== '') {
                return this.formatPercent(staking.annualized_apy);
            }

            return this.formatAnnualizedRate(staking.apy, staking.days);
        },

        getFirstDays(staking) {
            if (firstStakingPeriod(staking)) return firstStakingPeriod(staking).days;

            return this.getFirstCommaValue(staking.allowed_days);
        },

        getFirstReward(staking) {
            if (firstStakingPeriod(staking)) return firstStakingPeriod(staking).reward;

            return this.getFirstCommaValue(staking.rewards_percentage);
        },

        getFirstCommaValue(value) {
            if (value === null || value === undefined || value === '') {
                return 0;
            }

            if (Array.isArray(value)) {
                return value.length > 0 ? value[0] : 0;
            }

            if (typeof value === 'object') {
                const values = Object.values(value);
                return values.length > 0 ? values[0] : 0;
            }

            return value.toString().split(',')[0].trim();
        },

        formatAnnualizedRate(rate, days) {
            return annualizedRate(rate, days);
        },

        formatPercent(value) {
            const number = Number(value);

            if (isNaN(number)) {
                return '0.00';
            }

            return number.toFixed(2);
        },

        setStaking(staking, stakingType) {
            this.$inertia.visit(this.route('staking', staking.id) + '?staking_type=' + stakingType);
        },

        setSort(sort, stakingType) {
            this.$inertia.visit(this.route('stakings', {
                sort: sort,
                staking_type: stakingType
            }));
        },

        redeem(id) {
            if(this.sending) return;

            if (confirm(this.$t("Are you sure you want to redeem your staked coins in advance? You will not receive earned rewards in this case.")) == true) {

                this.sending = true;

                axios.post(this.route('staking.redeem'), { id: id}).then((response) => {
                    this.sending = false;
                    this.$toast.success('Your coins successfully have been redeemed');
                    this.$inertia.visit(this.route('stakings', {'sort': 'my'}));
                }).catch(error => {
                    this.sending = false;

                    if (error.response && error.response.data && error.response.data.errors) {
                        _.each(error.response.data.errors, (field, key) => {
                            if(field[0] !== "") {
                                this.$toast.error(field[0]);
                            }
                        });
                    }
                });

            }
        }
    }
})
</script>

<style>
.dp-wealth-page .staking-page__grid .staking-card{cursor:pointer}
.dp-wealth-page .staking-card__btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none}
.dp-wealth-page .staking-card__btn:focus-visible{outline:2px solid var(--ui-accent,#bc8b13);outline-offset:3px}
</style>
