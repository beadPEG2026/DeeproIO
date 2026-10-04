<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/KycDocuments/Index.template'
import AppLayout from '@/Layouts/AdminLayout'
import Welcome from '@/Jetstream/Welcome'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import Pagination from '@/Jetstream/Pagination'
import SearchFilter from '@/Jetstream/SearchFilter'
import NavLink from "@/Jetstream/NavLink";
import Badge from "@/Jetstream/Badge";
import JetDialogModal from '@/Jetstream/DialogModal'
import JetConfirmationModal from '@/Jetstream/ConfirmationModal'
import JetSecondaryButton from '@/Jetstream/SecondaryButton'
import JetDangerButton from '@/Jetstream/DangerButton'
import pickBy from "lodash/pickBy";
import throttle from "lodash/throttle";
import mapValues from "lodash/mapValues";
import {string_cut} from "@/Functions/String";

export default Template({
    components: {
        Badge,
        NavLink,
        NavButtonLink,
        AppLayout,
        Welcome,
        Pagination,
        SearchFilter,
        JetDialogModal,
        JetSecondaryButton,
        JetConfirmationModal,
        JetDangerButton,
    },

    props: {
        kycDocuments: Object,
        filters: Object,
    },

    data() {
        return {
            selectedAction: null,
            selectedDocument: null,
            documentBeingReviewed: false,
            images: [],
            sending: false,
            showImage: false,
            showReasonModal: false,
            rejectedReason: null,
            rejectedReasonText: "",
            selectedReferrerTitle: '',
            selectedReferrerChain: null,

            form: {
                search: this.filters.search,
                period: this.filters.period || [],
                role: this.filters.role || null,
                status: this.filters.status || null,
                kyc_status: this.filters.kyc_status || null,
                email_status: this.filters.email_status || null,
                login_ip: this.filters.login_ip || null,
                ip_location: this.filters.ip_location || null,
                duplicate_ip: this.filters.duplicate_ip || null,
                duplicate_account: this.filters.duplicate_account || null,
            },
        }
    },

    methods: {
        locationLabel(value) { const text = String(value || ''); const translated = legacyText(text); return translated !== text ? translated : text.split(/(\s*[/·]\s*)/).map(part => legacyText(part)).join(''); },
        format_string(string, limit) {
            return string_cut(string, limit);
        },

        showModal(images) {
            this.images = images;
            this.showImage = true;
        },

        closeModal() {
            this.showImage = false;
        },

        approve(document) {
            this.selectedDocument = document;
            this.selectedAction = 'approve';
            this.documentBeingReviewed = true;
        },

        reject(document) {
            this.selectedDocument = document;
            this.selectedAction = 'reject';
            this.documentBeingReviewed = true;
        },

        confirmAction() {
            if (this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            let afterRequest = {
                onStart: () => this.sending = true,
                onFinish: () => this.sending = false,
                onSuccess: () => {
                    this.documentBeingReviewed = false;
                    this.$toast.open('Document was moderated');
                },
                onError: () => {
                    this.$toast.error(legacyText("There are some form errors"));
                }
            };

            let form = {
                action: this.selectedAction
            };

            if (this.selectedAction == "reject") {
                if (this.rejectedReasonText.trim() == "") {
                    alert("Please fill the rejection reason field");
                    return false;
                }

                form.reason = this.rejectedReasonText;
            }

            this.$inertia.put(this.route('admin.kyc.moderate', this.selectedDocument.id), form, afterRequest);
        },

        showReason(document) {
            this.showReasonModal = true;
            this.rejectedReason = document.rejected_reason;
        },

        closeReasonModal() {
            this.showReasonModal = false;
        },

        reset() {
            this.form = mapValues(this.form, () => null)
            this.form.period = []
        },

        getList() {
            let query = pickBy(this.form, value => {
                if (Array.isArray(value)) {
                    return value.length > 0;
                }

                return value !== null && value !== undefined && value !== '';
            })

            if (this.form.period && this.form.period.length === 2) {
                query['period[0]'] = this.form.period[0];
                query['period[1]'] = this.form.period[1];
                delete query.period;
            }

            this.$inertia.replace(this.route('admin.kyc.documents', Object.keys(query).length ? query : { remember: 'forget' }));
        },

        filterDuplicateIp() {
            this.form.duplicate_ip = 1;
            this.form.duplicate_account = null;
        },

        filterDuplicateAccount() {
            this.form.duplicate_account = 1;
            this.form.duplicate_ip = null;
        },

        formatDocumentNumber(document) {
            if (!document) {
                return '-';
            }

            if (document.document_number) {
                return document.document_number;
            }

            if (document.id_number) {
                return document.id_number;
            }

            if (document.identity_number) {
                return document.identity_number;
            }

            return '-';
        },

        getReferrerChain(document) {
            return document &&
                document.user &&
                Array.isArray(document.user.referrer_chain)
                ? document.user.referrer_chain
                : [];
        },

        getReferrerDisplay(document) {
            if (document && document.user && document.user.referrer_display_name) {
                return document.user.referrer_display_name;
            }

            return 'N/A';
        },

        showReferrerChain(document) {
            const userId = document && document.user ? (document.user.referral_code || document.user.id) : '';

            this.selectedReferrerTitle = legacyText("用户 {value0} 的所有上级", {value0: userId});
            this.selectedReferrerChain = this.getReferrerChain(document);
        },

        closeReferrerChain() {
            this.selectedReferrerTitle = '';
            this.selectedReferrerChain = null;
        },

        referrerPrimaryName(item) {
            if (!item) {
                return '-';
            }

            return (item.nickname && item.nickname !== '-')
                ? item.nickname
                : (item.account || item.name || `ID: ${item.id}`);
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
