<script setup>
import { CheckIcon } from '@heroicons/vue/24/outline';
import { ref } from 'vue';

// Labels onto a picture: pick a label, then click where it belongs (or drag it there).
const props = defineProps({ image: String, labels: Array, modelValue: Object, disabled: Boolean, zones: { type: Array, default: () => [] } });
const emit = defineEmits(['update:modelValue']);
const picked = ref(null);
const pic = ref(null);
function place(event, id) {
    if (props.disabled || !id) return;
    const box = pic.value.getBoundingClientRect();
    const at = { x: Math.round(((event.clientX - box.left) / box.width) * 1000) / 10, y: Math.round(((event.clientY - box.top) / box.height) * 1000) / 10 };
    emit('update:modelValue', { ...(props.modelValue || {}), [id]: at });
    picked.value = null;
}
const remove = (id) => { if (props.disabled) return; const next = { ...(props.modelValue || {}) }; delete next[id]; emit('update:modelValue', next); };
const labelOf = (id) => props.labels.find((l) => l.id === Number(id))?.body;
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap gap-2">
            <button v-for="l in labels" :key="l.id" type="button" draggable="true" @dragstart="$event.dataTransfer.setData('text/plain', l.id)" @click="picked = picked === l.id ? null : l.id" :disabled="disabled"
                class="rounded-full border px-3 py-1 text-sm" :class="picked === l.id ? 'border-[var(--accent-600)] bg-[var(--accent-600)] text-white' : modelValue?.[l.id] ? 'border-emerald-400 bg-emerald-50 dark:bg-emerald-900/30' : 'border-gray-300 dark:border-gray-600'" dir="auto">
                {{ l.body }}<CheckIcon v-if="modelValue?.[l.id]" class="ms-1 inline h-3.5 w-3.5 align-text-bottom" />
            </button>
        </div>
        <div ref="pic" class="relative inline-block max-w-full select-none" :class="picked ? 'cursor-crosshair' : ''" @click="place($event, picked)" @dragover.prevent @drop.prevent="place($event, Number($event.dataTransfer.getData('text/plain')))">
            <img :src="image" alt="" class="block max-h-[70vh] max-w-full rounded-lg" draggable="false" />
            <span v-for="(z, k) in zones" :key="'z' + k" class="pointer-events-none absolute border-2 border-emerald-500 bg-emerald-400/20" :style="{ left: z.x + '%', top: z.y + '%', width: z.w + '%', height: z.h + '%' }" />
            <button v-for="(at, id) in modelValue || {}" :key="id" type="button" @click.stop="remove(id)" :title="disabled ? '' : 'إزالة'"
                class="absolute -translate-x-1/2 -translate-y-1/2 whitespace-nowrap rounded bg-[var(--accent-600)] px-1.5 py-0.5 text-xs text-white shadow" :style="{ left: at.x + '%', top: at.y + '%' }">{{ labelOf(id) }}</button>
        </div>
    </div>
</template>
