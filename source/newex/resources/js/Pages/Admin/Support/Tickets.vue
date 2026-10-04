<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import SitePresentationSettings from '@/Components/Admin/SitePresentationSettings.vue';
import Template from '{Template}/Admin/Pages/Admin/Support/Tickets.template'
import AppLayout from '@/Layouts/AdminLayout'
import Pagination from '@/Jetstream/Pagination'
import Badge from '@/Jetstream/Badge'
import JetDialogModal from '@/Jetstream/DialogModal'
import JetSecondaryButton from '@/Jetstream/SecondaryButton'
import JetDangerButton from '@/Jetstream/DangerButton'

export default Template({
    components: {
        SitePresentationSettings,
        AppLayout,
        Pagination,
        Badge,
        JetDialogModal,
        JetSecondaryButton,
        JetDangerButton,
    },
    props: {
        tickets: Object,
        filters: Object,
        operators: Array,
    },
    data() {
        return {
            showReplyModal: false,
            selectedTicket: null,
            replyText: '',
            sending: false,
            filterForm: {...this.filters, overdue: this.filters?.overdue === true || String(this.filters?.overdue) === '1'},
            workflow: {},
            requestKey: null,
        }
    },
    methods: {
        applyFilters() {
            this.$inertia.get(this.route('admin.support.tickets'), {...this.filterForm, overdue: this.filterForm.overdue ? 1 : null})
        },
        openReply(ticket) {
            this.selectedTicket = ticket
            this.replyText = ''
            this.requestKey = crypto.randomUUID()
            this.workflow = {internal_note:false,status:ticket.status,priority:ticket.priority,assigned_to:ticket.assigned_to,due_at:ticket.due_at ? ticket.due_at.replace(' ','T').slice(0,16) : null,revision:ticket.revision}
            this.showReplyModal = true
        },
        closeReply() {
            this.showReplyModal = false
            this.selectedTicket = null
            this.replyText = ''
        },
        sendReply() {
            if (!this.selectedTicket || this.sending) return
            if (this.$page.props.mode == 'readonly') {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            let afterRequest = {
                onStart: () => this.sending = true,
                onFinish: () => this.sending = false,
                onSuccess: () => {
                    this.$toast.open(this.$t('Ticket saved. Email transport status is shown in history.'))
                    this.closeReply()
                },
                onError: () => this.$toast.error(legacyText("There are some form errors")),
                preserveScroll: true,
            }

            this.$inertia.post(this.route('admin.support.tickets.reply', this.selectedTicket.id), {
                reply: this.replyText,
                ...this.workflow,
                request_key: this.requestKey,
            }, afterRequest)
        }
    }
})
</script>
