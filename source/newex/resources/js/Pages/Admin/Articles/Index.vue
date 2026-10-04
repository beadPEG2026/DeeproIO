<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/Articles/Index.template'
import AppLayout from '@/Layouts/AdminLayout'
import Welcome from '@/Jetstream/Welcome'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import Pagination from '@/Jetstream/Pagination'
import SearchFilter from '@/Jetstream/SearchFilter'
import pickBy from 'lodash/pickBy'
import throttle from 'lodash/throttle'
import mapValues from 'lodash/mapValues'
import NavLink from "@/Jetstream/NavLink";
import Badge from "@/Jetstream/Badge";

export default Template({
    components: {
        Badge,
        NavLink,
        NavButtonLink,
        AppLayout,
        Welcome,
        Pagination,
        SearchFilter
    },

    props: {
        articles: Object,
        filters: Object,
    },

    data() {
        return {
            sending: false,
            form: {
                search: this.filters.search,
                trashed: this.filters.trashed,
            },
        }
    },

    watch: {
        form: {
            handler: throttle(function() {
                this.getList()
            }, 150),
            deep: true,
        },
    },

    methods: {
        reset() {
            this.form = mapValues(this.form, () => null)
        },

        getList() {
            let query = pickBy(this.form)

            this.$inertia.replace(
                this.route(
                    'admin.articles',
                    Object.keys(query).length ? query : { remember: 'forget' }
                )
            )
        },

        formatDate(value) {
            if (!value) {
                return '-'
            }

            if (typeof value === 'string') {
                return value.replace('T', ' ').replace(/\.\d+Z$/, '').slice(0, 19)
            }

            try {
                const date = new Date(value)

                if (Number.isNaN(date.getTime())) {
                    return '-'
                }

                const year = date.getFullYear()
                const month = String(date.getMonth() + 1).padStart(2, '0')
                const day = String(date.getDate()).padStart(2, '0')
                const hour = String(date.getHours()).padStart(2, '0')
                const minute = String(date.getMinutes()).padStart(2, '0')
                const second = String(date.getSeconds()).padStart(2, '0')

                return `${year}-${month}-${day} ${hour}:${minute}:${second}`
            } catch (e) {
                return '-'
            }
        },

        visibilityLabel(article) {
            if (article.visibility_referral_user_id) {
                const country = article.visibility_country === 'ES'
                    ? legacyText(" + 西班牙（ES）")
                    : '';

                return legacyText("邀请链（根用户 UID ") + article.visibility_referral_user_id + '）' + country;
            }

            return article.visibility_country === 'ES' ? legacyText("西班牙（ES）") : legacyText("所有国家");
        },

        popupExcludedCountries(article) {
            const value = article.homepage_popup_excluded_countries;

            if (!value) {
                return '';
            }

            if (Array.isArray(value)) {
                return value.join(',');
            }

            return String(value);
        },
    },
})
</script>
