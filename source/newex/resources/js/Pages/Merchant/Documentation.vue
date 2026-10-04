<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Web/Pages/Merchant/Documentation.template'
import AppLayout from '@/Layouts/AppLayout'
import MerchantTopMenu from '@/Components/Merchant/TopMenu'

export default Template({
    components: {
        AppLayout,
        MerchantTopMenu
    },

    data() {
        return {
            activeSection: 'getting-started',
            sections: [
                { id: 'getting-started', label: legacyText("Getting Started") },
                { id: 'authentication', label: legacyText("Authentication") },
                { id: 'create-invoice', label: legacyText("Create Invoice") },
                { id: 'webhooks', label: legacyText("Webhooks") },
                { id: 'widget', label: legacyText("Payment Widget") },
                { id: 'testing', label: legacyText("Testing") }
            ]
        }
    },

    methods: {
        setActiveSection(sectionId) {
            this.activeSection = sectionId
            const element = document.getElementById(sectionId)
            if (element) {
                element.scrollIntoView({ behavior: 'smooth' })
            }
        },

        copyCode(code) {
            navigator.clipboard.writeText(code).then(() => {
                this.$toast.open(this.$t('Code copied'))
            })
        }
    }
})
</script>
