<script>
import Template from '{Template}/Admin/Components/PeerTrade/AdminTopMenu.template'

export default Template({
    components: {

    },
    props: {
        section: String,
    },
    data() {
        return {

        }
    },
    methods: {
        setSection(section) {
            this.$inertia.visit(this.route('admin.' + section));
        },
    }
})

</script>
