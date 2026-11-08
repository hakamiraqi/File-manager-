<script setup>
import { MinusIcon, PlusIcon } from '@heroicons/vue/24/outline';

const model = defineModel({ type: Number, default: 0 });
const props = defineProps({ min: { type: Number, default: 0 }, max: { type: Number, default: 100000 }, step: { type: Number, default: 1 }, unit: { type: String, default: '' } });

function change(delta) {
    const next = Number(model.value || 0) + delta;
    model.value = Math.min(props.max, Math.max(props.min, next));
}
</script>

<template>
    <div class="inline-flex items-stretch overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-600 dark:bg-gray-700">
        <button type="button" @click="change(-step)" :disabled="model <= min" class="flex w-10 items-center justify-center text-gray-500 hover:bg-gray-50 disabled:opacity-30 dark:text-gray-300 dark:hover:bg-gray-600" :aria-label="'-' + step">
            <MinusIcon class="h-4 w-4" />
        </button>
        <label class="flex items-center gap-1 border-x border-gray-200 px-2 dark:border-gray-600">
            <input v-model.number="model" type="number" :min="min" :max="max" inputmode="numeric" class="w-16 border-0 bg-transparent p-0 py-2 text-center text-base font-semibold text-gray-900 focus:ring-0 dark:text-white [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none" />
            <span v-if="unit" class="whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">{{ unit }}</span>
        </label>
        <button type="button" @click="change(step)" :disabled="model >= max" class="flex w-10 items-center justify-center text-gray-500 hover:bg-gray-50 disabled:opacity-30 dark:text-gray-300 dark:hover:bg-gray-600" :aria-label="'+' + step">
            <PlusIcon class="h-4 w-4" />
        </button>
    </div>
</template>
