<template>
<div class="flex flex-wrap items-center gap-4">
    <div class="flex items-center flex-1 min-w-0">
        <div class="flex w-full bg-white shadow rounded">
            <dropdown
                v-if="filter"
                :auto-close="true"
                class="px-4 md:px-6 rounded-l border-r hover:bg-gray-100 focus:border-white focus:outline-none focus:ring focus:z-10"
                placement="bottom-start"
            >
                <div class="flex items-baseline">
                    <span class="text-gray-700 hidden md:inline">{{ $t("筛选") }}</span>
                    <svg class="w-2 h-2 fill-gray-700 md:ml-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 961.243 599.998">
                        <path d="M239.998 239.999L0 0h961.243L721.246 240c-131.999 132-240.28 240-240.624 239.999-.345-.001-108.625-108.001-240.624-240z" />
                    </svg>
                </div>

                <div
                    slot="dropdown"
                    class="mt-2 px-4 py-6 w-screen shadow-xl bg-white rounded"
                    :style="{ maxWidth: `${maxWidth}px` }"
                >
                    <slot />
                </div>
            </dropdown>

            <input
                class="relative w-full px-6 py-2 rounded border-none focus:shadow-none focus:ring-0 focus:outline-gray-200 focus:border-gray-200"
                type="text"
                name="search"
                :placeholder="placeholder"
                :value="value"
                @input="$emit('input', $event.target.value)"
            />
        </div>
    </div>

    <div v-if="showDatepicker" class="min-w-[260px]">
        <t-datepicker
            v-model="innerPeriod"
            :range="true"
            :timepicker="false"
            dateFormat="Y-m-d"
            userFormat="Y-m-d"
            :weekStart="1"
        />
    </div>

    <button
        class="text-sm text-gray-500 hover:text-gray-700 focus:text-indigo-500 focus:border-gray-200"
        type="button"
        @click="handleReset"
    >
        {{ $t("重置") }}
    </button>
</div>
</template>

<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import TextInput from "@/Jetstream/TextInput";
import Dropdown from "@/Jetstream/FilterDropdown";

export default {
    components: {
        TextInput,
        Dropdown,
    },
    props: {
        value: String,
        period: {
            type: Array,
            default: () => [],
        },
        maxWidth: {
            type: Number,
            default: 300,
        },
        filter: {
            type: Boolean,
            default: true,
        },
        showDatepicker: {
            type: Boolean,
            default: false,
        },
        placeholder: {
            type: String,
            default: legacyText("搜索..."),
        },
    },
    computed: {
        innerPeriod: {
            get() {
                return this.period
            },
            set(val) {
                this.$emit('update:period', val)
            }
        }
    },
    methods: {
        handleReset() {
            this.$emit('input', '')
            this.$emit('update:period', [])
            this.$emit('reset')
        },
    },
}
</script>