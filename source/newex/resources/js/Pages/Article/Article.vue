<script>
import { themeContent } from "@/Functions/ThemeContent";
import Template from '{Template}/Web/Pages/Article/Article.template'
import AppLayout from '@/Layouts/AppLayout'
import IconFilter from "@/Components/Table/IconFilter";

export default Template({
    components: {
        AppLayout,
        IconFilter,
    },
    data() {
        return {
            state: false,
            sending: false,
        }
    },
    props: {
        article: Object,
    },
    methods: {
        copyLink() {
            navigator.clipboard.writeText(this.route('article', article)).then(() => {
                this.$toast.success(this.$t('Link copied to clipboard!'));
            }).catch(() => {
                this.$toast.error(this.$t('Failed to copy link'));
            });
        }
    },
    computed:{
        cleanMessage() {
            return themeContent(this.article.body);
        }
    }
})
</script>
