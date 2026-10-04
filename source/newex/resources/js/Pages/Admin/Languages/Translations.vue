<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/Languages/Translations.template'
import AppLayout from '@/Layouts/AdminLayout'
import Welcome from '@/Jetstream/Welcome'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import Pagination from '@/Jetstream/Pagination'
import NavLink from "@/Jetstream/NavLink";
import Badge from "@/Jetstream/Badge";
import ButtonLink from "@/Jetstream/ButtonLink";
import JetButton from "@/Jetstream/Button";
import JetDialogModal from "@/Jetstream/DialogModal";
import JetInput from "@/Jetstream/Input";
import JetInputError from "@/Jetstream/InputError";
import JetSecondaryButton from "@/Jetstream/SecondaryButton";
import mapValues from "lodash/mapValues";
import pickBy from "lodash/pickBy";
import throttle from "lodash/throttle";
import SearchFilter from '@/Jetstream/SearchFilter'
import {string_cut} from "@/Functions/String";

export default Template({
    components: {
        Badge,
        NavLink,
        NavButtonLink,
        AppLayout,
        Welcome,
        Pagination,
        JetDialogModal,
        JetInput,
        JetInputError,
        JetButton,
        JetSecondaryButton,
        SearchFilter,
        ButtonLink
    },
    props: {
        language: Object,
        translations: Object,
        filters: Object,

    },
    data() {
        return {
            syncing: false,
            form: this.$inertia.form({
                id: '',
                key: '',
                content: '',
            }),
            searchForm: {
                search: this.filters.search,
            },
            sending: false,
            isEdit: false,
            showModal: false,
        }
    },
    computed: {
        modalTitle: function () {
            return this.isEdit ? 'Edit translation string' : 'Add new translation string';
        },
        actionButtonTitle: function () {
            return this.isEdit ? 'Update' : legacyText("Create");
        },
    },
    methods: {
        toggleTranslation(translation) {

            this.form.reset();

            this.isEdit = translation ? true : false;

            if(this.isEdit) {
                this.form.id = translation.id;
                this.form.key = translation.key;
                this.form.content = translation.content;
            } else {
                delete this.form.id;
            }

            this.showModal = true;
        },
        submitTranslation() {

            let route = this.isEdit ?
                this.route('admin.language.translations.update', this.language.id)
                : this.route('admin.language.translations.store', this.language.id);

            this.form.put(route, {
                preserveScroll: true,
                onStart: () => this.sending = true,
                onFinish: () => this.sending = false,
                onSuccess: () => {
                    this.$toast.open('Database updated');
                    this.closeModal();
                    this.form.reset();
                },
                onError: () => {
                    this.$toast.error(legacyText("There are some form errors"));
                }
            });
        },
        destroyTranslation(translation) {

            if(this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            if (confirm('Are you sure you want to delete this translation?')) {

                let form = {
                    language : this.language.id,
                    translation : translation.id,
                    search: this.searchForm.search
                }

                this.$inertia.delete(this.route('admin.language.translations.destroy', form), {
                    onSuccess: () => { this.$toast.open('Translation String was deleted'); },
                    onError: () => {
                        this.$toast.error(legacyText("There are some form errors"));
                    }
                })
            }
        },
        closeModal() {
            this.showModal = false;
        },
        reset() {
            this.searchForm = mapValues(this.searchForm, () => null)
        },
        getList() {
            this.searchForm.language = this.language.id;
            let query = pickBy(this.searchForm)

            this.$inertia.replace(this.route('admin.language.translations', Object.keys(query).length ? query : { remember: 'forget' }))
        },
        format_string(string, limit) {
            return string_cut(string, limit);
        },
        syncLanguage() {

            if(this.syncing) return false;

            this.syncing = true;

            axios.post(this.route('admin.language.translations.sync', this.language.id)).then((response) => {
                this.syncing = false;
                this.$toast.open('Language Translations file was synced');
            }).catch(error => {
                this.syncing = false;
            });
        },
    },
    watch: {
        searchForm: {
            handler: throttle(function() {
                this.getList()
            }, 150),
            deep: true,
        },
    },
})
</script>
