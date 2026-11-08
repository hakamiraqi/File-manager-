<script setup>
import RichEditor from '@/Components/Exams/RichEditor.vue';
// One text in several languages: {ar: '...', en: '...'}. Each language gets its own box and direction.
const props = defineProps({
    modelValue: { type: Object, default: () => ({}) },
    languages: { type: Array, required: true },
    multiline: { type: Boolean, default: false },
    rows: { type: Number, default: 3 },
    placeholder: { type: String, default: '' },
    rich: { type: Boolean, default: false },   // formatted text (the editor's HTML)
});
const emit = defineEmits(['update:modelValue']);

const RTL = ['ar', 'ku'];
const LABELS = { ar: 'ع', en: 'EN', ku: 'کو' };

function set(lang, value) {
    emit('update:modelValue', { ...(props.modelValue || {}), [lang]: value });
}
</script>

<template>
    <div class="space-y-1.5">
        <div v-for="lang in languages" :key="lang" class="flex items-start gap-2">
            <span v-if="languages.length > 1" class="mt-2 w-8 shrink-0 rounded bg-gray-100 py-0.5 text-center text-[11px] font-semibold text-gray-500 dark:bg-gray-700 dark:text-gray-300">{{ LABELS[lang] ?? lang }}</span>
            <RichEditor v-if="rich" class="w-full" :model-value="modelValue?.[lang] ?? ''" @update:model-value="set(lang, $event)"
                :dir="RTL.includes(lang) ? 'rtl' : 'ltr'" :lang="lang" :placeholder="placeholder" />
            <textarea
                v-else-if="multiline"
                :value="modelValue?.[lang] ?? ''"
                @input="set(lang, $event.target.value)"
                :rows="rows"
                :dir="RTL.includes(lang) ? 'rtl' : 'ltr'"
                :lang="lang"
                :placeholder="placeholder"
                class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
            />
            <input
                v-else
                type="text"
                :value="modelValue?.[lang] ?? ''"
                @input="set(lang, $event.target.value)"
                :dir="RTL.includes(lang) ? 'rtl' : 'ltr'"
                :lang="lang"
                :placeholder="placeholder"
                class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
            />
        </div>
    </div>
</template>
