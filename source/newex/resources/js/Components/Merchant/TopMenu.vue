<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Web/Components/Merchant/TopMenu.template'

export default Template({
    props: {
        label: {
            type: String,
            default: ''
        }
    },

    data() {
        return {
            menuItems: [
                { route: 'merchant.dashboard', label: legacyText("Dashboard"), icon: 'home' },
                { route: 'merchant.invoices', label: legacyText("Invoices"), icon: 'document' },
                { route: 'merchant.earnings', label: legacyText("Earnings"), icon: 'chart' },
                { route: 'merchant.deposits', label: legacyText("Deposits"), icon: 'bell' },
                { route: 'merchant.payouts', label: legacyText("Payouts"), icon: 'cash' },
                { route: 'merchant.webhooks', label: legacyText("Webhooks"), icon: 'bell' },
                { route: 'merchant.settings', label: legacyText("Settings"), icon: 'cog' },
                { route: 'merchant.docs', label: 'API Docs', icon: 'book' }
            ]
        }
    },

    methods: {
        isActive(routeName) {
            // Check if current route matches

            const currentRoute = this.$page.url
            try {
                const targetUrl = '/' + (routeName.replace('.', '/'));

                // Check exact match or if it starts with the target URL
                return currentRoute === targetUrl || currentRoute.startsWith(targetUrl.replace(/\/$/, '') + '/')
            } catch (e) {
                return false
            }
        }
    }
})
</script>
