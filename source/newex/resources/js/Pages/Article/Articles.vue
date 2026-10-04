<script>
import Template from '{Template}/Web/Pages/Article/Articles.template'
import AppLayout from '@/Layouts/AppLayout'
import IconFilter from "@/Components/Table/IconFilter";
import Pagination from '@/Jetstream/Pagination'

export default Template({
    components: {
        AppLayout,
        IconFilter,
        Pagination
    },
    data() {
        return {
            filter: {
                sortBy: null,
            },
        }
    },
    props: {
        articles: Object,
        sort: String
    },
    mounted() {

    },
    methods: {
        setArticle(article) {
            this.$inertia.visit(this.route('article', article.id));
        },
        setSort(sort) {
            this.$inertia.visit(this.route('articles', {'sort': sort}));
        }
    }
})
</script>
