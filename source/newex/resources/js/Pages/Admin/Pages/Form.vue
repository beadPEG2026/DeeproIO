<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/Pages/Form.template'
import AppLayout from '@/Layouts/AdminLayout'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import TextInput from '@/Jetstream/TextInput'
import TextareaInput from "@/Jetstream/TextareaInput";
import LoadingButton from "@/Jetstream/LoadingButton";
import TrashedMessage from "@/Jetstream/TrashedMessage";
import SelectInput from "@/Jetstream/SelectInput";
import EmptyColumn from "@/Jetstream/EmptyColumn";
import {quillEditor} from "vue-quill-editor";

const defaultForm = {
    'title_zh-cn': null, 'content_zh-cn': null, 'html_content_zh-cn': null,
    title: null,
    slug: null,
    content: null,
    html_content: null,
    is_html: false,

    title_ja: null,
    content_ja: null,
    html_content_ja: null,

    title_cs: null,
    content_cs: null,
    html_content_cs: null,

    title_de: null,
    content_de: null,
    html_content_de: null,

    title_es: null,
    content_es: null,
    html_content_es: null,

    title_fr: null,
    content_fr: null,
    html_content_fr: null,

    title_nl: null,
    content_nl: null,
    html_content_nl: null,

    title_pt: null,
    content_pt: null,
    html_content_pt: null,

    title_ro: null,
    content_ro: null,
    html_content_ro: null,

    title_it: null,
    content_it: null,
    html_content_it: null,

    title_zh_tw: null,
    content_zh_tw: null,
    html_content_zh_tw: null,

    seo_title: null,
    seo_description: null,
    seo_keywords: null,
    status: null,
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
        page: Object,
        publication: Object,
    },

    remember: 'form',

    data() {
        return {
            modules: null,
            preview: null, previewError: "", publishReason: "",
            sending: false,
            form: Object.assign({}, defaultForm),
        }
    },

    mounted() {
        if (this.isEdit) {
            this.form = Object.assign({}, defaultForm, this.normalizePageData(this.publication?.draft || this.page));
        }
    },

    computed: {
        subTitle: function () {
            return this.isEdit ? this.page.title : legacyText("Create");
        },

        actionButtonTitle: function () {
            return this.isEdit ? 'Update Page' : 'Create Page';
        },
    },

    methods: {
        normalizePageData(page) {
            let data = Object.assign({}, page || {});

            if (data['title_zh-tw'] !== undefined && data.title_zh_tw === undefined) {
                data.title_zh_tw = data['title_zh-tw'];
            }

            if (data['content_zh-tw'] !== undefined && data.content_zh_tw === undefined) {
                data.content_zh_tw = data['content_zh-tw'];
            }

            if (data['html_content_zh-tw'] !== undefined && data.html_content_zh_tw === undefined) {
                data.html_content_zh_tw = data['html_content_zh-tw'];
            }

            return data;
        },

normalizeSubmitData() {
    let data = Object.assign({}, this.form);

    data['title_zh-tw'] = data.title_zh_tw;
    data['content_zh-tw'] = data.content_zh_tw;
    data['html_content_zh-tw'] = data.html_content_zh_tw;

    delete data.title_zh_tw;
    delete data.content_zh_tw;
    delete data.html_content_zh_tw;

    return data;
},

        async previewPage(){try{const {data}=await axios.post(this.route("admin.pages.preview"),{...this.normalizeSubmitData(),id:this.page?.id,mode:"preview"});this.preview=data;this.previewError=""}catch(e){this.previewError=Object.values(e.response?.data?.errors||{}).flat().join(" ")||this.$t("Unable to load preview")}},
        restoreVersion(data){this.form={...this.form,...this.normalizePageData(data)};this.preview=null},
        submit(mode="publish") {
            if (this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            let afterRequest = {
                onStart: () => this.sending = true,
                onFinish: () => this.sending = false,
                onSuccess: () => {
                    this.publishReason='';this.$toast.open(this.$t(mode==='draft'?'Draft saved':'Published'));
                },
                onError: () => {
                    this.$toast.error(legacyText("There are some form errors"));
                }
            };

            const payload = {...this.normalizeSubmitData(),id:this.page?.id,mode,reason:this.publishReason,revision:this.publication?.revision};

            if (this.isEdit) {
                this.$inertia.put(this.route('admin.pages.update', this.page.id), payload, afterRequest);
            } else {
                this.$inertia.post(this.route('admin.pages.store'), payload, afterRequest);
            }
        },

        destroy() {
            if (this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            if (confirm('Are you sure you want to delete this page?')) {
                this.$inertia.delete(this.route('admin.pages.destroy', this.page.id), {
                    onSuccess: () => {
                        this.$toast.open('Page was deleted');
                    },
                    onError: () => {
                        this.$toast.error(legacyText("There are some form errors"));
                    }
                })
            }
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
        },

        handleImageAdded: function(file, Editor, cursorLocation, resetUploader) {
            let formData = new FormData();
            formData.append("file", file);

            axios({
                url: this.route('file-upload'),
                method: "POST",
                data: formData
            })
                .then(result => {
                    let url = result.data.path;
                    Editor.insertEmbed(cursorLocation, "image", url);
                    resetUploader();
                })
                .catch(err => {

                });
        }
    },

    watch: {
        'form.title': function () {
            if (!this.isEdit && this.form.title) {
                this.form.slug = this.slugify(this.form.title)
            }
        },
    }
});
</script>
