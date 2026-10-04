<template>
<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
    <path :class="{'table-sort__icon-active': filter.filterBy == field && filter.filterDirection == 'asc'}" class="table-sort__icon-color" d="M9.90869 7V16.8644"/>
    <path :class="{'table-sort__icon-active': filter.filterBy == field && filter.filterDirection == 'asc'}" class="table-sort__icon-color" d="M9.90907 17.0001L6.7576 13.6668"/>
    <path :class="{'table-sort__icon-active': filter.filterBy == field && filter.filterDirection == 'desc'}" class="table-sort__icon-color" d="M14 16.6665L14 5.99983"/>
    <line :class="{'table-sort__icon-active': filter.filterBy == field && filter.filterDirection == 'desc'}" class="table-sort__icon-color" x1="0.5" y1="-0.5" x2="5.15685" y2="-0.5" transform="matrix(0.707107 0.707107 0.707107 -0.707107 14 5)"/>
</svg>
</template>

<script>
export default {
    props: {
        filter: {
            default: ''
        },
        field: {
            default: ''
        },
    },
}
</script>
