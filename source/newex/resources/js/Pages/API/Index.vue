<script>
    import ApiTokenManager from './ApiTokenManager'
    import AppLayout from '@/Layouts/AppLayout'
    import Template from '{Template}/Web/Pages/API/Index.template'

    export default Template({
        props: [
            'tokens',
            'availablePermissions',
            'defaultPermissions',
        ],
        components: {
            ApiTokenManager,
            AppLayout,
        },
        data() { return {tabPage: 'api_tokens'}; },
        methods: {
            setTabPage() {
                if (this.tabPage === 'api_tokens') return;
                if (this.tabPage === 'kyc') return this.$inertia.visit(this.route('user.kyc'));
                this.setPage(this.tabPage);
            },
            setPage(page, external = false) {
                if (page === 'email') {
                    page = 'info';
                }

                if(external) {
                    return this.$inertia.visit(this.route(page, {'lite': 1}));
                }

                this.$inertia.visit(this.route('profile.show', {'slug': page, 'lite': 1}));
            },
        }
    });
</script>
