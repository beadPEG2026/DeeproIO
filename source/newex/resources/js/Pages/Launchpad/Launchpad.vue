<script>
import RiskDialog from "@/Mixins/RiskDialog";
import Template from '{Template}/Web/Pages/Launchpad/Launchpad.template'
import AppLayout from '@/Layouts/AppLayout'
import IconFilter from "@/Components/Table/IconFilter";

export default Template({
    mixins: [RiskDialog],
    components: {
        AppLayout,
        IconFilter,
    },
    data() {
        return {
            state: false,
            sending: false,

            // 风险提示弹框
            showRiskModal: false,

            form: {
                id: null,
                amount: '',
            },
        }
    },
    props: {
        launchpad: Object,
    },
    methods: {
        submit() {

            if(this.sending) return;

            this.sending = true;

            this.form.id = this.launchpad.id;

            axios.post(this.route('launchpad.submit'), this.form).then((response) => {
                this.sending = false;
                this.$inertia.visit(this.route('reports.launchpad-transactions'));
            }).catch(error => {
                this.sending = false;
                _.each(error.response.data.errors, (field, key) => {
                    if(field[0] !== "") {
                        this.$toast.error(field[0]);
                    }
                });
            });
        },
        login() {
            this.$inertia.visit(this.route('login'));
        },
        handleInput ($event) {

            let keyCode = ($event.keyCode ? $event.keyCode : $event.which);

            if ((keyCode < 48 || keyCode > 57) && (keyCode !== 46 || this.form.amount.toString().indexOf('.') != -1)) {
                $event.preventDefault();
            }

            let precision = 8;

            if(this.form.amount != null && this.form.amount.toString().indexOf(".")>-1 && (this.form.amount.toString().split('.')[1].length >= precision)){
                $event.preventDefault();
            }
        },
        clearInput ($event) {
            let field = this.form.amount.toString();

            if(field.charAt(0) == '.') {
                this.form.amount = 0;
                return;
            }

            if (/^0+\.\d+/.test( field )) {
                this.form.amount = field.replace(/^0+/, '0');
            }

            if (/^0+\d+/.test( field )) {
                this.form.amount = field.replace(/^0+/, '');
            }
        },
    }
})
</script>