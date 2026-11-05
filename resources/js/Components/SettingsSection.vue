<script setup>
// Card for one group of settings: icon, title, short help line, then the fields.
defineProps({
    icon: { type: [Object, Function], default: null },
    title: { type: String, required: true },
    description: { type: String, default: '' },
    tone: { type: String, default: 'accent' },
});
const TONES = {
    accent: 'bg-indigo-50 text-[var(--accent-600)] dark:bg-indigo-900/30',
    green: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400',
    amber: 'bg-amber-50 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400',
    red: 'bg-red-50 text-red-600 dark:bg-red-900/30 dark:text-red-400',
    sky: 'bg-sky-50 text-sky-600 dark:bg-sky-900/30 dark:text-sky-400',
};
</script>

<template>
    <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <header class="flex items-start gap-3 border-b border-gray-100 px-4 py-4 sm:px-6 dark:border-gray-700">
            <span v-if="icon" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl" :class="TONES[tone] ?? TONES.accent">
                <component :is="icon" class="h-5 w-5" />
            </span>
            <div class="min-w-0 flex-1">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ title }}</h3>
                <p v-if="description" class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ description }}</p>
            </div>
            <slot name="aside" />
        </header>
        <div class="px-4 py-4 sm:px-6">
            <slot />
        </div>
    </section>
</template>
