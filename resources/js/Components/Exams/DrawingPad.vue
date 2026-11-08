<script setup>
import { ref, onMounted } from 'vue';

// Drawing or handwriting with the mouse, a pen or a finger; sent as a picture.
const props = defineProps({ saved: String, disabled: Boolean });
const emit = defineEmits(['drawn']);
const canvas = ref(null);
const color = ref('#111827');
const width = ref(3);
const dirty = ref(false);
let ctx, drawing = false;

onMounted(() => {
    const c = canvas.value;
    c.width = c.clientWidth * 2;
    c.height = c.clientHeight * 2;
    ctx = c.getContext('2d');
    ctx.scale(2, 2);
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, c.width, c.height);
    ctx.lineCap = ctx.lineJoin = 'round';
});
const pos = (e) => { const r = canvas.value.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; };
function down(e) {
    if (props.disabled) return;
    drawing = true;
    canvas.value.setPointerCapture(e.pointerId);
    ctx.beginPath();
    ctx.moveTo(...pos(e));
}
function move(e) {
    if (!drawing) return;
    ctx.strokeStyle = color.value;
    ctx.lineWidth = color.value === '#ffffff' ? 18 : width.value;
    ctx.lineTo(...pos(e));
    ctx.stroke();
    dirty.value = true;
}
function up() { drawing = false; }
function clear() {
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, canvas.value.width, canvas.value.height);
    dirty.value = true;
}
function send() {
    canvas.value.toBlob((blob) => { emit('drawn', new File([blob], 'drawing.png', { type: 'image/png' })); dirty.value = false; }, 'image/png');
}
</script>

<template>
    <div class="space-y-2">
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <button v-for="c in ['#111827', '#dc2626', '#2563eb', '#16a34a']" :key="c" type="button" @click="color = c" class="h-6 w-6 rounded-full border-2" :class="color === c ? 'border-gray-900 dark:border-white' : 'border-transparent'" :style="{ background: c }" />
            <button type="button" @click="color = '#ffffff'" class="rounded border px-2 py-0.5" :class="color === '#ffffff' ? 'border-gray-900 dark:border-white' : 'border-gray-300 dark:border-gray-600'">ممحاة</button>
            <input v-model.number="width" type="range" min="1" max="10" class="w-24" />
            <button type="button" @click="clear" :disabled="disabled" class="rounded border border-gray-300 px-2 py-0.5 dark:border-gray-600">مسح الكل</button>
            <span class="flex-1" />
            <span v-if="saved && !dirty" class="text-xs text-emerald-700 dark:text-emerald-400">✓ محفوظ</span>
            <button type="button" @click="send" :disabled="disabled || !dirty" class="rounded-lg bg-[var(--accent-600)] px-3 py-1 text-white disabled:opacity-40">حفظ الرسم</button>
        </div>
        <canvas ref="canvas" @pointerdown="down" @pointermove="move" @pointerup="up" @pointerleave="up"
            class="h-80 w-full touch-none rounded-lg border border-gray-300 bg-white dark:border-gray-600" />
    </div>
</template>
