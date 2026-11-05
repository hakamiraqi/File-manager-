<script setup>
// One on/off setting as a full-width row: text on one side, switch on the other.
import ToggleSwitch from '@/Components/ToggleSwitch.vue';

const model = defineModel({ type: Boolean, default: false });
defineProps({ description: { type: String, default: '' }, disabled: Boolean });
</script>

<template>
    <label class="flex cursor-pointer items-center justify-between gap-4 py-3 first:pt-0 last:pb-0" :class="{ 'cursor-not-allowed opacity-60': disabled }">
        <span class="min-w-0">
            <span class="block text-sm font-medium text-gray-800 dark:text-gray-100"><slot /></span>
            <span v-if="description" class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">{{ description }}</span>
        </span>
        <ToggleSwitch v-model="model" :disabled="disabled" />
    </label>
</template>
