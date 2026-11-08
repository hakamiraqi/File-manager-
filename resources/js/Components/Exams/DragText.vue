<script setup>
import { ref, computed } from 'vue';

// Words into blanks: pick a word (click, or drag it) then a blank; click a filled blank to empty it.
const props = defineProps({ parts: Array, words: Array, modelValue: Object, disabled: Boolean });
const emit = defineEmits(['update:modelValue']);
const picked = ref(null);
const used = computed(() => Object.values(props.modelValue || {}));
function put(blank, word) {
    if (props.disabled) return;
    const next = { ...(props.modelValue || {}) };
    for (const k of Object.keys(next)) if (next[k] === word) delete next[k];   // a word goes in one blank
    if (word) next[blank] = word; else delete next[blank];
    emit('update:modelValue', next);
    picked.value = null;
}
const clickBlank = (b) => (picked.value ? put(b, picked.value) : props.modelValue?.[b] && put(b, null));
const drop = (b, e) => put(b, e.dataTransfer.getData('text/plain'));
</script>

<template>
    <div class="space-y-4">
        <p class="text-base leading-loose" dir="auto">
            <template v-for="(part, k) in parts" :key="k">
                <span v-if="part.blank === undefined" class="whitespace-pre-line">{{ part.text }}</span>
                <button v-else type="button" @click="clickBlank(part.blank)" @dragover.prevent @drop.prevent="drop(part.blank, $event)"
                    class="mx-1 inline-flex min-w-[6rem] justify-center rounded-md border-2 border-dashed px-2 py-0.5 align-middle text-sm"
                    :class="modelValue?.[part.blank] ? 'border-[var(--accent-500)] bg-[var(--accent-50)] font-medium dark:bg-gray-700' : picked ? 'border-[var(--accent-500)]' : 'border-gray-300 text-gray-400 dark:border-gray-600'">
                    {{ modelValue?.[part.blank] || '…' }}
                </button>
            </template>
        </p>
        <div class="flex flex-wrap gap-2 rounded-lg bg-gray-50 p-3 dark:bg-gray-700/50">
            <button v-for="w in words" :key="w" type="button" draggable="true" @dragstart="$event.dataTransfer.setData('text/plain', w)" @click="picked = picked === w ? null : w"
                :disabled="disabled || used.includes(w)" class="rounded-full border px-3 py-1 text-sm disabled:opacity-30"
                :class="picked === w ? 'border-[var(--accent-600)] bg-[var(--accent-600)] text-white' : 'border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-800'">{{ w }}</button>
        </div>
    </div>
</template>
