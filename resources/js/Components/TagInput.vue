<script setup>
// Chips you add with Enter / comma / space and remove with ×. The value stays a
// comma-separated string ("exe, bat, sh") so the server side is unchanged.
import { computed, ref } from 'vue';
import { XMarkIcon } from '@heroicons/vue/20/solid';

const props = defineProps({
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    // Applied to every new tag, e.g. strip a leading dot and lower-case.
    normalize: { type: Function, default: (v) => v.trim() },
    suggestions: { type: Array, default: () => [] },
});
const emit = defineEmits(['update:modelValue']);

const draft = ref('');
const input = ref(null);
const tags = computed(() => (props.modelValue || '').split(/[\s,،]+/).map((v) => v.trim()).filter(Boolean));
const unused = computed(() => props.suggestions.filter((s) => !tags.value.includes(s)));

function save(list) {
    emit('update:modelValue', [...new Set(list)].join(', '));
}
function add(raw = draft.value) {
    const parts = raw.split(/[\s,،]+/).map((v) => props.normalize(v)).filter(Boolean);
    if (parts.length) save([...tags.value, ...parts]);
    draft.value = '';
}
function remove(i) {
    save(tags.value.filter((_, j) => j !== i));
}
function onKeydown(e) {
    if (['Enter', ',', '،', ' ', 'Tab'].includes(e.key)) {
        if (draft.value.trim()) {
            e.preventDefault();
            add();
        } else if (e.key === 'Enter') {
            e.preventDefault();
        }
    } else if (e.key === 'Backspace' && !draft.value && tags.value.length) {
        remove(tags.value.length - 1);
    }
}
</script>

<template>
    <div>
        <div
            class="flex min-h-[2.75rem] flex-wrap items-center gap-1.5 rounded-md border border-gray-300 bg-white px-2 py-1.5 shadow-sm focus-within:border-[var(--accent-500)] focus-within:ring-1 focus-within:ring-[var(--accent-500)] dark:border-gray-600 dark:bg-gray-700"
            @click="input?.focus()"
        >
            <span
                v-for="(tag, i) in tags"
                :key="tag"
                class="inline-flex items-center gap-1 rounded-full bg-[var(--accent-100)] py-0.5 pe-1 ps-2.5 text-sm font-medium text-[var(--accent-700)] dark:bg-gray-600 dark:text-gray-100"
                dir="ltr"
            >
                {{ tag }}
                <button type="button" class="rounded-full p-0.5 hover:bg-[var(--accent-200)] dark:hover:bg-gray-500" :aria-label="`× ${tag}`" @click.stop="remove(i)">
                    <XMarkIcon class="h-3.5 w-3.5" />
                </button>
            </span>
            <input
                ref="input"
                v-model="draft"
                type="text"
                dir="ltr"
                :placeholder="tags.length ? '' : placeholder"
                class="min-w-[6rem] flex-1 border-0 bg-transparent p-1 text-sm focus:ring-0 dark:text-gray-100"
                @keydown="onKeydown"
                @blur="add()"
                @paste.prevent="add($event.clipboardData.getData('text'))"
            />
        </div>
        <div v-if="unused.length" class="mt-2 flex flex-wrap gap-1.5">
            <button
                v-for="s in unused"
                :key="s"
                type="button"
                dir="ltr"
                class="rounded-full border border-dashed border-gray-300 px-2.5 py-0.5 text-xs text-gray-500 hover:border-[var(--accent-500)] hover:text-[var(--accent-700)] dark:border-gray-600 dark:text-gray-400"
                @click="add(s)"
            >
                + {{ s }}
            </button>
        </div>
    </div>
</template>
