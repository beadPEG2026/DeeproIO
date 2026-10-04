<script>
import Template from '{Template}/Admin/Pages/Admin/Merchant/Assets/Index.template'
import AppLayout from '@/Layouts/AdminLayout'

export default Template({
    components: { AppLayout },
    props: {
        assets: Array,
        poolStatus: Object
    },
    methods: {
        toggleAsset(asset) {
            this.$inertia.post(this.route('admin.merchant.assets.toggle', asset.id))
        }
    }
})
</script>
