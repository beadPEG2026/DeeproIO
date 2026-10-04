<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/Articles/Form.template'
import AppLayout from '@/Layouts/AdminLayout'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import TextInput from '@/Jetstream/TextInput'
import TextareaInput from "@/Jetstream/TextareaInput";
import LoadingButton from "@/Jetstream/LoadingButton";
import TrashedMessage from "@/Jetstream/TrashedMessage";
import SelectInput from "@/Jetstream/SelectInput";
import EmptyColumn from "@/Jetstream/EmptyColumn";
import { quillEditor } from 'vue-quill-editor'

const multilingualFields = {
    'title_zh-tw': null,
    'body_zh-tw': null,

    title_ja: null,
    body_ja: null,

    title_cs: null,
    body_cs: null,

    title_de: null,
    body_de: null,

    title_es: null,
    body_es: null,

    title_fr: null,
    body_fr: null,

    title_nl: null,
    body_nl: null,

    title_pt: null,
    body_pt: null,

    title_ro: null,
    body_ro: null,

    title_it: null,
    body_it: null,
};

const defaultForm = {
    title: null,
    slug: null,
    body: null,
    status: true,
    featured: false,
    language: null,
    category_id: null,
    visibility_country: null,
    visibility_referral_user_id: null,
    homepage_popup_enabled: false,
    homepage_popup_excluded_countries: null,
    file_id: null,
    ...multilingualFields,
};

