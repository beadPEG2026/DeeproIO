<template>
<button v-bind="$attrs" :class="classes">
    <slot></slot>
</button>
</template>

<script>
export default {
    computed: {
        classes() {
            return 'flex justify-between items-center py-2 border-radius-xl px-4 bg-red-500 text-sm rounded text-gray-100 cursor-pointer hover:bg-red-600 hover:text-gray-100 focus:outline-none';
        }
    }
}
</script>
