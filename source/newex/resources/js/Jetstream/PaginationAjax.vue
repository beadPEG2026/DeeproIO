<template>
<div class="mt-6 -mb-1 flex flex-wrap pagenav">
    <a class="mr-1 mb-1 px-4 py-3 text-sm border rounded hover:bg-white focus:border-indigo-500 focus:text-indigo-500" @click.prevent="changePage(pagination.current_page - 1)" :disabled="pagination.current_page <= 1">{{ $t('&laquo;')}}</a>
    <a v-for="page in pages" class="mr-1 mb-1 px-4 py-3 text-sm border rounded hover:bg-white focus:border-indigo-500 focus:text-indigo-500" :class="isCurrentPage(page) ? 'bg-white' : ''" @click.prevent="changePage(page)">{{ page }}</a>
    <a class="mr-1 mb-1 px-4 py-3 text-sm border rounded hover:bg-white focus:border-indigo-500 focus:text-indigo-500" @click.prevent="changePage(pagination.current_page + 1)" :disabled="pagination.current_page >= pagination.last_page">{{ $t('&raquo;')}}</a>

</div>
</template>
<style>
.pagination {
    margin-top: 40px;
}
</style>

<script>
export default {
    props: ['pagination', 'offset'],

    methods: {
        isCurrentPage(page) {
            return this.pagination.current_page === page;
        },

        changePage(page) {
            if (page > this.pagination.last_page) {
                page = this.pagination.last_page;
            }

            this.pagination.current_page = page;
            this.$emit('paginate');
        }
    },

    computed: {
        pages() {
            let pages = [];

            let from = this.pagination.current_page - Math.floor(this.offset / 2);

            if (from < 1) {
                from = 1;
            }

            let to = from + this.offset - 1;

            if (to > this.pagination.last_page) {
                to = this.pagination.last_page;
            }

            while (from <= to) {
                pages.push(from);
                from++;
            }

            return pages;
        }
    }
}
</script>
