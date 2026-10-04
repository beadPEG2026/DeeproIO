<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/Liquidity/Index.template'
import AppLayout from '@/Layouts/AdminLayout'
import Welcome from '@/Jetstream/Welcome'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import NavButton from "@/Jetstream/NavButton";
import NavButtonActive from "@/Jetstream/NavButtonActive";
import Pagination from '@/Jetstream/Pagination'
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
        NavButton,
        NavButtonActive
    },
    props: {
        markets: Object,
        services: Array,
        daemons: Object,
        debug: Boolean,
    },
    data() {
        return {
            sending: false,
        }
    },
    methods: {
        runLiquidity(model, isService = false) {

            if(this.sending) return;

            let form = {};

            if(isService) {
                this.sending = model.name;
                form = {
                    command: model.command,
                    service: model.name,
                };
            } else {
                this.sending = model.id;
                form = {
                    market: model.name,
                };
            }

            return axios.post(this.route('admin.liquidity.run'), form).then((response) => {

                this.$toast.open(response.data.status);

                if(isService) {
                    model.status = response.data.status;
                } else {
                    model.liq_enabled = response.data.liq;
                }

            }).catch(error => {
                const data = error && error.response && error.response.data;
                if (!isService && data && data.liq === false) {
                    model.liq_enabled = false;
                }
                const message = data && (data.message || (data.errors && data.errors.market && data.errors.market[0]));
                this.$toast.error(message || legacyText('Request failed'));
            }).finally(() => {
                this.sending = false;
            });
        },
        stopLiquidity(model, isService = false) {

            if(this.sending) return;

            this.sending = isService ? model.name : model.id;
            let form = {};

            if(isService) {
                form = {
                    command: model.command,
                    service: model.name,
                };
            } else {
                form = {
                    market: model.name,
                };
            }

            return axios.post(this.route('admin.liquidity.stop'), form).then((response) => {
                this.$toast.open(response.data.status);

                if(isService) {
                    model.status = false;
                } else {
                    model.liq_enabled = response.data.liq;
                }

            }).catch(error => {
                const data = error && error.response && error.response.data;
                if (!isService && data && data.liq === false) {
                    model.liq_enabled = false;
                }
                const message = data && (data.message || (data.errors && data.errors.market && data.errors.market[0]));
                this.$toast.error(message || legacyText('Request failed'));
            }).finally(() => {
                this.sending = false;
            });
        }
    }
})
</script>
