<script setup>
// On/off switch (RTL aware). v-model is a boolean.
const model = defineModel({ type: Boolean, default: false });
defineProps({ disabled: Boolean, size: { type: String, default: 'md' }, tone: { type: String, default: 'accent' } });
</script>

<template>
    <button
        type="button"
        role="switch"
        :aria-checked="model"
        :disabled="disabled"
        class="relative inline-flex shrink-0 items-center rounded-full transition disabled:cursor-not-allowed disabled:opacity-50"
        :class="[
            size === 'sm' ? 'h-5 w-9' : 'h-6 w-11',
            model ? (tone === 'green' ? 'bg-emerald-500' : 'bg-[var(--accent-600)]') : 'bg-gray-300 dark:bg-gray-600',
        ]"
        @click.stop="model = !model"
    >
        <span
            class="inline-block rounded-full bg-white shadow transition"
            :class="[
                size === 'sm' ? 'h-4 w-4' : 'h-5 w-5',
                model ? (size === 'sm' ? 'translate-x-[18px] rtl:-translate-x-[18px]' : 'translate-x-[22px] rtl:-translate-x-[22px]') : 'translate-x-0.5 rtl:-translate-x-0.5',
            ]"
        />
    </button>
</template>
