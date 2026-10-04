<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/Reports/WalletBalanceLogs.template'
import AppLayout from '@/Layouts/AdminLayout'
import Pagination from '@/Jetstream/Pagination'
import SearchFilter from '@/Jetstream/SearchFilter'
import AdminReportsTab from '@/Components/Reports/AdminReportsTab'
import pickBy from 'lodash/pickBy'
import throttle from 'lodash/throttle'
import mapValues from 'lodash/mapValues'

export default Template({
    components: {
        AppLayout,
        Pagination,
        SearchFilter,
        AdminReportsTab,
    },
    props: {
        records: Object,
        filters: Object,
        accountFields: Array,
    },
    data() {
        return {
            form: {
                search: this.filters.search,
                user_id: this.filters.user_id || null,
                referral: this.filters.referral || null,
                period: this.filters.period || null,
                account_field: this.filters.account_field || null,
                change_type: this.filters.change_type || null,
                currency: this.filters.currency || null,
                per_page: this.filters.per_page || 50,
            },
            changeTypes: [
                { id: 'increase', name: legacyText("增加") },
                { id: 'decrease', name: legacyText("减少") },
            ],
        }
    },
    methods: {
        reset() {
            this.form = mapValues(this.form, () => null)
            this.form.per_page = 50
        },
        buildUrl(path, query) {
            const params = new URLSearchParams()

            Object.keys(query).forEach((key) => {
                const value = query[key]

                if (value === null || value === undefined || value === '') {
                    return
                }

                if (Array.isArray(value)) {
                    value.forEach((item, index) => {
                        if (item !== null && item !== undefined && item !== '') {
                            params.append(`${key}[${index}]`, item)
                        }
                    })
                    return
                }

                params.append(key, value)
            })

            const queryString = params.toString()

            return queryString ? `${path}?${queryString}` : path
        },
        getList() {
            let query = pickBy(this.form)

            if (this.filters && this.filters.referrer) {
                query.referrer = this.filters.referrer
            }

            this.$inertia.replace(this.buildUrl(
                '/exchange-control-panel/reports/wallet-balance-logs',
                Object.keys(query).length ? query : { remember: 'forget' },
            ))
        },
        exportCsv() {
            let query = pickBy(this.form)

            if (this.filters && this.filters.referrer) {
                query.referrer = this.filters.referrer
            }

            window.location.href = this.buildUrl('/exchange-control-panel/reports/wallet-balance-logs/export', query)
        },
        changeTypeLabel(type) {
            const found = this.changeTypes.find((item) => item.id === type)

            return found ? found.name : type
        },
        amountClass(value) {
            const numeric = parseFloat(value)

            if (numeric > 0) {
                return 'text-green-600'
            }

            if (numeric < 0) {
                return 'text-red-600'
            }

            return 'text-gray-700'
        },
        formatSignedAmount(value) {
            const numeric = parseFloat(value)
            const formatted = this.formatAmount(value)

            if (numeric > 0) {
                return `+${formatted}`
            }

            return formatted
        },
        formatAmount(value) {
            if (value === null || value === undefined || value === '') {
                return '0.0000'
            }

            const numeric = Number(value)

            if (!Number.isFinite(numeric)) {
                return value
            }

            return numeric.toFixed(4)
        },
        withCurrency(record, value) {
            const symbol = record.currency && record.currency.symbol ? ` ${record.currency.symbol}` : ''

            return `${value}${symbol}`
        },
    },
    watch: {
        form: {
            handler: throttle(function () {
                this.getList()
            }, 250),
            deep: true,
        },
    },
})
</script>
