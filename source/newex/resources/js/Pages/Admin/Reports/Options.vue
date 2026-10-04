<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/Reports/Options.template'
import AppLayout from '@/Layouts/AdminLayout'
import Welcome from '@/Jetstream/Welcome'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import Pagination from '@/Jetstream/Pagination'
import NavLink from "@/Jetstream/NavLink";
import Badge from "@/Jetstream/Badge";
import SearchFilter from '@/Jetstream/SearchFilter'
import pickBy from 'lodash/pickBy'
import throttle from 'lodash/throttle'
import mapValues from 'lodash/mapValues'
import {string_cut} from "@/Functions/String";
import AdminReportsTab from "@/Components/Reports/AdminReportsTab";

export default Template({
    components: {
        Badge,
        NavLink,
        NavButtonLink,
        AppLayout,
        Welcome,
        Pagination,
        SearchFilter,
        AdminReportsTab
    },
    props: {
        transactions: Object,
        filters: Object,
        settlementReviews: {type:Array,default:()=>[]},
    },
    data() {
        return {
            sending: false,
            activeReview:null,reviewAction:null,reviewNote:'',reviewError:'',
            form: {
                search: this.filters.search,
                type: this.filters.type,
                period: this.filters.period || null,
            },
        }
    },
    computed:{
        canReview(){return (this.$page.props.user?.roles||[]).includes('superadmin')},
        zh(){return String(this.$i18n.locale).startsWith('zh')}
    },
    methods: {
        openReview(review,action){this.activeReview=review;this.reviewAction=action;this.reviewNote='';this.reviewError='';this.$nextTick(()=>this.$refs.reviewDialog.showModal())},
        closeReview(){if(this.sending)return;this.$refs.reviewDialog.close();this.activeReview=null},
        async submitReview(){
            if(this.sending||!this.activeReview)return;
            if(this.reviewNote.trim().length<8){this.reviewError=this.zh?'请填写至少 8 个字的复核说明。':'Enter a review note of at least 8 characters.';return}
            this.sending=true;this.reviewError='';
            try{
                await axios.post(this.route('admin.reports.options.close'),{id:this.activeReview.option_id,review_action:this.reviewAction,note:this.reviewNote.trim()},{timeout:20000});
                this.$refs.reviewDialog.close();this.activeReview=null;
                this.$toast.success(this.zh?'复核操作已记录。':'Review action recorded.');
                this.$inertia.reload({only:['transactions','settlementReviews'],preserveScroll:true});
            }catch(error){this.reviewError=Object.values(error.response?.data?.errors||{}).flat().join(' ')||error.response?.data?.message||(this.zh?'结果尚未确认，请刷新列表后核对，勿重复批准。':'Outcome is not confirmed. Refresh the list and check before approving again.');}
            finally{this.sending=false}
        },
        format_string(string, limit) {
            return string_cut(string, limit);
        },
        doCopy(string) {
            this.$copyText(string).then(() => {
                this.$toast.open(legacyText("Text was copied to the clipboard"));
            }, function (e) {

            })
        },
        reset() {
            this.form = mapValues(this.form, () => null)
        },
        getList() {
            let query = pickBy(this.form)
            this.$inertia.replace(this.route('admin.reports.options', Object.keys(query).length ? query : { remember: 'forget' }))
        },
        exportCsv() {
            let query = pickBy(this.form)
            if (this.filters && this.filters.referrer) {
                query.referrer = this.filters.referrer
            }
            const url = this.route('admin.reports.options.export', Object.keys(query).length ? query : {})
            window.location.href = url
        },
        closePosition(transaction) {

            if(this.sending) return;

            if (!confirm('Are you sure you want to close this position?')) {
                return;
            }

            this.sending = true;

            axios.post(this.route('admin.reports.options.close'), { id: transaction.id }).then((response) => {

                this.$toast.open("Position is closed");

                transaction.status = 'closed';

                this.sending = false;
            }).catch(error => {
                this.sending = false;
                this.$toast.error(error.response?.data?.message || (this.zh?'平仓结果尚未确认，请刷新后核对。':'Close outcome is not confirmed. Refresh and check the position.'));
            });
        },
    },
    watch: {
        form: {
            handler: throttle(function() {
                this.getList()
            }, 150),
            deep: true,
        },
    },
})
</script>

<style>
.dp-option-reviews{margin:20px 0;padding:20px;border:1px solid #d5a52f;border-radius:10px}.dp-option-reviews h2,.dp-option-review-dialog h2{font-size:20px;font-weight:700;margin-bottom:12px}.dp-option-reviews p,.dp-option-review-dialog p{line-height:1.7;font-size:14px}.dp-option-reviews table{width:100%;min-width:650px}.dp-option-reviews th,.dp-option-reviews td{text-align:left;padding:12px 8px;border-bottom:1px solid #ddd}.dp-option-reviews small{display:block;max-width:350px;overflow-wrap:anywhere}.dp-option-reviews button,.dp-option-review-dialog button{min-height:40px;padding:8px 14px;border:1px solid #bbb;border-radius:6px}.dp-option-review-dialog{width:min(540px,calc(100vw - 32px));padding:24px;border:0;border-radius:12px;max-height:calc(100vh - 40px);overflow:auto}.dp-option-review-dialog::backdrop{background:#0008}.dp-option-review-dialog label,.dp-option-review-dialog textarea{display:block;width:100%;margin:12px 0}.dp-option-review-dialog textarea{min-height:100px;border:1px solid #aaa;border-radius:6px;padding:10px}.dp-option-review-dialog form>div{display:flex;justify-content:flex-end;gap:12px}.dp-option-review-dialog [role=alert]{color:#b91c1c}
</style>
