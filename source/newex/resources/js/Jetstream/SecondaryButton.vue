<template>
<button :type="type" class="modal-secondary-button inline-flex items-center px-4 py-1 text-sm bg-white border border-transparent rounded-md font-semibold text-gray-700 capitalize tracking-widest shadow-sm hover:text-gray-500 focus:outline-none focus:border-blue-300 focus:shadow-outline-blue active:text-gray-800 active:bg-gray-50 transition ease-in-out duration-150">
    <slot></slot>
</button>
</template>

<script>
    export default {
        props: {
            type: {
                type: String,
                default: 'button',
            },
        }
    }

</script>