export default Template({
    components: {
        NavButtonLink,
        AppLayout,
        TextInput,
        TextareaInput,
        LoadingButton,
        TrashedMessage,
        SelectInput,
        EmptyColumn,
        quillEditor
    },

    props: {
        isEdit: {
            type: Boolean,
            default: false,
        },
        errors: Object,
        article: Object,
        categories: Array,
        languages: Object,
    },

    remember: 'form',

    data() {
        return {
            sending: false,
            form: Object.assign({}, defaultForm),
            articleLogo: null,
            uploaded: false,
            activeLanguageSlug: 'zh-tw',
            uploadUrl: this.route('file-upload'),
            uploadHeaders: {
                'X-XSRF-TOKEN': $cookies.get('XSRF-TOKEN')
            },
            articleLanguages: [
                {
                    slug: 'zh-tw',
                    name: legacyText("繁體中文")
                },
                {
                    slug: 'ja',
                    name: legacyText("日本語")
                },
                {
                    slug: 'cs',
                    name: 'Čeština'
                },
                {
                    slug: 'de',
                    name: 'Deutsch'
                },
                {
                    slug: 'es',
                    name: 'Español'
                },
                {
                    slug: 'fr',
                    name: 'Français'
                },
                {
                    slug: 'nl',
                    name: 'Nederlands'
                },
                {
                    slug: 'pt',
                    name: 'Português'
                },
                {
                    slug: 'ro',
                    name: 'Română'
                },
                {
                    slug: 'it',
                    name: 'Italiano'
                },
            ],
        }
    },

    mounted() {
        if (this.isEdit) {
            this.form = Object.assign({}, defaultForm, this.article);

            if (this.form.file) {
                this.articleLogo = {
                    name: this.form.file.name,
                    type: this.form.file.type,
                    url: this.form.file.url
                }
            }
        } else {
            // Add newly introduced fields when Inertia restores an older form.
            this.form = Object.assign({}, defaultForm, this.form);
        }
    },

    computed: {
        subTitle: function () {
            return this.isEdit ? this.article.title : legacyText("Create");
        },

        actionButtonTitle: function () {
            return this.isEdit ? 'Update Article' : 'Create Article';
        },

        activeLanguage() {
            return this.articleLanguages.find((lang) => lang.slug === this.activeLanguageSlug) || this.articleLanguages[0];
        },
    },

    methods: {
        setActiveLanguage(slug) {
            this.activeLanguageSlug = slug;
        },

        getLangTitleField(slug) {
            return slug === 'zh-tw' ? 'title_zh-tw' : 'title_' + slug;
        },

        getLangBodyField(slug) {
            return slug === 'zh-tw' ? 'body_zh-tw' : 'body_' + slug;
        },

        getError(field) {
            if (!this.errors) {
                return null;
            }

            return this.errors[field] || null;
        },

        copyDefaultToActiveLanguage() {
            const titleField = this.getLangTitleField(this.activeLanguage.slug);
            const bodyField = this.getLangBodyField(this.activeLanguage.slug);

            this.form[titleField] = this.form.title;
            this.form[bodyField] = this.form.body;

            if (this.$toast) {
                this.$toast.open(legacyText("已复制默认标题和正文"));
            }
        },

        buildPayload() {
            const payload = {
                title: this.form.title,
                slug: this.form.slug,
                body: this.form.body,
                status: this.form.status,
                featured: this.form.featured,
                language: this.form.language,
                category_id: this.form.category_id,
                visibility_country: this.form.visibility_country,
                visibility_referral_user_id: this.form.visibility_referral_user_id,
                homepage_popup_enabled: this.form.homepage_popup_enabled,
                homepage_popup_excluded_countries: this.form.homepage_popup_excluded_countries,
                file_id: this.form.file_id,
            };

            Object.keys(multilingualFields).forEach((field) => {
                payload[field] = this.form[field];
            });

            return payload;
        },

        submit() {
            if (this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            let afterRequest = {
                onStart: () => this.sending = true,
                onFinish: () => this.sending = false,
                onSuccess: () => {
                    this.$toast.open('Database updated');
                },
                onError: () => {
                    this.$toast.error(legacyText("There are some form errors"));
                }
            };

            const payload = this.buildPayload();

            if (this.isEdit) {
                this.$inertia.put(this.route('admin.articles.update', this.article.id), payload, afterRequest);
            } else {
                this.$inertia.post(this.route('admin.articles.store'), payload, afterRequest);
            }
        },

        destroy() {
            if (this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            if (confirm('Are you sure you want to delete this article?')) {
                this.$inertia.delete(this.route('admin.articles.destroy', this.article.id), {
                    onSuccess: () => {
                        this.$toast.open('Article was deleted');
                    }
                })
            }
        },

        restore() {
            if (this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            if (confirm('Are you sure you want to restore this article?')) {
                this.$inertia.put(this.route('admin.articles.restore', this.article.id))
            }
        },

        removePic: function () {
            let articleLogo = this.articleLogo;

            this.$refs.fileUploadForm.deleteUpload(this.uploadUrl, this.uploadHeaders, [articleLogo], {
                uuid: this.form.file_id,
            });

            this.articleLogo = null;
            this.form.file_id = null;
            this.uploaded = false;
        },

        upload: function () {
            let self = this;

            this.$refs.fileUploadForm.upload(this.uploadUrl, this.uploadHeaders, [this.articleLogo]).then(function () {
                self.uploaded = true;

                setTimeout(function () {
                    // self.articleLogo.progress(0);
                }, 500);
            });
        },

        onSelect: function (fileRecords) {
            this.upload();
            this.uploaded = false;
        },

        onUpload: function (responses) {
            let response = responses[0];

            if (!response.error) {
                this.form.file_id = response.data.uuid;
            }
        },

        generateSlug() {
            this.form.slug = this.slugify(this.form.title || '');
        },

        slugify(text, ampersand = 'and') {
            const a = 'àáäâèéëêìíïîòóöôùúüûñçßÿỳýœæŕśńṕẃǵǹḿǘẍźḧ'
            const b = 'aaaaeeeeiiiioooouuuuncsyyyoarsnpwgnmuxzh'
            const p = new RegExp(a.split('').join('|'), 'g')

            return text.toString().toLowerCase()
                .replace(/[\s_]+/g, '-')
                .replace(p, c =>
                    b.charAt(a.indexOf(c)))
                .replace(/&/g, `-${ampersand}-`)
                .replace(/[^\w-]+/g, '')
                .replace(/--+/g, '-')
                .replace(/^-+|-+$/g, '')
        }
    },

    watch: {

    }
});
</script>
